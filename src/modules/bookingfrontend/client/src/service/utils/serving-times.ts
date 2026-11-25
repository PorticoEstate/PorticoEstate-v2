import {Season} from '@/service/types/Building';
import {isWithinBusinessHours} from '@/service/utils/business-hours';

/**
 * The start times, on a 15-minute grid, from `fromHour:fromMinute` to `toHour:toMinute` (both ends
 * included). The first start is rounded UP to the grid: a booking from 18:07 offers 18:15 first.
 */
export function generateTimeSlots(fromHour: number, fromMinute: number, toHour: number, toMinute: number): string[] {
	const slots: string[] = [];
	let h = fromHour;
	let m = Math.ceil(fromMinute / 15) * 15;
	if (m >= 60) {
		h++;
		m = 0;
	}
	while (h < toHour || (h === toHour && m <= toMinute)) {
		slots.push(`${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`);
		m += 15;
		if (m >= 60) {
			h++;
			m = 0;
		}
	}
	return slots;
}

/**
 * The start times of a booking window that the kitchen's opening hours offer on the window's day. This is
 * THE list the on-site Klokkeslett select shows. Its help text names the first and last entry (see
 * servingTimeBounds), so the text and the list come from one computation and cannot disagree.
 *
 * Each start is judged by isWithinBusinessHours. A gap between two of the kitchen's boundaries is therefore
 * refused here, while the help text still names the span from the first offered time to the last.
 */
export function offeredServingTimes(
	booking: {from: Date; to: Date},
	resourceId: string,
	seasons: Season[] | undefined,
	closeWhenNoSeasons: boolean,
): string[] {
	const day = booking.from;
	return generateTimeSlots(
		booking.from.getHours(),
		booking.from.getMinutes(),
		booking.to.getHours(),
		booking.to.getMinutes(),
	).filter(hm => {
		const [h, m] = hm.split(':').map(Number);
		const at = new Date(day.getFullYear(), day.getMonth(), day.getDate(), h, m);
		return isWithinBusinessHours(at, [resourceId], seasons, closeWhenNoSeasons);
	});
}

/**
 * The first and last time in an offered list: the span the on-site help text names. Null for an empty
 * list, so the caller shows the closed message instead of a range.
 */
export function servingTimeBounds(offered: string[]): {from: string; to: string} | null {
	if (offered.length === 0) return null;
	return {from: offered[0], to: offered[offered.length - 1]};
}

/**
 * The kitchen's own first and last start on the day, on the same 15-minute grid and judged by the same
 * isWithinBusinessHours as the list. It is never named in the text. It only tells a closed kitchen (null) from
 * one that is open that day but offers no time inside the booking.
 */
export function kitchenOpeningSpanOnDay(
	day: Date,
	resourceId: string,
	seasons: Season[] | undefined,
	closeWhenNoSeasons: boolean,
): {from: string; to: string} | null {
	const open = generateTimeSlots(0, 0, 23, 45).filter(hm => {
		const [h, m] = hm.split(':').map(Number);
		const at = new Date(day.getFullYear(), day.getMonth(), day.getDate(), h, m);
		return isWithinBusinessHours(at, [resourceId], seasons, closeWhenNoSeasons);
	});
	return servingTimeBounds(open);
}

/** What the help under the on-site Klokkeslett field says. The modal words it; servingTimeHelp decides it. */
export type ServingTimeHelp =
	| {kind: 'kitchen'; from: string; to: string}
	| {kind: 'application'}
	| {kind: 'closed'}
	| {kind: 'none'};

/**
 * Which help text the time field shows, from the same `offered` array the select renders. A range is only ever
 * named when the list offers a time inside it.
 *
 * - Delivery, or on-site with the kitchen's hours not known: the booking's own times when the list offers any
 *   (for delivery the list IS the booking's slots). With no time offered, nothing is named.
 * - On-site with the kitchen's hours known and times offered: the first and last time the list offers.
 * - On-site with no time offered: `closed` when the kitchen's hours can be judged and the kitchen has no opening
 *   that day. That includes a building with no season rows while close-without-season is on, whose span is null.
 *   Otherwise `none`, which names no hours and never reads as a closure. Hours that cannot be judged (building
 *   unknown, seasons still loading) are `none` too.
 *
 * `kitchenHoursKnown` means the kitchen's seasons define its hours, so a range can be named from them.
 * `kitchenHoursJudgeable` means the kitchen's seasons are loaded, an empty list included, so `kitchenSpan` is an answer.
 */
export function servingTimeHelp(input: {
	isDelivery: boolean;
	offered: string[];
	kitchenHoursJudgeable: boolean;
	kitchenHoursKnown: boolean;
	kitchenSpan: {from: string; to: string} | null;
}): ServingTimeHelp {
	if (input.offered.length === 0) {
		const kitchenClosedThatDay = !input.isDelivery && input.kitchenHoursJudgeable && input.kitchenSpan === null;
		return kitchenClosedThatDay ? {kind: 'closed'} : {kind: 'none'};
	}
	if (input.isDelivery || !input.kitchenHoursKnown) return {kind: 'application'};
	const bounds = servingTimeBounds(input.offered);
	return bounds ? {kind: 'kitchen', ...bounds} : {kind: 'none'};
}
