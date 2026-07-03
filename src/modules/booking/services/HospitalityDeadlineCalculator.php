<?php

namespace App\modules\booking\services;

/**
 * Computes the order deadline (cutoff) for a hospitality/catering order, honouring the
 * per-hospitality open_days ("working days") configuration (#373).
 *
 * This is the SHARED REFERENCE implementation of the Board-decided rule. The client
 * (bookingfrontend) mirrors the same algorithm for instant frontend enforcement; keeping
 * the two in lock-step is why the rule is documented precisely here.
 *
 * MODE (driven by the open_days bitmask; bit (ISO weekday - 1) set = that day is open):
 *   - open_days === 127 (all days open) AND no holidays → plain calendar arithmetic
 *     (the pre-#373 behaviour, no business-day logic).
 *   - open_days === subset (some days closed) OR holidays supplied → working-days mode:
 *       * unit "days"  → count BACKWARDS in open / non-holiday days only. "3 working days"
 *         before a Monday event, with Sat+Sun closed, lands the PRIOR week (the
 *         Friday-for-Monday shortcut is intentionally REJECTED — the kitchen needs the
 *         working days).
 *       * unit "hours" → subtract the hours but roll over (skip) closed days and holidays
 *         while counting, so closed-day time does not consume lead time.
 *
 * HOLIDAY SOURCE — pluggable by design. No customer-registered season-holiday store
 * exists in the schema today (see the #373 feasibility note: bb_season_boundary holds only
 * weekday time-windows, and the legacy phpgw_cal_holidays table is not installed). Callers
 * therefore pass an explicit list of closed dates (['Y-m-d', ...]); it defaults to [] so the
 * weekends / open-days core works standalone. A future season-registration source — or the
 * algorithmic {@see \App\modules\phpgwapi\services\datetime::get_holidays()} — can be
 * injected without touching this class.
 */
class HospitalityDeadlineCalculator
{
    /** Bitmask value for "every weekday open" (Mon..Sun). */
    public const ALL_DAYS_OPEN = 127;

    /** Mon-Fri open, Sat+Sun closed — the "working days on" standard preset. */
    public const WORKING_DAYS = 31;

    /**
     * Compute the order deadline for an event.
     *
     * @param \DateTimeInterface $eventTime When the catering is needed.
     * @param int|null           $value     order_by_time_value (lead time). Null / <= 0 → no deadline.
     * @param string|null        $unit      "hours" | "days".
     * @param int                $openDays  Bitmask; bit (ISO weekday - 1) set = open. Default 127 (all open).
     * @param string[]           $holidays  Closed calendar dates as 'Y-m-d' strings.
     *
     * @return \DateTimeImmutable|null The cutoff (orders must be placed at or before this instant),
     *                                 or null when no deadline is configured. The event's time-of-day
     *                                 is preserved on the resulting date.
     */
    public function computeDeadline(
        \DateTimeInterface $eventTime,
        ?int $value,
        ?string $unit,
        int $openDays = self::ALL_DAYS_OPEN,
        array $holidays = []
    ): ?\DateTimeImmutable {
        if ($value === null || $value <= 0 || $unit === null) {
            return null;
        }

        $unit = strtolower($unit);
        if ($unit !== 'hours' && $unit !== 'days') {
            return null;
        }

        $cursor = \DateTimeImmutable::createFromInterface($eventTime);
        $openDays = $this->normalizeMask($openDays);
        $holidaySet = array_flip($holidays);

        // Fast path: all days open and no holidays → plain calendar arithmetic.
        if ($openDays === self::ALL_DAYS_OPEN && empty($holidaySet)) {
            $spec = $unit === 'hours' ? "PT{$value}H" : "P{$value}D";
            return $cursor->sub(new \DateInterval($spec));
        }

        return $unit === 'days'
            ? $this->subtractWorkingDays($cursor, $value, $openDays, $holidaySet)
            : $this->subtractWorkingHours($cursor, $value, $openDays, $holidaySet);
    }

    /**
     * Decode an open_days bitmask into a sorted ISO weekday list (1=Mon .. 7=Sun).
     * Exposed on the hospitality API response so the client needs no bit-ops.
     *
     * @return int[] e.g. 31 → [1,2,3,4,5]; 127 → [1,2,3,4,5,6,7].
     */
    public static function decodeOpenDays(int $openDays): array
    {
        $openDays = self::clampMask($openDays);
        $list = [];
        for ($iso = 1; $iso <= 7; $iso++) {
            if ($openDays & (1 << ($iso - 1))) {
                $list[] = $iso;
            }
        }
        return $list;
    }

    private function normalizeMask(int $openDays): int
    {
        $openDays = self::clampMask($openDays);
        // A zero mask ("every day closed") would make the deadline unsatisfiable; treat it as
        // all-open. Defensive only — the admin config UI never writes 0.
        return $openDays === 0 ? self::ALL_DAYS_OPEN : $openDays;
    }

    private static function clampMask(int $openDays): int
    {
        return $openDays & self::ALL_DAYS_OPEN; // keep only the 7 weekday bits
    }

    private function isOpen(\DateTimeImmutable $day, int $openDays, array $holidaySet): bool
    {
        if (isset($holidaySet[$day->format('Y-m-d')])) {
            return false;
        }
        $iso = (int) $day->format('N'); // 1=Mon .. 7=Sun
        return (bool) ($openDays & (1 << ($iso - 1)));
    }

    private function subtractWorkingDays(
        \DateTimeImmutable $eventTime,
        int $value,
        int $openDays,
        array $holidaySet
    ): \DateTimeImmutable {
        $cursor = $eventTime->modify('-1 day');
        $counted = 0;
        // Guard against a pathological config (e.g. only holidays ahead) causing a runaway loop.
        for ($guard = 0; $guard < 366 * 5; $guard++) {
            if ($this->isOpen($cursor, $openDays, $holidaySet)) {
                if (++$counted >= $value) {
                    return $cursor;
                }
            }
            $cursor = $cursor->modify('-1 day');
        }
        return $cursor; // best-effort fallback if the guard trips
    }

    private function subtractWorkingHours(
        \DateTimeImmutable $eventTime,
        int $value,
        int $openDays,
        array $holidaySet
    ): \DateTimeImmutable {
        $cursor = $eventTime;
        $remaining = $value;
        for ($guard = 0; $remaining > 0 && $guard < 24 * 366 * 5; $guard++) {
            $prev = $cursor->modify('-1 hour');
            // The hour block [prev, cursor) counts toward the lead time only if it lies on an
            // open, non-holiday day; closed-day hours are skipped (rolled over).
            if ($this->isOpen($prev, $openDays, $holidaySet)) {
                $remaining--;
            }
            $cursor = $prev;
        }
        return $cursor;
    }
}
