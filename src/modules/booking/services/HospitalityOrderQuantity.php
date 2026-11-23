<?php

namespace App\modules\booking\services;

/**
 * What the quantity of a catering order line may be: a POSITIVE WHOLE NUMBER, for every unit
 * (operator ruling, request 206, 2026-11-23, task 47994).
 *
 * Why: a fractional quantity makes billing and the Endringslogg disagree by an øre. Billing taxes the
 * unrounded product quantity x unit price, the log taxes the stored amount, which is already rounded
 * to two decimals (HospitalityOrderDiff::lineInclOre); the two agree whenever the product is exact in
 * øre and differ by +1 øre for some products with a third decimal. No screen offers a fraction (both
 * the applicant's page and the admin order page send parseInt), so only a hand-made request can.
 *
 * Pure: plain values in, an int or null out. The caller refuses on null, BEFORE it writes anything,
 * and uses the returned int from then on, so the value that was judged is the value that is stored
 * (the same idiom as HospitalityDirectBookingGuard::normaliseResourceId).
 *
 * Numbers are judged by their VALUE, strings by their SPELLING:
 *   - an int, or a float with no fraction (JSON `2.0` and `1e1` arrive as floats), is taken as it is;
 *   - a string is taken only when it is plain decimal digits, optionally followed by `.` and zeros
 *     (`"2"`, `"2.0"`, `"007"`): not `"1e1"`, `" 2"`, `"2abc"`, `"0x2"`, `"2.5"`;
 *   - anything else (bool, array, null, a non-finite float) is not a quantity.
 * The result must lie in 1..MAX. MAX is the largest whole number bb_hospitality_order_line.quantity
 * (numeric(10,2)) can hold: above it PostgreSQL answers «numeric field overflow», after the order row
 * has already been written.
 */
final class HospitalityOrderQuantity
{
    public const MAX = 99999999;

    /**
     * @param mixed $raw the quantity as the request body carries it
     * @return int|null the quantity as a whole number, or null when it is not a positive whole number
     */
    public static function whole(mixed $raw): ?int
    {
        if (is_int($raw)) {
            $quantity = $raw;
        } elseif (is_float($raw)) {
            // INF is above MAX and NaN is not equal to its own floor, so neither needs a test of its own.
            if ($raw != floor($raw) || $raw > self::MAX) {
                return null;
            }
            $quantity = (int)$raw;
        } elseif (is_string($raw) && preg_match('/^[0-9]+(?:\.0+)?$/D', $raw) === 1) {
            // (int) of a digit string too long for an int saturates at PHP_INT_MAX, which the range test below refuses.
            $quantity = (int)$raw;
        } else {
            return null;
        }

        return $quantity >= 1 && $quantity <= self::MAX ? $quantity : null;
    }

    /**
     * The quantity of one request line: an absent or null `quantity` means 1 (as it always did), anything
     * else must be a positive whole number.
     *
     * @param mixed $line one element of the request's `lines`
     * @return int|null null = the line carries a quantity that is not a positive whole number
     */
    public static function ofLine(mixed $line): ?int
    {
        return isset($line['quantity']) ? self::whole($line['quantity']) : 1;
    }
}
