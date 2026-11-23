/**
 * The label for the closing time at the bottom of the building calendar's time axis (#1537).
 *
 * FullCalendar draws a slot label only while slatTime < slotMaxTime (@fullcalendar/timegrid internal.js:1082), so the
 * closing time itself never gets one. closingAxisLabel derives that one label from the slotMaxTime the view is given.
 * It is formatted with SLOT_LABEL_FORMAT, the same options as the view's slotLabelFormat, in the calendar's locale, so
 * it reads like the labels beside it.
 */

/** The view's slotLabelFormat options (full-calendar-view.tsx). One constant, so the two cannot drift apart. */
export const SLOT_LABEL_FORMAT: Intl.DateTimeFormatOptions = {
	hour: '2-digit',
	minute: '2-digit',
	hour12: false,
};

/**
 * setSlotMaxTime (full-calendar-view.tsx) already treats a closing time from 23:45 on as the end of the day, and the
 * grid runs to midnight there. The label says so too.
 */
const END_OF_DAY_FROM_MINUTES = 23 * 60 + 45;

const CLOCK_TIME = /^(\d{2}):(\d{2})(?::\d{2})?$/;

/**
 * The closing label for a slotMaxTime such as '22:00:00', or null when slotMaxTime is not a clock time.
 *
 *   closingAxisLabel('22:00:00', 'nb-NO') === '22:00'
 *   closingAxisLabel('23:45:00', 'nb-NO') === '24:00'   the end of the day, as setSlotMaxTime maps it
 *   closingAxisLabel('24:00:00', 'nb-NO') === '24:00'   midnight is the end of the day, never '00:00'
 */
export const closingAxisLabel = (slotMaxTime: string | null | undefined, locale: string): string | null => {
	const match = CLOCK_TIME.exec(slotMaxTime ?? '');
	if (!match) return null;

	const hours = Number(match[1]);
	const minutes = Number(match[2]);
	if (hours > 24 || minutes > 59 || (hours === 24 && minutes > 0)) return null;

	const endOfDay = hours * 60 + minutes >= END_OF_DAY_FROM_MINUTES;
	// A fixed January day, so no DST switch can make the clock time missing or ambiguous.
	const clock = endOfDay ? new Date(2000, 0, 3, 0, 0) : new Date(2000, 0, 3, hours, minutes);

	// The hour is written as 24 at the end of the day whatever the engine's own midnight is ('00' or '24').
	return new Intl.DateTimeFormat(locale, SLOT_LABEL_FORMAT)
		.formatToParts(clock)
		.map(part => (endOfDay && part.type === 'hour' ? '24' : part.value))
		.join('');
};
