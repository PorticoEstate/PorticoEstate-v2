/**
 * What, if anything, stops a calendar selection (#1573).
 *
 * - 'booked': the selection overlaps a booking, allocation or event on a resource that does not
 *   allow overlapping bookings. The person should be told the time is taken.
 * - 'closed': the selection only touches closed time (the grey background outside opening
 *   hours). Nothing is booked there, so it must not be reported as an overlap with another booking.
 * - null: the selection is allowed.
 *
 * Times are epoch milliseconds so the helper has no calendar or date-library dependency.
 */
export type SelectionOverlapKind = 'booked' | 'closed' | null;

export interface SelectionOverlapCandidate {
	start: number;
	end: number;
	/** 'booking' | 'allocation' | 'event' | 'background' | ... */
	type?: string;
	closed?: boolean;
	title?: string;
}

export interface SelectionOverlapResult {
	kind: SelectionOverlapKind;
	/** Title of the first overlapping booking, when kind is 'booked'. */
	title?: string;
}

const BOOKED_TYPES = new Set(['booking', 'allocation', 'event']);

export function findSelectionOverlap(
	candidates: SelectionOverlapCandidate[],
	selectStart: number,
	selectEnd: number,
	denyOverlapWithBookings: boolean
): SelectionOverlapResult {
	let touchesClosedTime = false;

	for (const candidate of candidates) {
		const overlaps = !(selectEnd <= candidate.start || selectStart >= candidate.end);
		if (!overlaps) continue;

		if (denyOverlapWithBookings && candidate.type !== undefined && BOOKED_TYPES.has(candidate.type)) {
			return {kind: 'booked', title: candidate.title || undefined};
		}
		if (candidate.closed) {
			touchesClosedTime = true;
		}
	}

	return {kind: touchesClosedTime ? 'closed' : null};
}
