/**
 * Client-side mirror of the backend order-deadline rule (#373).
 *
 * This replicates App\modules\booking\services\HospitalityDeadlineCalculator so the
 * frontend can show an instant live preview of the order/cancellation cutoff. The
 * BACKEND remains authoritative — keep the two in lock-step.
 *
 * MODE (driven by the open-days config; ISO weekday 1=Mon..7=Sun):
 *  - all 7 days open AND no holidays  → plain calendar arithmetic (pre-#373 behaviour).
 *  - some days closed OR holidays      → working-days mode:
 *      * "days"  → count BACKWARDS in open / non-holiday days only (Friday-for-Monday
 *                  is intentionally rejected — the kitchen needs the working days).
 *      * "hours" → subtract the hours but skip (roll over) closed days and holidays.
 *
 * Holidays are a pending Board decision (no source in the schema yet); callers pass an
 * explicit list of 'YYYY-MM-DD' strings and it defaults to [] — the weekends/open-days
 * core works standalone, and a holiday source plugs in later on both sides.
 */

export const ALL_OPEN_DAYS: number[] = [1, 2, 3, 4, 5, 6, 7];

export type DeadlineUnit = 'hours' | 'days' | 'weeks';

/** Normalise an open-days list to a set of ISO weekdays, defaulting to all-open. */
function openDaySet(openDaysList?: number[] | null): Set<number> {
    if (Array.isArray(openDaysList) && openDaysList.length > 0) {
        const set = new Set(openDaysList.filter(d => d >= 1 && d <= 7));
        // A zero/empty mask would make the deadline unsatisfiable — treat as all-open (defensive).
        return set.size > 0 ? set : new Set(ALL_OPEN_DAYS);
    }
    return new Set(ALL_OPEN_DAYS);
}

/** Local calendar date as 'YYYY-MM-DD' (matches the backend holiday-key format, local time). */
function toYmd(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

/** ISO weekday for a Date (1=Mon..7=Sun); JS getDay() is 0=Sun..6=Sat. */
function isoWeekday(date: Date): number {
    const js = date.getDay();
    return js === 0 ? 7 : js;
}

function isOpen(date: Date, open: Set<number>, holidays: Set<string>): boolean {
    if (holidays.has(toYmd(date))) return false;
    return open.has(isoWeekday(date));
}

/**
 * Working-days mode is on when the catering is NOT open every day (a subset of weekdays),
 * or holidays are configured. All 7 days open + no holidays → plain calendar behaviour.
 */
export function isWorkingDaysMode(openDaysList?: number[] | null, holidays: string[] = []): boolean {
    if (holidays.length > 0) return true;
    return Array.isArray(openDaysList) && openDaysList.length > 0 && openDaysList.length < 7;
}

function subtractWorkingDays(eventTime: Date, value: number, open: Set<number>, holidays: Set<string>): Date {
    // Step days back preserving wall-clock time-of-day (DST-safe via setDate, not ms math).
    const cursor = new Date(eventTime);
    cursor.setDate(cursor.getDate() - 1);
    let counted = 0;
    for (let guard = 0; guard < 366 * 5; guard++) {
        if (isOpen(cursor, open, holidays)) {
            if (++counted >= value) return cursor;
        }
        cursor.setDate(cursor.getDate() - 1);
    }
    return cursor; // best-effort fallback if the guard trips (pathological config)
}

function subtractWorkingHours(eventTime: Date, value: number, open: Set<number>, holidays: Set<string>): Date {
    let cursor = new Date(eventTime);
    let remaining = value;
    for (let guard = 0; remaining > 0 && guard < 24 * 366 * 5; guard++) {
        const prev = new Date(cursor.getTime() - 3600000);
        // The hour block [prev, cursor) counts only if it lies on an open, non-holiday day.
        if (isOpen(prev, open, holidays)) remaining--;
        cursor = prev;
    }
    return cursor;
}

/**
 * Compute the order/cancellation cutoff (orders must be placed at or before this instant),
 * honouring the open-days config. Returns null when no deadline is configured.
 *
 * @param eventTime   When the catering is needed (serving time).
 * @param value       Lead-time value (null/<=0 → no deadline).
 * @param unit        'hours' | 'days' (the backend calc supports these; 'weeks' falls back to calendar).
 * @param openDaysList Decoded open weekdays (ISO 1=Mon..7=Sun); undefined/empty → all open.
 * @param holidays    Closed calendar dates as 'YYYY-MM-DD' (default []).
 */
export function computeHospitalityDeadline(
    eventTime: Date,
    value: number | null | undefined,
    unit: DeadlineUnit | null | undefined,
    openDaysList?: number[] | null,
    holidays: string[] = []
): Date | null {
    if (!value || value <= 0 || !unit) return null;

    const open = openDaySet(openDaysList);
    const holidaySet = new Set(holidays);
    const allOpen = open.size === 7;

    // Calendar fast path: 'weeks' (backend has no working-week rule), or all-open + no holidays.
    if (unit === 'weeks' || (allOpen && holidaySet.size === 0)) {
        const ms = unit === 'hours' ? value * 3600000
            : unit === 'weeks' ? value * 604800000
                : value * 86400000;
        return new Date(eventTime.getTime() - ms);
    }

    return unit === 'days'
        ? subtractWorkingDays(eventTime, value, open, holidaySet)
        : subtractWorkingHours(eventTime, value, open, holidaySet);
}

/** Map the app language (no/nn/en) to a BCP-47 locale for Intl weekday formatting. */
function localeFor(lang: string): string {
    if (lang === 'nn') return 'nn-NO';
    if (lang === 'en') return 'en-GB';
    return 'nb-NO';
}

/**
 * Human list of the open weekdays, e.g. "man., tir., ons., tor., fre." in the app locale.
 * Uses Intl so no per-weekday translation keys are needed.
 */
export function formatOpenDays(openDaysList: number[] | null | undefined, lang: string, style: 'long' | 'short' = 'long'): string {
    const list = (Array.isArray(openDaysList) && openDaysList.length > 0 ? openDaysList : ALL_OPEN_DAYS)
        .filter(d => d >= 1 && d <= 7)
        .sort((a, b) => a - b);
    const fmt = new Intl.DateTimeFormat(localeFor(lang), {weekday: style});
    // 2024-01-01 is a Monday → ISO weekday n maps to that date + (n-1) days.
    return list
        .map(iso => fmt.format(new Date(2024, 0, 1 + (iso - 1))))
        .join(', ');
}
