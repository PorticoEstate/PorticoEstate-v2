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
 * What a line of `quantity` units costs incl. VAT, in whole øre, for ANY unit. Mirrors how the server
 * stores the line (ArticleRepository::savePurchaseOrderLine): amount = ex-VAT price x quantity and
 * tax = amount x VAT%, each to the øre. The ex-VAT price has two decimals and the quantity is whole,
 * so the amount is exact and the tax is the only rounding. The API's own unit_price/price are already
 * rounded to two decimals incl. VAT and must never be multiplied: 136.99 ex at 25 % is served as
 * 171.24, and 3 x 171.24 = 513.72, while the server stores 410.97 + 102.74 = 513.71.
 *
 * Sum lines in øre and divide ONCE at the end; never round a line before adding it to a total.
 */
export const lineTotalOre = (article: Pick<IArticle, 'ex_tax_price' | 'tax_percent'>, quantity: number): number => {
    const amountOre = toOre(article.ex_tax_price) * quantity;
    const taxOre = Math.round(amountOre * Number(article.tax_percent) / 100);
    return amountOre + taxOre;
};

/** Total incl. VAT, in kroner, for `minutes` of a minute article. */
export const minuteArticleTotal = (article: Pick<IArticle, 'ex_tax_price' | 'tax_percent'>, minutes: number): number =>
    lineTotalOre(article, minutes) / 100;

/** Hourly price incl. VAT of a minute article that has not been booked yet (the form). */
export const hourlyRateOfMinuteArticle = (article: Pick<IArticle, 'ex_tax_price' | 'tax_percent'>): number =>
    Math.round(toOre(article.ex_tax_price) * MINUTES_PER_HOUR * (100 + Number(article.tax_percent)) / 100) / 100;

/** A stored order line's total incl. VAT in kroner: its amount plus its tax, each already to the øre. */
export const orderLineTotal = (line: Pick<IOrderLine, 'amount' | 'tax'>): number =>
    (toOre(line.amount) + toOre(line.tax || 0)) / 100;

/**
 * The VAT % a stored order line was billed at. OrderLine does not carry it, so it is read back from the
 * line: tax / amount, rounded to a whole percent (the rates in use are whole). Null when there is no
 * amount to divide by. BOUND: a line under about 0.20 kr has a tax that rounds to 0, so it reads as 0 %,
 * and a line whose stored tax contradicts its tax code reads as what was actually stored.
 */
export const taxPercentOfOrderLine = (line: Pick<IOrderLine, 'amount' | 'tax'>): number | null => {
    const amount = Number(line.amount);
    if (!(amount > 0)) return null;
    return Math.round(100 * Number(line.tax || 0) / amount);
};

/**
 * Hourly price incl. VAT of a booked minute line, worked out exactly as the form works it out for an
 * unbooked one (hourlyRateOfMinuteArticle): the stored ex-VAT minute price x 60 x (1 + VAT%), rounded
 * once. Not (amount + tax) / quantity x 60: the line's tax is rounded to the øre, so that drifts by an
 * øre for an odd-øre price (3.33 ex at 25 % over 30 min gives 249.76 against the form's 249.75).
 * unit_price is the billed ex-VAT price on every path that writes a line, an officer's manual price
 * included. The VAT % is read back from the line (taxPercentOfOrderLine). Null when it cannot be.
 */
export const hourlyRateOfOrderLine = (line: Pick<IOrderLine, 'unit_price' | 'amount' | 'tax'>): number | null => {
    const taxPercent = taxPercentOfOrderLine(line);
    if (taxPercent === null || !(Number(line.unit_price) > 0)) return null;
    return hourlyRateOfMinuteArticle({ex_tax_price: String(line.unit_price), tax_percent: taxPercent});
};

/**
 * The unit price incl. VAT of a stored order line, to the øre, as the form shows it. A free line is 0.
 * Null when the VAT % cannot be read back (no amount to divide by): the caller shows «-», because the
 * stored unit price is ex-VAT and must not stand in for an incl. price.
 */
export const unitPriceInclOfOrderLine = (line: Pick<IOrderLine, 'unit_price' | 'amount' | 'tax'>): number | null => {
    if (Number(line.unit_price) === 0) return 0;
    const taxPercent = taxPercentOfOrderLine(line);
    if (taxPercent === null) return null;
    return Math.round(toOre(line.unit_price) * (100 + taxPercent) / 100) / 100;
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
