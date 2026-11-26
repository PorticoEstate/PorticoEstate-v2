/**
 * Overlap check for the application form (#1573).
 *
 * The form blocks a time that overlaps a booking, allocation or event on a resource with
 * deny_application_if_booked. It used to say only «Ressurs-overlapping oppdaget», without what
 * collides or when. That left people stuck when the collision was outside the part of the
 * calendar they were looking at, typically when start and end were on different weeks and the
 * repeat switch was off: the time then runs non-stop for weeks and hits bookings on other days.
 *
 * These helpers find the first colliding booking, so the form can name it, and tell whether the
 * chosen time runs over more than one day, so the form can point to the repeat switch.
 *
 * Plain timestamps only, no calendar or date-library dependency.
 */

export interface FormOverlapEvent {
	id: number | string;
	from_: string;
	to_: string;
	resources: Array<{ id: number }>;
}

/**
 * The earliest event that overlaps [startMs, endMs) on one of the deny-flagged resources,
 * or null when there is none. Touching edges is not an overlap.
 */
export function findFirstFormConflict<T extends FormOverlapEvent>(
	events: T[],
	startMs: number,
	endMs: number,
	denyResourceIds: number[],
	excludeEventId?: number | string
): T | null {
	if (denyResourceIds.length === 0) return null;

	let first: T | null = null;
	let firstStart = Infinity;
	// An event whose times cannot be read still blocks, as it did before (the comparison with an
	// unreadable time never rules out an overlap). It is only named when nothing readable collides.
	let unreadable: T | null = null;

	for (const event of events) {
		if (excludeEventId !== undefined && event.id === excludeEventId) continue;
		if (!event.resources.some(res => denyResourceIds.includes(res.id))) continue;

		const eventStart = Date.parse(event.from_);
		const eventEnd = Date.parse(event.to_);
		if (Number.isNaN(eventStart) || Number.isNaN(eventEnd)) {
			unreadable = unreadable ?? event;
			continue;
		}

		const overlaps = !(endMs <= eventStart || startMs >= eventEnd);
		if (overlaps && eventStart < firstStart) {
			first = event;
			firstStart = eventStart;
		}
	}

	return first ?? unreadable;
}

/**
 * «A, B og C» in the form's language. The client uses 'no' for bokmål, which Intl calls 'nb'.
 */
export function joinNames(names: string[], language: string | undefined): string {
	const locale = language === 'no' ? 'nb' : (language || 'nb');
	try {
		return new Intl.ListFormat(locale, {style: 'long', type: 'conjunction'}).format(names);
	} catch {
		return names.join(', ');
	}
}

/**
 * True when start and end fall on different calendar days (local time). An end at exactly
 * midnight still counts as the start day, so 20:00-00:00 is one evening, not two days.
 */
export function spansMultipleDays(start: Date, end: Date): boolean {
	if (end.getTime() <= start.getTime()) return false;
	const lastMoment = new Date(end.getTime() - 1);
	return start.getFullYear() !== lastMoment.getFullYear()
		|| start.getMonth() !== lastMoment.getMonth()
		|| start.getDate() !== lastMoment.getDate();
}
