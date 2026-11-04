import {DateTime} from 'luxon';
import {IArticle} from '@/service/types/api/order-articles.types';
import {IOrderLine} from '@/service/types/api/application.types';
import {IHospitalityArticle, IHospitalityOrder, IHospitalityOrderLine} from '@/service/types/api/hospitality.types';
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
 * A line's ex-VAT amount in whole øre plus its VAT at `percent`, the tax rounded ONCE to the øre. The ONE
 * place the rounding rule lives: every line total incl. VAT in the client goes through it, and it is the
 * rule the server writes a line with (tax = amount x VAT% to the øre).
 */
export const inclOre = (exOre: number, percent: number): number =>
    exOre + Math.round(exOre * percent / 100);

/** A unit price incl. VAT in kroner, rounded to the øre for showing. Never multiply it: use inclOre per line. */
const unitPriceIncl = (exPrice: string | number, percent: number): number =>
    Math.round(toOre(exPrice) * (100 + percent) / 100) / 100;

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
export const lineTotalOre = (article: Pick<IArticle, 'ex_tax_price' | 'tax_percent'>, quantity: number): number =>
    inclOre(toOre(article.ex_tax_price) * quantity, Number(article.tax_percent));

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
    return unitPriceIncl(line.unit_price, taxPercent);
};

/*
 * Catering (hospitality). A catering order line is stored EX VAT (amount = quantity x unit_price, no tax
 * column) and billed by turning it into a purchase-order line with tax = amount x fm_ecomva.percent_ / 100
 * (async_task_update_reservation_state, create_purchase_orders_from_hospitality). The API sends that VAT %
 * beside every line (tax_percent) and every menu article (effective_tax_percent). NULL means «that tax code
 * has no fm_ecomva row» and is NOT 0 %: the helpers return null for it, and the caller shows «-» rather than
 * an ex-VAT amount in its place. Same rule as unitPriceInclOfOrderLine.
 */

const knownPercent = (percent: number | string | null | undefined): number | null => {
    if (percent === null || percent === undefined || percent === '') return null;
    const n = Number(percent);
    return Number.isFinite(n) ? n : null;
};

const exOreOrNull = (kroner: string | number | null | undefined): number | null => {
    if (kroner === null || kroner === undefined || kroner === '') return null;
    const n = Number(kroner);
    return Number.isFinite(n) ? toOre(n) : null;
};

/** The sum of øre amounts, or null as soon as one of them is unknown (a total must not hide a gap). */
export const sumOreOrNull = (values: Array<number | null>): number | null =>
    values.reduce<number | null>((sum, value) => (sum === null || value === null ? null : sum + value), 0);

/** An øre amount as kroner («1 500 kr»), or «-» when it is unknown (null): never an ex-VAT amount in its place. */
export const formatOreOrDash = (ore: number | null): string => (ore === null ? '-' : formatCurrency(ore / 100));

/** A stored catering line's total incl. VAT in øre: its stored ex-VAT amount plus its VAT, as billed. */
export const hospitalityLineTotalOre = (line: Pick<IHospitalityOrderLine, 'amount' | 'tax_percent'>): number | null => {
    const exOre = exOreOrNull(line.amount);
    const percent = knownPercent(line.tax_percent);
    return exOre === null || percent === null ? null : inclOre(exOre, percent);
};

/** A catering order's total incl. VAT in øre: the sum of its lines, each rounded once. Null if any line is unknown. */
export const hospitalityOrderTotalOre = (order: Pick<IHospitalityOrder, 'lines'>): number | null =>
    sumOreOrNull(order.lines.map(hospitalityLineTotalOre));

/** What `quantity` of a menu article costs incl. VAT in øre (ex price x quantity, then the VAT once, as billed). */
export const hospitalityMenuLineTotalOre = (
    article: Pick<IHospitalityArticle, 'effective_price' | 'effective_tax_percent'>,
    quantity: number
): number | null => {
    const exOre = exOreOrNull(article.effective_price);
    const percent = knownPercent(article.effective_tax_percent);
    return exOre === null || percent === null ? null : inclOre(exOre * quantity, percent);
};

/** A menu article's price per unit incl. VAT in kroner, to the øre, for showing. Null if its VAT % is unknown. */
export const hospitalityUnitPriceIncl = (
    article: Pick<IHospitalityArticle, 'effective_price' | 'effective_tax_percent'>
): number | null => {
    const exOre = exOreOrNull(article.effective_price);
    const percent = knownPercent(article.effective_tax_percent);
    return exOre === null || percent === null ? null : unitPriceIncl(exOre / 100, percent);
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

/**
 * The label to show with a chosen price. A bare amount is the option's EX-VAT price, so it is shown incl.
 * VAT, worked out from the line's own stored tax like its unit price (unitPriceInclOfOrderLine); on a minute
 * line it is the per-minute price and is not shown at all, and it is not shown when the VAT % cannot be read
 * back. A purely numeric label that is NOT the line's unit price is a typed remark and stays as typed.
 * BOUND: a typed remark that is a number equal to the line's ex-VAT unit price reads as the bare amount.
 */
export const priceLabelForDisplay = (
    line: Pick<IOrderLine, 'price_label' | 'unit' | 'unit_price' | 'amount' | 'tax' | 'currency'>
): string | null => {
    const label = line.price_label ?? null;
    if (label === null || !isAmountOnlyLabel(label)) return label;
    if (isMinuteUnit(line.unit)) return null;
    if (Number(label.trim().replace(',', '.')) !== Number(line.unit_price)) return label;
    const incl = unitPriceInclOfOrderLine(line);
    return incl === null ? null : formatCurrency(incl, line.currency);
};

/** «150 min» */
export const formatMinutes = (minutes: number, t: Translate): string =>
    `${minutes}${NBSP}${t('bookingfrontend.minutes_short')}`;
