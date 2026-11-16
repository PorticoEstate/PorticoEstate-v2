/**
 * When an application is, in one line, for the page header (GH #1423):
 * «Onsdag 23. desember 2026, 08:00–16:00». An application with several dates shows its EARLIEST one,
 * and says how many more there are (`moreDates`); the caller words that («+2 datoer»).
 *
 * Read at the venue (Europe/Oslo), like the serving times of the catering section on the same page: the
 * dates arrive as offset-aware instants, so the browser's zone must not move them (see catering-venue-time).
 *
 * Pure (no React, no DOM), so tests/application-view/run.mts can load it.
 */
import {localeFor, VENUE_TIMEZONE} from './hospitality-deadline';
import {servingMoment} from './catering-venue-time';

const dayFormats = new Map<string, Intl.DateTimeFormat>();

/** «onsdag 23. desember 2026» / «Wednesday 23 December 2026»: the year always, the venue's zone. */
function dayFormat(locale: string): Intl.DateTimeFormat {
    let format = dayFormats.get(locale);
    if (!format) {
        format = new Intl.DateTimeFormat(locale, {
            timeZone: VENUE_TIMEZONE, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
        });
        dayFormats.set(locale, format);
    }
    return format;
}

/** First letter upper-cased in the UI locale (Intl gives «onsdag»); by code point, not by UTF-16 unit. */
function capitalise(text: string, locale: string): string {
    const [first = '', ...rest] = Array.from(text);
    return first.toLocaleUpperCase(locale) + rest.join('');
}

export interface ApplicationWhen {
    /** The earliest date with its time: «Onsdag 23. desember 2026, 08:00–16:00». */
    text: string;
    /** How many dates there are besides that one; 0 for an application with one date. */
    moreDates: number;
}

/**
 * The earliest of `dates` as a line, and how many more there are. A date that spans midnight reads
 * «Onsdag 23. desember 2026 22:00 → Torsdag 24. desember 2026 02:00». Null when no date can be read
 * (Intl throws on an invalid date, so an unreadable one is skipped, never formatted).
 */
export function applicationWhen(
    dates: ReadonlyArray<{from_: string; to_: string}>,
    lang: string,
): ApplicationWhen | null {
    const locale = localeFor(lang);
    const readable = dates.flatMap(date => {
        const from = servingMoment(date.from_);
        return from ? [{from, to: servingMoment(date.to_)}] : [];
    });
    if (readable.length === 0) return null;

    const first = readable.reduce((earliest, date) => (date.from.ms < earliest.from.ms ? date : earliest));
    const day = (ms: number) => capitalise(dayFormat(locale).format(ms), locale);

    let text: string;
    if (!first.to) {
        text = `${day(first.from.ms)}, ${first.from.time}`;
    } else if (first.to.dayKey === first.from.dayKey) {
        text = `${day(first.from.ms)}, ${first.from.time}–${first.to.time}`;
    } else {
        text = `${day(first.from.ms)} ${first.from.time} → ${day(first.to.ms)} ${first.to.time}`;
    }
    return {text, moreDates: dates.length - 1};
}
