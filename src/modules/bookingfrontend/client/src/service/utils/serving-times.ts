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
