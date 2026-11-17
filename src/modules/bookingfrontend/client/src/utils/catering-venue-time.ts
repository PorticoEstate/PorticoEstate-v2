/**
 * Catering times as the venue reads them: the serving time of an order, the day it falls on, and
 * the deadlines that govern it, all in ONE zone, VENUE_TIMEZONE (Europe/Oslo).
 *
 * `serving_time_iso` reaches the client as an offset-aware instant ("2026-11-03T12:00:00+01:00",
 * measured on GET /bookingfrontend/applications/{id}/hospitality-orders), so it is parsed as an
 * instant and never sliced as text. Which day it belongs to, and what time the wall clock showed,
 * is then answered here and only here: the browser's zone and the offset the server happened to
 * write do not matter. A UTC date split would put 00:30 Oslo on the previous day; the browser's zone
 * would move the order for anyone viewing from outside Norway.
 *
 * Pure (no React, no DOM), so tests/hospitality-deadline/run_serving_days.mts can load it.
 */
import {localeFor, VENUE_TIMEZONE} from './hospitality-deadline';

/** The ONE place a time zone is chosen: every formatter below is built here. */
const venueFormatters = new Map<string, Intl.DateTimeFormat>();
function venueFormat(locale: string, options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
    const cacheKey = `${locale}|${JSON.stringify(options)}`;
    let format = venueFormatters.get(cacheKey);
    if (!format) {
        format = new Intl.DateTimeFormat(locale, {...options, timeZone: VENUE_TIMEZONE});
        venueFormatters.set(cacheKey, format);
    }
    return format;
}

interface VenueParts {
    year: string;
    month: string;
    day: string;
    hour: string;
    minute: string;
}

/**
 * The wall clock of an instant at the venue. en-GB is only a fixed numeric carrier (latin digits,
 * whatever the UI language); 'h23' so that midnight is "00", never "24".
 */
function venueParts(ms: number): VenueParts {
    const parts = venueFormat('en-GB', {
        year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(ms);
    const pick = (type: Intl.DateTimeFormatPartTypes): string => parts.find(part => part.type === type)?.value ?? '';
    return {year: pick('year'), month: pick('month'), day: pick('day'), hour: pick('hour'), minute: pick('minute')};
}

export interface ServingMoment {
    /** The instant, in epoch milliseconds: what orders sort by. */
    ms: number;
    /** The venue calendar day, 'YYYY-MM-DD': what orders group by. */
    dayKey: string;
    /** The venue wall-clock time shown on the order, 'HH:mm'. */
    time: string;
}

/**
 * Where an order's serving time falls. `null` for no serving time and for one that cannot be read
 * as a date: Intl's format() THROWS a RangeError on an invalid date, which would take the whole
 * Min side down, so an unreadable value is treated as «no serving time».
 */
export function servingMoment(iso: string | null | undefined): ServingMoment | null {
    if (!iso) return null;
    const ms = Date.parse(iso);
    if (!Number.isFinite(ms)) return null;
    const {year, month, day, hour, minute} = venueParts(ms);
    return {ms, dayKey: `${year}-${month}-${day}`, time: `${hour}:${minute}`};
}

/** First letter upper-cased in the UI locale (Intl gives «torsdag»); by code point, not by UTF-16 unit. */
function capitalise(text: string, locale: string): string {
    const [first = '', ...rest] = Array.from(text);
    return first.toLocaleUpperCase(locale) + rest.join('');
}

/**
 * «Torsdag 12. november» / «Thursday 12 November», in the UI language. The year is added only when
 * it is not the current year, both read at the venue («Mandag 4. juni 2029»).
 *
 * `nn` is mapped by localeFor: Chromium has no Nynorsk data and would answer in en-US.
 */
export function formatServingDayHeading(ms: number, lang: string, nowMs: number): string {
    const locale = localeFor(lang);
    const showYear = venueParts(ms).year !== venueParts(nowMs).year;
    const text = venueFormat(locale, {
        weekday: 'long', day: 'numeric', month: 'long', ...(showYear ? {year: 'numeric'} : {}),
    }).format(ms);
    return capitalise(text, locale);
}

export interface ServingDayGroup<T> {
    /** 'YYYY-MM-DD' at the venue; null for the group of orders without a serving time. */
    dayKey: string | null;
    /** The day heading; null for the group without a serving time (its heading is a lang key). */
    heading: string | null;
    /** The orders of the day, earliest first, equal times by order id. `time` is null without one. */
    items: Array<{order: T; time: string | null}>;
}

/**
 * The orders grouped per venue day, days ascending, each day by serving time and then by id; the
 * orders without a serving time come last, by id.
 *
 * Sorted on the instant, not on the displayed time: the hour that clocks repeat when summer time
 * ends (02:30 twice) is shown the same and must still come out in the order it happened.
 */
export function groupByServingDay<T extends {id: number; serving_time_iso: string | null}>(
    orders: readonly T[],
    lang: string,
    nowMs: number,
): Array<ServingDayGroup<T>> {
    const dated: Array<{order: T; moment: ServingMoment}> = [];
    const undated: T[] = [];
    for (const order of orders) {
        const moment = servingMoment(order.serving_time_iso);
        if (moment) {
            dated.push({order, moment});
        } else {
            undated.push(order);
        }
    }
    dated.sort((a, b) => a.moment.ms - b.moment.ms || a.order.id - b.order.id);

    const groups: Array<ServingDayGroup<T>> = [];
    for (const {order, moment} of dated) {
        const last = groups[groups.length - 1];
        if (last && last.dayKey === moment.dayKey) {
            last.items.push({order, time: moment.time});
        } else {
            groups.push({
                dayKey: moment.dayKey,
                heading: formatServingDayHeading(moment.ms, lang, nowMs),
                items: [{order, time: moment.time}],
            });
        }
    }
    if (undated.length > 0) {
        undated.sort((a, b) => a.id - b.id);
        groups.push({dayKey: null, heading: null, items: undated.map(order => ({order, time: null}))});
    }
    return groups;
}

/**
 * A change / cancel deadline, «10. nov. 2026, 12:00» (the same shape as the cart's «Kan avbestilles
 * innen %1»), in venue time like the serving time it counts back from. '' for none or unreadable
 * (see servingMoment).
 */
export function formatVenueDeadline(iso: string | null | undefined): string {
    if (!iso) return '';
    const ms = Date.parse(iso);
    if (!Number.isFinite(ms)) return '';
    return venueFormat('nb-NO', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(ms);
}
