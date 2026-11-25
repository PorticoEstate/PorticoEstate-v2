<?php

namespace App\modules\booking\services;

/**
 * What changed between two snapshots of a catering order (GH #1425).
 *
 * The same result feeds two consumers, so they can never disagree about what the applicant did:
 *   - the case entry (bb_hospitality_order_changelog.old_value / new_value, JSONB), and
 *   - the mail to the case officer.
 * Both are put into words by CateringChangeText, from these values.
 *
 * Pure: snapshots in, plain arrays out. A snapshot is the shape of
 * HospitalityOrderRepository::getOrderWithLines() — an order row plus `lines` (each with
 * article_name, unit, quantity, unit_price, amount, tax_percent, comment) and `total_amount`.
 *
 * Every text value is CateringText::decode()d to plain text here and nowhere later: the mail
 * body escapes it once at the HTML boundary, and the admin changelog renderer escapes it once
 * with esc().
 *
 * Values are language-neutral strings ("3 stk × 45.00 = 135.00 [VAT 25% incl 168.75]",
 * "27.10.2026 14:00"). Amounts are EX VAT, as stored. A line whose tax code has a known rate
 * carries the rate and the incl. amount it gave when the entry was written, and the total carries
 * " [incl x]" when every line's rate is known, so an entry keeps showing the incl. amount of its
 * moment even after a line has been removed or re-taxed (GH #1425 D2: per line, from the stored
 * tax, never an assumed 25 %). The order line stores no tax amount of its own (only tax_code), so
 * the incl. amount is ex + VAT at the code's rate, rounded once to the øre. Entries written before
 * this carry neither and are read as they are (parseLine / parseTotal accept every form).
 */
final class HospitalityOrderDiff
{
    private const VENUE_TIMEZONE = 'Europe/Oslo';

    /**
     * @param array|null $before the order before the change; null when the order was just added
     * @param array      $after  the order after the change (for a cancel: the cancelled order)
     * @return array{
     *   rows: list<array{key: string, label: ?string, from: string, to: string}>,
     *   old: array<string, string>,
     *   new: array<string, string>
     * } `rows` drives the mail table; `old`/`new` are the flat maps stored in the changelog and
     *   contain ONLY the keys that changed. All three are empty when nothing changed.
     */
    public static function between(?array $before, array $after): array
    {
        $b = $before === null ? [] : self::flatten($before);
        $a = self::flatten($after);

        $rows = [];
        $old = [];
        $new = [];

        foreach (self::orderedKeys($b, $a) as $key) {
            // A new order is always 'pending'; that is not something the applicant changed.
            if ($before === null && $key === 'status') {
                continue;
            }
            $from = $b[$key]['value'] ?? '';
            $to = $a[$key]['value'] ?? '';
            if ($from === $to) {
                continue;
            }
            $label = $a[$key]['label'] ?? $b[$key]['label'] ?? null;
            $rows[] = ['key' => $key, 'label' => $label, 'from' => $from, 'to' => $to];
            if ($from !== '') {
                $old[$key] = $from;
            }
            if ($to !== '') {
                $new[$key] = $to;
            }
        }

        return ['rows' => $rows, 'old' => $old, 'new' => $new];
    }

    public static function isEmpty(array $diff): bool
    {
        return $diff['rows'] === [];
    }

    /**
     * One order as label/value rows (non-empty values only) — what a CANCELLED order contained,
     * for the mail, since a cancel's diff alone is just "status: pending → cancelled".
     *
     * @return list<array{key: string, label: ?string, value: string}>
     */
    public static function summary(array $order): array
    {
        $rows = [];
        foreach (self::flatten($order) as $key => $entry) {
            if ($key === 'status' || $entry['value'] === '') {
                continue;
            }
            $rows[] = ['key' => $key, 'label' => $entry['label'], 'value' => $entry['value']];
        }
        return $rows;
    }

    /**
     * @return array<string, array{value: string, label: ?string}>
     */
    private static function flatten(array $order): array
    {
        $out = [];

        $out['hospitality'] = ['value' => CateringText::decode($order['hospitality_name'] ?? ''), 'label' => null];
        $out['status'] = ['value' => (string)($order['status'] ?? ''), 'label' => null];
        $out['serving_time'] = ['value' => self::formatServingTime($order['serving_time_iso'] ?? null), 'label' => null];
        $out['location'] = ['value' => CateringText::decode($order['location_name'] ?? ''), 'label' => null];
        $out['comment'] = ['value' => CateringText::decode($order['comment'] ?? ''), 'label' => null];
        $out['special_requirements'] = ['value' => CateringText::decode($order['special_requirements'] ?? ''), 'label' => null];

        $seen = [];
        foreach (($order['lines'] ?? []) as $line) {
            $name = CateringText::decode($line['article_name'] ?? '');
            $n = ($seen[$name] = ($seen[$name] ?? 0) + 1);
            $label = $n > 1 ? "{$name} #{$n}" : $name;
            $out['line:' . $label] = ['value' => self::describeLine($line), 'label' => $label];
        }

        $out['total'] = ['value' => self::describeTotal($order), 'label' => null];

        return $out;
    }

    /**
     * The ex-VAT total, plus " [incl x]" when every line's VAT rate is known. The incl. amount is the
     * sum of the lines' incl. amounts, each rounded once to the øre (lineInclOre), as the client and
     * the billing job add them up.
     */
    private static function describeTotal(array $order): string
    {
        $text = self::money($order['total_amount'] ?? 0);
        $inclOre = 0;
        foreach (($order['lines'] ?? []) as $line) {
            $vat = self::vatPercent($line['tax_percent'] ?? null);
            if ($vat === null) {
                return $text;
            }
            $inclOre += self::lineInclOre(self::lineAmount($line), (float)$vat);
        }
        return $text . ' [incl ' . self::money($inclOre / 100) . ']';
    }

    /**
     * A stored line value back into its parts — the inverse of describeLine(). Null when the value
     * is not in that shape (it is then shown as it is).
     *
     * @return array{quantity: string, unit: string, unit_price: string, amount: string, vat: ?string, incl: ?string, comment: string}|null
     *         `amount` is ex VAT; `vat` the rate and `incl` the incl. amount stored with it (null
     *         in an entry written before they were stored)
     */
    public static function parseLine(string $value): ?array
    {
        $ok = preg_match(
            '/^(?<qty>-?\d+(?:\.\d+)?)(?: (?<unit>.+?))? × (?<price>-?\d+\.\d{2}) = (?<amount>-?\d+\.\d{2})'
            . '(?: \[VAT (?<vat>\d+(?:\.\d+)?)%(?: incl (?<incl>-?\d+\.\d{2}))?\])?(?: — (?<comment>.*))?$/su',
            $value,
            $m
        );
        if ($ok !== 1) {
            return null;
        }
        return [
            'quantity' => $m['qty'],
            'unit' => (string)($m['unit'] ?? ''),
            'unit_price' => $m['price'],
            'amount' => $m['amount'],
            'vat' => isset($m['vat']) && $m['vat'] !== '' ? $m['vat'] : null,
            'incl' => isset($m['incl']) && $m['incl'] !== '' ? $m['incl'] : null,
            'comment' => (string)($m['comment'] ?? ''),
        ];
    }

    /**
     * A stored total back into its parts. Null when it is not in the describeTotal() shape.
     *
     * @return array{amount: string, incl: ?string}|null
     */
    public static function parseTotal(string $value): ?array
    {
        if (preg_match('/^(?<ex>-?\d+\.\d{2})(?: \[incl (?<incl>-?\d+\.\d{2})\])?$/', $value, $m) !== 1) {
            return null;
        }
        return ['amount' => $m['ex'], 'incl' => isset($m['incl']) && $m['incl'] !== '' ? $m['incl'] : null];
    }

    /**
     * An ex-VAT amount in kroner plus its VAT at $percent, in whole øre: the VAT rounded ONCE to the
     * øre. The client's inclOre() (article-pricing.ts) and the billing job's tax use the same rule.
     */
    public static function lineInclOre(float $exKroner, float $percent): int
    {
        $exOre = (int)round($exKroner * 100);
        return $exOre + (int)round($exOre * $percent / 100);
    }

    /**
     * Fixed keys first in a stable order, then the article lines in the order they first appear.
     *
     * @param array<string, mixed> $b
     * @param array<string, mixed> $a
     * @return list<string>
     */
    private static function orderedKeys(array $b, array $a): array
    {
        $fixed = ['hospitality', 'status', 'serving_time', 'location', 'comment', 'special_requirements'];
        $keys = $fixed;
        foreach ([$b, $a] as $side) {
            foreach (array_keys($side) as $k) {
                if (str_starts_with($k, 'line:') && !in_array($k, $keys, true)) {
                    $keys[] = $k;
                }
            }
        }
        $keys[] = 'total';
        return $keys;
    }

    private static function describeLine(array $line): string
    {
        $qty = self::trimNumber((string)($line['quantity'] ?? '0'));
        $unit = CateringText::decode($line['unit'] ?? '');
        $price = self::money($line['unit_price'] ?? 0);
        $amount = self::money(self::lineAmount($line));

        $text = trim("{$qty} {$unit}") . " × {$price} = {$amount}";

        // The line's own rate (its tax_code's fm_ecomva row) and the incl. amount it gives NOW, stored
        // so the entry keeps showing what was shown at the time. NULL means "no row", which is NOT
        // 0 %: then nothing is added and the line can only be shown ex VAT.
        $vat = self::vatPercent($line['tax_percent'] ?? null);
        if ($vat !== null) {
            $incl = self::money(self::lineInclOre(self::lineAmount($line), (float)$vat) / 100);
            $text .= " [VAT {$vat}% incl {$incl}]";
        }

        $comment = CateringText::decode($line['comment'] ?? '');
        if ($comment !== '') {
            $text .= ' — ' . $comment;
        }
        return $text;
    }

    /** The stored ex-VAT amount of a line, else quantity × unit price. */
    private static function lineAmount(array $line): float
    {
        return (float)($line['amount'] ?? ((float)($line['quantity'] ?? 0) * (float)($line['unit_price'] ?? 0)));
    }

    /** "25.00" → "25", "15" → "15"; null for NULL / '' / anything not a number. */
    private static function vatPercent(mixed $percent): ?string
    {
        if ($percent === null || $percent === '' || !is_numeric($percent)) {
            return null;
        }
        return self::trimNumber((string)$percent);
    }

    /** "3.00" → "3", "2.50" → "2.5", so a quantity reads the way the applicant typed it. */
    private static function trimNumber(string $n): string
    {
        if (!str_contains($n, '.')) {
            return $n;
        }
        return rtrim(rtrim($n, '0'), '.');
    }

    private static function money(mixed $amount): string
    {
        return number_format((float)$amount, 2, '.', '');
    }

    /**
     * serving_time_iso is held as naive UTC; officers read venue wall-clock.
     */
    private static function formatServingTime(?string $naiveUtc): string
    {
        if ($naiveUtc === null || $naiveUtc === '') {
            return '';
        }
        try {
            $instant = new \DateTimeImmutable($naiveUtc, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return $naiveUtc;
        }
        return $instant->setTimezone(new \DateTimeZone(self::VENUE_TIMEZONE))->format('d.m.Y H:i');
    }
}
