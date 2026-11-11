import {localeFor} from './hospitality-deadline';

type Translate = (key: string, options?: any) => string;

// A non-breaking space keeps a number and its unit on one line, as formatMinutes does.
const NBSP = ' ';

const NUMBER_FORMATS = new Map<string, Intl.NumberFormat>();

/** One formatter per locale: a recurring series prints a row per occurrence. Never grouped, like formatQuantity. */
const numberFormatFor = (locale: string): Intl.NumberFormat => {
    let format = NUMBER_FORMATS.get(locale);
    if (!format) {
        format = new Intl.NumberFormat(locale, {maximumFractionDigits: 2, useGrouping: false});
        NUMBER_FORMATS.set(locale, format);
    }
    return format;
};

/**
 * A duration in hours the way the UI language writes numbers, followed by that language's short unit:
 * «2,5 t» (no, nn), «2.5 h» (en), «3 t» for whole hours.
 *
 * The locale is localeFor(lang), the one language -> locale mapping of the client: no -> nb-NO, nn -> 'no', en -> en-GB.
 * Never 'nn' or 'nn-NO' for nn: Chromium has no Nynorsk data and Intl would answer in en-US, a decimal POINT in a
 * Nynorsk UI (measured, Chrome 157). Unlike formatQuantity and formatCurrency, which are nb-NO whatever the language.
 */
export const formatHours = (hours: number, lang: string, t: Translate): string =>
    `${numberFormatFor(localeFor(lang)).format(hours)}${NBSP}${t('bookingfrontend.hours_short')}`;
