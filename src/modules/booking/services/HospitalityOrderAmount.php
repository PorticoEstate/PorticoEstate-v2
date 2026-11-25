<?php

namespace App\modules\booking\services;

/**
 * How large a catering order may be: what the money columns can hold, judged BEFORE anything is written (§49596).
 *
 * Every money column a catering order passes through is numeric(10,2): the line's unit_price and amount
 * (bb_hospitality_order_line) and, when the billing job turns the order into a purchase order, each line's amount and
 * tax (bb_purchase_order_line) and the order's GROSS total (bb_payment.amount, the sum of amount + tax over its lines).
 * The largest value any of them holds is 99,999,999.99; above it PostgreSQL answers «numeric field overflow» (22003).
 * The applicant's endpoints used to meet that error half-way through their writes (an order left with no lines, or
 * with its old lines deleted); the admin's answered with the raw SQLSTATE.
 *
 * Two rules, both counted in whole øre (ints), never in floats: PHP's 2463661 * 40.59 is 99999999.99000001, which a
 * float compare would refuse although the column stores it as 99,999,999.99.
 *   - LINE: for every line being written, the unit price and the NET amount (quantity × unit price, what the line's
 *     `amount` stores) are at most 99,999,999.99 either way (numeric(10,2) is symmetric).
 *   - ORDER: the order's GROSS total once the write is done, the sum of net + VAT over ALL its lines, is at most
 *     99,999,999.99 either way, OR it is no further from zero than the order's gross total as stored now. The second
 *     clause lets a change that LOWERS an order already over the bound through: the admin page saves one line per
 *     request, so without it such an order could never be brought down.
 *
 * Net and VAT are the billing job's own (async_task_update_reservation_state::create_purchase_orders_from_hospitality):
 * the net is quantity × unit price (billing recomputes it; it does not read the stored `amount`), the VAT is the whole
 * percent of the line's OWN tax_code in fm_ecomva, 0 for a code that is not there, taken of the unrounded net. Each is
 * rounded to øre as numeric(10,2) rounds a value it is given: half away from zero. The rate is never a constant.
 *
 * Pure: plain values in, plain values out. The callers read the lines and the VAT table; this class reads nothing.
 */
final class HospitalityOrderAmount
{
    /** 99,999,999.99 in øre: the largest value numeric(10,2) holds. */
    public const MAX_CENTS = 9999999999;

    /** a line's unit price or net amount does not fit */
    public const CODE_LINE = 'amount_too_large';
    /** the order's gross total does not fit, and the change does not lower it */
    public const CODE_TOTAL = 'order_total_too_large';

    /**
     * Any magnitude beyond this many øre is "too large" whatever its exact value. Every amount is capped at it, so a
     * product or a sum never leaves int64 (PHP would silently turn it into a float): 90,000 capped lines still fit.
     */
    private const CAP = 100000000000000;

    /**
     * A money value or a quantity in hundredths, exactly as numeric(10,2) stores it: rounded half away from zero at the
     * third decimal, the magnitude capped at CAP.
     *
     * A string is read digit by digit (`"40.59"`, `" 1e8 "`), so nothing is lost on the way. An int is read as its
     * digits. A float is read as the string PHP turns it into, which is the string PDO binds: what the database is
     * given is what is judged.
     *
     * @return int|null null when the value is not a finite number (NaN, an array, a word): the database's to refuse
     */
    public static function hundredths(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string)$value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }
            $value = (string)$value;
        } elseif (!is_string($value)) {
            return null;
        }

        if (preg_match('/^\s*([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?\s*$/D', $value, $m) !== 1) {
            return null;
        }
        $sign = $m[1] === '-' ? -1 : 1;
        $whole = $m[2] ?? '';
        $digits = $whole . ($m[3] ?? '') . ($m[4] ?? '');
        $exponent = isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 0;

        // The value is 0.<digits> × 10^$point once the leading zeros are gone.
        $point = strlen($whole) + $exponent;
        $significant = ltrim($digits, '0');
        $point -= strlen($digits) - strlen($significant);
        if ($significant === '') {
            return 0;
        }

        // The value × 100 has $kept digits before its decimal point; the next one decides the rounding.
        $kept = $point + 2;
        if ($kept > 16) {
            return $sign * self::CAP;
        }
        if ($kept < 0) {
            return 0;
        }
        $head = $kept === 0 ? 0 : (int)str_pad(substr($significant, 0, $kept), $kept, '0');
        $next = $significant[$kept] ?? '0';
        $hundredths = $head + ($next >= '5' ? 1 : 0);

        return $sign * min($hundredths, self::CAP);
    }

    /**
     * A line's net amount and VAT in øre, as the billing job will write them.
     *
     * @param array<int,int> $taxPercents fm_ecomva id => whole percent (HospitalityArticleRepository::getTaxPercentByCode)
     * @return array{net: int, tax: int}|null null when the quantity or the unit price is not a number
     */
    public static function line(mixed $quantity, mixed $unitPrice, mixed $taxCode, array $taxPercents): ?array
    {
        $q = self::hundredths($quantity);
        $p = self::hundredths($unitPrice);
        if ($q === null || $p === null) {
            return null;
        }
        // Billing: $tax_codes[(int)$line['tax_code']] ?? 0. A NULL code is (int) 0, which is fm_ecomva's 0 % row.
        $percent = (int)($taxPercents[(int)$taxCode] ?? 0);

        // quantity × unit price in 1/10000 kr (hundredths × hundredths), then rounded to øre once, as billing rounds it.
        $raw = self::multiply($q, $p);
        if ($raw === null) {
            $sign = ($q < 0) !== ($p < 0) ? -1 : 1;
            return ['net' => $sign * self::CAP, 'tax' => $percent === 0 ? 0 : ($percent < 0 ? -1 : 1) * $sign * self::CAP];
        }

        return [
            'net' => self::capped(self::divideRounded($raw, 100)),
            'tax' => self::capped(self::scaleRounded($raw, $percent, 10000)),
        ];
    }

    /**
     * The order's gross total in øre: the sum of net + VAT over its lines. A line that is not a number counts 0.
     *
     * @param iterable<array{quantity?: mixed, unit_price?: mixed, tax_code?: mixed}> $lines
     * @param array<int,int> $taxPercents
     */
    public static function gross(iterable $lines, array $taxPercents): int
    {
        $sum = 0;
        foreach ($lines as $line) {
            $amount = self::line($line['quantity'] ?? null, $line['unit_price'] ?? null, $line['tax_code'] ?? null, $taxPercents);
            if ($amount !== null) {
                $sum += $amount['net'] + $amount['tax'];
            }
        }
        return $sum;
    }

    /**
     * The verdict on a write. Every array holds lines as ['quantity' => …, 'unit_price' => …, 'tax_code' => …].
     *
     * @param array<int|string, array> $written the lines this request writes, keyed as the request keys them
     * @param iterable<array>          $after   every line of the order once the write is done, $written among them
     * @param iterable<array>          $before  every line of the order as stored now ([] for a new order)
     * @param array<int,int>           $taxPercents fm_ecomva id => whole percent
     * @return array{code: string, line: int|string|null}|null null when it fits; else the code, and for a line the key
     *         of the FIRST line that does not fit
     */
    public static function refusal(array $written, iterable $after, iterable $before, array $taxPercents): ?array
    {
        foreach ($written as $key => $line) {
            $price = self::hundredths($line['unit_price'] ?? null);
            $amount = self::line($line['quantity'] ?? null, $line['unit_price'] ?? null, $line['tax_code'] ?? null, $taxPercents);
            // A unit price or a quantity that is not a number is not an amount: the database refuses it as it always did.
            if ($price === null || $amount === null) {
                continue;
            }
            if (abs($price) > self::MAX_CENTS || abs($amount['net']) > self::MAX_CENTS) {
                return ['code' => self::CODE_LINE, 'line' => $key];
            }
        }

        $grossAfter = abs(self::gross($after, $taxPercents));
        if ($grossAfter <= self::MAX_CENTS || $grossAfter <= abs(self::gross($before, $taxPercents))) {
            return null;
        }
        return ['code' => self::CODE_TOTAL, 'line' => null];
    }

    /** $a × $b, or null when the product leaves int64. */
    private static function multiply(int $a, int $b): ?int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        if ($a === PHP_INT_MIN || $b === PHP_INT_MIN || abs($a) > intdiv(PHP_INT_MAX, abs($b))) {
            return null;
        }
        return $a * $b;
    }

    /** $n / $divisor rounded half away from zero ($divisor > 0). */
    private static function divideRounded(int $n, int $divisor): int
    {
        $magnitude = intdiv(abs($n), $divisor);
        if ((abs($n) % $divisor) * 2 >= $divisor) {
            $magnitude++;
        }
        return $n < 0 ? -$magnitude : $magnitude;
    }

    /** $n × $factor / $divisor rounded half away from zero, saturating at ±CAP when the product leaves int64. */
    private static function scaleRounded(int $n, int $factor, int $divisor): int
    {
        $product = self::multiply($n, $factor);
        if ($product === null) {
            return (($n < 0) !== ($factor < 0) ? -1 : 1) * self::CAP;
        }
        return self::divideRounded($product, $divisor);
    }

    private static function capped(int $n): int
    {
        return max(-self::CAP, min(self::CAP, $n));
    }
}
