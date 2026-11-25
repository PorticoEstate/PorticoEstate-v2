import {DateTime} from 'luxon';
import {Season} from '@/service/types/Building';

/**
 * Whether an instant falls inside the opening hours defined by a set of seasons.
 *
 * 🔴 AN EMPTY `resourceIds` MEANS "NO FILTER", NOT "NO RESOURCE". If you do not know which
 * resource applies, DO NOT express that as `[]` -- this function reads absence as permission
 * and will match every season. A caller that can legitimately lack an id has a no-data state
 * and must handle it deliberately (show nothing, not everything).
 *
 * Extracted from application-crud.tsx so the booking calendar and the hospitality order
 * modal judge opening hours by the same rules. The exits below are deliberately
 * ASYMMETRIC and must move as one block -- re-deriving them is how they get collapsed:
 *
 *   1. no seasons at all                -> open or closed per the admin setting
 *   2. no ACTIVE season covers the date -> CLOSED
 *   3. no boundaries that weekday       -> CLOSED
 *
 * A boundary ending >= 23:45 is treated as ending at midnight -- see the comment at the
 * final return. That extends the END only; it is not a fourth exit and does not open the
 * morning. The original form was a whole-day early return whose guard
 * (`timeStr <= '24:00:00'`) was a TAUTOLOGY, so it opened every hour on such a day and the
 * boundary check was unreachable -- 4 of 7 buildings with live seasons, 18 building x
 * weekday pairs. That is fixed here, and separately in application-crud.tsx on
 * henning-fleet-fix/business-hours-tautology, which is where the PRODUCTION defect lives.
 *
 * @param date               the instant to test, in venue-local terms
 * @param resourceIds        resources to match against each season's resources. REQUIRED,
 *                           deliberately: ⚠️ AN EMPTY ARRAY MATCHES EVERY SEASON (fails open),
 *                           so a caller gating a real booking must pass a non-empty list and
 *                           must not be able to reach the permissive branch by omission.
 * @param seasons            season set for the building that owns the resource
 * @param closeWhenNoSeasons admin setting: close the calendar when no seasons exist
 */
export function isWithinBusinessHours(
	date: Date,
	resourceIds: string[],
	seasons: Season[] | undefined,
	closeWhenNoSeasons: boolean,
): boolean {
	// No seasons defined at all: closed or open depending on admin config
	if (!seasons || seasons.length === 0) {
		return !closeWhenNoSeasons;
	}
	const dt = DateTime.fromJSDate(date);
	const dayOfWeek = dt.weekday;
	const timeStr = dt.toFormat('HH:mm:ss');

	const activeSeasons = activeSeasonsAt(dt, resourceIds, seasons);

	// No active season covers this date (out of season): closed, mirroring the
	// calendar's isDateCoveredBySeason gate.
	if (activeSeasons.length === 0) {
		return false;
	}

	// Get all boundaries for this day from active seasons
	const dayBoundaries = activeSeasons.flatMap(season =>
		season.boundaries.filter(b => b.wday === dayOfWeek)
	);

	// If no boundaries defined for this day, consider it CLOSED (not within business hours)
	// This ensures that days missing from season boundaries are treated as closed
	if (dayBoundaries.length === 0) {
		return false;
	}

	// A boundary reaching 23:45 or later is treated as ending at midnight -- "allow bookings
	// until midnight", the intent the original comment documented.
	//
	// It extends the END ONLY. The previous form was a separate early return guarded by
	// `timeStr <= '24:00:00'`, which is TRUE for every possible time: it opened the whole
	// day, 00:00 included, and this check was never reached. The same asymmetric rule is
	// implemented correctly in two other places -- full-calendar-view.tsx (slotMaxTime, and
	// the closed-block skip after the last boundary) and application-crud.tsx
	// (getTimeBoundariesForDate) -- neither of which has a morning equivalent.
	return dayBoundaries.some(boundary => {
		const effectiveTo = boundary.to_ >= '23:45:00' ? '24:00:00' : boundary.to_;
		return boundary.from_ <= timeStr && effectiveTo >= timeStr;
	});
}

/**
 * The seasons active at `dt` that carry one of `resourceIds`. An empty `resourceIds` matches every
 * season (see the warning on isWithinBusinessHours). Shared by isWithinBusinessHours and
 * openingSpanForDay, so the time list and the help text judge a day by the same seasons.
 */
function activeSeasonsAt(dt: DateTime, resourceIds: string[], seasons: Season[]): Season[] {
	return seasons.filter(season => {
		const seasonStart = DateTime.fromISO(season.from_);
		const seasonEnd = DateTime.fromISO(season.to_);

		// Check if season has any resources that match selected resources
		const hasMatchingResources = resourceIds.length === 0 ||
			season.resources.some(seasonResource =>
				resourceIds.includes(seasonResource.id.toString())
			);

		return season.active && dt >= seasonStart && dt <= seasonEnd && hasMatchingResources;
	});
}

/**
 * The span of one resource's opening hours on the day of `date`: the earliest boundary start to the
 * latest boundary end, over the seasons isWithinBusinessHours judges that day by. Null when no active
 * season covers the day for the resource, or when no season is defined at all.
 *
 * This names the hours in a help text. It is NOT a gate: a time inside the span but in a gap between
 * two boundaries is still refused by isWithinBusinessHours, which callers must keep using for the list
 * of times they offer.
 */
export function openingSpanForDay(
	date: Date,
	resourceId: string,
	seasons: Season[] | undefined,
): {from: string; to: string} | null {
	if (!seasons || seasons.length === 0) return null;
	const dt = DateTime.fromJSDate(date);
	const dayBoundaries = activeSeasonsAt(dt, [resourceId], seasons)
		.flatMap(season => season.boundaries.filter(b => b.wday === dt.weekday));
	if (dayBoundaries.length === 0) return null;
	// The same midnight rule as isWithinBusinessHours: a boundary reaching 23:45 is open until midnight.
	const endOf = (b: {to_: string}) => (b.to_ >= '23:45:00' ? '24:00:00' : b.to_);
	const from = dayBoundaries.reduce((min, b) => (b.from_ < min ? b.from_ : min), dayBoundaries[0].from_);
	const to = dayBoundaries.reduce((max, b) => (endOf(b) > max ? endOf(b) : max), endOf(dayBoundaries[0]));
	// 'HH:mm:ss' in, 'HH:mm' out: the help text names hours and minutes only.
	return {from: from.slice(0, 5), to: to.slice(0, 5)};
}
