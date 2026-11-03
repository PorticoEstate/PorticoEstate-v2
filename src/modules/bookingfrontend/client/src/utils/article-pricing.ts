import {DateTime} from 'luxon';
import {IArticle} from '@/service/types/api/order-articles.types';
import {IOrderLine} from '@/service/types/api/application.types';
import {formatAmount, formatCurrency} from '@/utils/cost-utils';

/**
 * Articles priced per minute (article mapping unit 'minute').
 *
 * The stored price is per MINUTE, but the applicant must only ever see the TOTAL and the HOURLY
 * price (minute price x 60) as its explanation: «705 kr (282 kr/time)».
 *
 * Unlike the 'hour' unit, which bills whole started hours, a minute article bills the exact
 * booked minutes. Never derive them from the rounded-up hour count.
 */

const MINUTES_PER_HOUR = 60;

type Translate = (key: string, options?: any) => string;

export const isMinuteUnit = (unit?: string | null): boolean => unit === 'minute';

/**
 * Exact booked minutes between start and end, as a whole number (the quantity sent to the server,
 * which stores and bills it as given). Without both times the default is one hour, like the hourly path.
 */
export const bookedMinutes = (start?: Date, end?: Date): number => {
    if (!start || !end) return MINUTES_PER_HOUR;
    const minutes = DateTime.fromJSDate(end).diff(DateTime.fromJSDate(start), 'minutes').minutes;
    return Math.max(0, Math.round(minutes));
};

const toOre = (kroner: string | number): number => Math.round(Number(kroner) * 100);

/**
 * Total incl. VAT, in kroner, for `minutes` of a minute article. Mirrors how the server stores the
 * line (ArticleRepository::savePurchaseOrderLine): amount = ex-VAT price x quantity and
 * tax = amount x VAT%, each to the øre. The ex-VAT price has two decimals and the quantity is whole,
 * so the amount is exact and the tax is the only rounding. The API's own unit_price/price are
 * already rounded to two decimals and must not be multiplied.
 */
export const minuteArticleTotal = (article: Pick<IArticle, 'ex_tax_price' | 'tax_percent'>, minutes: number): number => {
    const amountOre = toOre(article.ex_tax_price) * minutes;
    const taxOre = Math.round(amountOre * Number(article.tax_percent) / 100);
    return (amountOre + taxOre) / 100;
};

/** Hourly price incl. VAT of a minute article that has not been booked yet (the form). */
export const hourlyRateOfMinuteArticle = (article: Pick<IArticle, 'ex_tax_price' | 'tax_percent'>): number =>
    Math.round(toOre(article.ex_tax_price) * MINUTES_PER_HOUR * (100 + Number(article.tax_percent)) / 100) / 100;

/**
 * Hourly price incl. VAT of a booked order line: what was billed per minute, times 60. The API serves
 * numeric fields as strings, so everything is coerced. Null when there is nothing to divide by.
 */
export const hourlyRateOfOrderLine = (line: Pick<IOrderLine, 'amount' | 'tax' | 'quantity'>): number | null => {
    const quantity = Number(line.quantity);
    const amount = Number(line.amount);
    if (!(quantity > 0) || !(amount > 0)) return null;
    const total = amount + Number(line.tax || 0);
    return Math.round(total * MINUTES_PER_HOUR / quantity * 100) / 100;
};

// A non-breaking space keeps a number and its unit on one line, as Intl does for «705 kr».
const NBSP = '\u00A0';

/** «282 kr/time» */
export const formatHourlyRate = (hourlyRate: number, t: Translate): string =>
    `${formatAmount(hourlyRate)}${NBSP}${t('bookingfrontend.kr_per_hour')}`;

/** «705 kr (282 kr/time)», or just «705 kr» when there is no hourly price to explain it. */
export const formatTotalWithHourlyRate = (
    total: number,
    hourlyRate: number | null | undefined,
    currency: string,
    t: Translate
): string => {
    const formattedTotal = formatCurrency(total, currency);
    return hourlyRate ? `${formattedTotal} (${formatHourlyRate(hourlyRate, t)})` : formattedTotal;
};

/**
 * The label stored with a chosen price is its remark, or, when the remark is empty, the bare amount
 * (ArticleRepository::priceLabel). On a minute line that amount is the per-minute price, so a label
 * that is only an amount must not be shown to the applicant.
 */
export const isAmountOnlyLabel = (label?: string | null): boolean =>
    label != null && /^\d+([.,]\d+)?$/.test(label.trim());

/** «150 min» */
export const formatMinutes = (minutes: number, t: Translate): string =>
    `${minutes}${NBSP}${t('bookingfrontend.minutes_short')}`;
