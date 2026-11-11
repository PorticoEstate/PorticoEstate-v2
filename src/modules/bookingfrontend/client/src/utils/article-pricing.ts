import {DateTime} from 'luxon';
import {IArticle} from '@/service/types/api/order-articles.types';
import {IOrder, IOrderLine} from '@/service/types/api/application.types';
import {IHospitalityArticle, IHospitalityOrder, IHospitalityOrderLine} from '@/service/types/api/hospitality.types';
import {formatAmount, formatCurrency} from '@/utils/cost-utils';
import {cateringBillingNotRuledOut, showsPriceSummary} from '@/utils/catering-status';

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

/**
 * Articles priced per day (unit 'day', or its alias 'dag') and per piece (unit 'each').
 *
 * A day line bills the ELAPSED 24-hour periods of the booking, started ones counted, at least one:
 * the rule of the legacy forms (purchase_order_add.js:248, purchase_order_edit.js:296,
 * `Math.ceil((to - from) / one_day)` with one_day = 86 400 000 ms). It counts elapsed time, not calendar
 * dates: 22:00-02:00 is 1 day, Mon 10:00 to Wed 10:00 is 2, and across the October clock change Sat 10:00
 * to Sun 10:00 is 25 hours = 2 days (a consequence of the legacy rule, kept). An each line bills 1 per
 * booked period. The server stores the quantity it is sent (ArticleRepository::savePurchaseOrderLine).
 */
const MS_PER_DAY = 24 * 60 * 60 * 1000;

export const isDayUnit = (unit?: string | null): boolean => unit === 'day' || unit === 'dag';

export const isEachUnit = (unit?: string | null): boolean => unit === 'each';

/** Started 24-hour periods between start and end, at least 1. Without both times the default is one day, like the hourly path. */
export const bookedDays = (start?: Date, end?: Date): number => {
    if (!start || !end) return 1;
    return Math.max(1, Math.ceil((end.getTime() - start.getTime()) / MS_PER_DAY));
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

/** A stored order line's total incl. VAT in whole øre: its amount plus its tax, each already to the øre. */
export const orderLineTotalOre = (line: Pick<IOrderLine, 'amount' | 'tax'>): number =>
    toOre(line.amount) + toOre(line.tax || 0);

/** A stored order line's total incl. VAT in kroner (orderLineTotalOre / 100). */
export const orderLineTotal = (line: Pick<IOrderLine, 'amount' | 'tax'>): number =>
    orderLineTotalOre(line) / 100;

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

const QUANTITY_FORMAT = new Intl.NumberFormat('nb-NO', {maximumFractionDigits: 2, useGrouping: false});

/**
 * A stored quantity for showing, from the number or the decimal string the API sends ("3.00"): a whole quantity
 * reads «3», a stored fraction keeps at most two decimals and the comma of the amounts beside it («2,5»). Never
 * grouped («1800») and never rounded to a whole number. nb-NO whatever the UI language, like formatCurrency, so
 * «2,5 × 81,25 kr» has one decimal separator. A value with nothing to show (null, undefined, blank, not a
 * number) is '', never a made-up «0».
 */
export const formatQuantity = (value: number | string | null | undefined): string => {
    if (value === null || value === undefined) return '';
    if (typeof value === 'string' && value.trim() === '') return '';
    const quantity = Number(value);
    return Number.isFinite(quantity) ? QUANTITY_FORMAT.format(quantity) : '';
};

/**
 * A resource line's quantity with its unit: «1 dag» / «2 dager» for a day line, «1 stk» for an each line
 * (the existing bookingfrontend.day, .days and .each labels, lowercased). Every other unit keeps the bare
 * number, as hour lines always have.
 */
export const formatQuantityWithUnit = (quantity: number, unit: string | null | undefined, t: Translate): string => {
    if (isDayUnit(unit)) {
        return `${quantity}${NBSP}${t(quantity === 1 ? 'bookingfrontend.day' : 'bookingfrontend.days').toLowerCase()}`;
    }
    if (isEachUnit(unit)) {
        return `${quantity}${NBSP}${t('bookingfrontend.each').toLowerCase()}`;
    }
    return String(quantity);
};

/** The existing label key of each article mapping unit code (ArticleMappingController::VALID_UNITS, plus the 'dag' alias). */
const UNIT_LABEL_KEYS = new Map<string, string>([
    ['kg', 'bookingfrontend.kg'],
    ['m', 'bookingfrontend.m'],
    ['m2', 'bookingfrontend.m2'],
    ['minute', 'bookingfrontend.minute'],
    ['hour', 'bookingfrontend.hour'],
    ['day', 'bookingfrontend.day'],
    ['dag', 'bookingfrontend.day'],
]);

/**
 * A catering article's unit as the applicant reads it, or null for no unit text. Never the raw unit code:
 * 'each' has none (a price is per piece unless it says otherwise), a known code has its existing label,
 * lowercased like formatQuantityWithUnit («kg»), and an unknown code has none.
 */
export const hospitalityUnitLabel = (unit: string | null | undefined, t: Translate): string | null => {
    const key = unit ? UNIT_LABEL_KEYS.get(unit) : undefined;
    return key ? t(key).toLowerCase() : null;
};

/*
 * Price summary: the sum ex VAT, the VAT and the total incl. VAT of a set of stored lines, on the SAME basis
 * as billing: every line is worked out to the øre on its own (never the VAT of a sum), and the totals are
 * sums of those whole-øre parts, so ex + VAT = incl to the øre by construction.
 *   - an application order line (IOrderLine) is stored as amount (ex) + tax (the VAT, to the øre);
 *   - a catering line (IHospitalityOrderLine) is stored ex and gets tax = amount x tax_percent / 100, once
 *     per line (inclOre), exactly as hospitalityLineTotalOre.
 * An unknown VAT is null and stays null all the way to the screen («-»): never 0.
 */

/** One stored line reduced to what a summary needs. */
export interface PricedLine {
    /** Ex-VAT amount in whole øre; null when it cannot be read. */
    exOre: number | null;
    /** The VAT in whole øre; null when it is not known (never 0 for «unknown»). */
    vatOre: number | null;
    /** The VAT % the line was billed at; null when it is not known (the VAT amount may still be). */
    percent: number | null;
}

/**
 * A stored application order line. The VAT AMOUNT is the stored one (`tax`, as billed); the percent only LABELS
 * it and is the line's `tax_percent` (fm_ecomva.percent_ of its tax code), as the server sends it: nothing is
 * inferred from tax / amount. fm_ecomva holds the code's rate TODAY while the tax was stored when the line was
 * billed, so the label is printed only when that rate gives the stored tax back by the billing rule (a 25 % code
 * on a line with a VAT of 0.00 would read «Mva 25 %: 0 kr»). No percent (a code with no fm_ecomva row, a payload
 * without the field, or a rate the stored tax contradicts) puts the line's VAT, still summed, under the plain
 * «Mva» row; it is never labelled 0 %.
 */
export const pricedOrderLine = (line: Pick<IOrderLine, 'amount' | 'tax' | 'tax_percent'>): PricedLine => {
    const exOre = toOre(line.amount);
    const vatOre = toOre(line.tax || 0);
    const percent = knownPercent(line.tax_percent);
    return {exOre, vatOre, percent: percent !== null && inclOre(exOre, percent) - exOre === vatOre ? percent : null};
};

/** A stored catering line: ex-VAT amount and tax_percent as sent. An unknown percent leaves the VAT null. */
export const pricedHospitalityLine = (line: Pick<IHospitalityOrderLine, 'amount' | 'tax_percent'>): PricedLine => {
    const exOre = exOreOrNull(line.amount);
    const percent = knownPercent(line.tax_percent);
    return {
        exOre,
        percent,
        vatOre: exOre === null || percent === null ? null : inclOre(exOre, percent) - exOre,
    };
};

/** The VAT of one rate in a summary; `percent` null = VAT that is stored but whose percent is not known. */
export interface PriceSummaryRate {
    percent: number | null;
    exOre: number;
    vatOre: number;
}

export interface PriceSummary {
    /** Σ ex-VAT; null if any line's amount is unreadable. */
    exOre: number | null;
    /** Σ VAT; null as soon as one line's VAT is unknown. */
    vatOre: number | null;
    /** exOre + vatOre; null if either is. */
    inclOre: number | null;
    /** VAT per rate, highest first, the one without a percent last; empty while vatOre is null. */
    rates: PriceSummaryRate[];
}

/**
 * Sum a set of lines. Lines that cost nothing (ex 0 and VAT 0) take part in the sums but make no rate row.
 * The rates are only listed while every VAT is known, so that the rows always add up to `vatOre`.
 */
export const summarisePricedLines = (lines: readonly PricedLine[]): PriceSummary => {
    const exOre = sumOreOrNull(lines.map(line => line.exOre));
    const vatOre = sumOreOrNull(lines.map(line => line.vatOre));

    const byPercent = new Map<number | null, PriceSummaryRate>();
    if (exOre !== null && vatOre !== null) {
        for (const line of lines) {
            if (line.exOre === 0 && line.vatOre === 0) continue;
            const rate = byPercent.get(line.percent) ?? {percent: line.percent, exOre: 0, vatOre: 0};
            rate.exOre += line.exOre ?? 0;
            rate.vatOre += line.vatOre ?? 0;
            byPercent.set(line.percent, rate);
        }
    }
    const rates = [...byPercent.values()].sort((a, b) =>
        a.percent === null ? 1 : b.percent === null ? -1 : b.percent - a.percent);

    return {exOre, vatOre, inclOre: exOre === null || vatOre === null ? null : exOre + vatOre, rates};
};

/**
 * The category rows of the application price summary (room rental, equipment and services, catering) without
 * the ones that are 0 kr: an application with no stored lines has no «Romleie 0 kr». An UNKNOWN amount (null,
 * shown «-») is not 0 and stays. Only these rows go: the VAT rows and the total are not in this list, so a
 * non-zero total never loses a VAT row (a real «Mva 0 %: 0 kr» included) or itself.
 */
export const withoutZeroParts = <T extends {ore: number | null}>(parts: readonly T[]): T[] =>
    parts.filter(part => part.ore !== 0);

/** Article category of a stored order line (IOrderLine.article_cat_id): 1 = resource, i.e. the room rental. */
const ROOM_RENTAL_CATEGORY = 1;

/**
 * The stored order lines of an application split the way the price summary splits them: the room rental
 * (resource lines) and the equipment and services (every other line). The page lists them under those two
 * headings, so the lists and the summary can never disagree about which line is which.
 */
export const splitStoredLines = (
    orders: ReadonlyArray<Pick<IOrder, 'lines'>>
): {rental: IOrderLine[]; services: IOrderLine[]} => {
    const lines = orders.flatMap(order => order.lines);
    return {
        rental: lines.filter(line => line.article_cat_id === ROOM_RENTAL_CATEGORY),
        services: lines.filter(line => line.article_cat_id !== ROOM_RENTAL_CATEGORY),
    };
};

/** True when every line is known to cost nothing (ex 0 and VAT 0); a list with no line is not «free», it is empty. */
export const storedLinesAreFree = (lines: ReadonlyArray<Pick<IOrderLine, 'amount' | 'tax' | 'tax_percent'>>): boolean =>
    lines.length > 0 && lines.every(line => {
        const priced = pricedOrderLine(line);
        return priced.exOre === 0 && priced.vatOre === 0;
    });

/** The total incl. VAT of stored lines in whole øre, as the price summary sums it (VAT per line, as billed). */
export const storedLinesInclOre = (lines: ReadonlyArray<Pick<IOrderLine, 'amount' | 'tax' | 'tax_percent'>>): number | null =>
    summarisePricedLines(lines.map(pricedOrderLine)).inclOre;

/**
 * The application's price in its parts: the room rental (resource lines) and the equipment and services
 * (every other stored line) from `orders`, the catering from the catering orders, and the whole.
 */
export const applicationPriceSummary = (
    orders: ReadonlyArray<Pick<IOrder, 'lines'>>,
    cateringOrders: ReadonlyArray<Pick<IHospitalityOrder, 'lines'>>
): {rental: PriceSummary; services: PriceSummary; catering: PriceSummary; total: PriceSummary} => {
    const stored = splitStoredLines(orders);
    const rental = stored.rental.map(pricedOrderLine);
    const services = stored.services.map(pricedOrderLine);
    const catering = cateringOrders.flatMap(order => order.lines.map(pricedHospitalityLine));
    return {
        rental: summarisePricedLines(rental),
        services: summarisePricedLines(services),
        catering: summarisePricedLines(catering),
        total: summarisePricedLines([...rental, ...services, ...catering]),
    };
};

/** What is known of the application's catering: `pending` (its orders are being read), `read` (the orders, possibly none, or catering is off) or `unreadable` (the read failed). */
export type CateringState = 'pending' | 'read' | 'unreadable';

export interface ClosingTotal {
    /** `combined`: the application's total, there being no catering. `registered`: every stored line added up, what the application has registered. */
    kind: 'combined' | 'registered';
    /** Whole øre incl. VAT; null = unknown («-»). */
    ore: number | null;
}

export interface ApplicationTotals {
    /** The «Utstyr og tjenester» card's own total. */
    showServicesTotal: boolean;
    /** The row that closes the price cards, or null. */
    closing: ClosingTotal | null;
}

/**
 * What the application page's price cards (Romleie / Utstyr og tjenester) total, in one place (GH #1423).
 *
 *  - The «Utstyr og tjenester» total is shown once billing is settled (the catering is read, or cannot be) and no
 *    catering order can be a billed one: a billed order is also a stored line (cateringBillingNotRuledOut).
 *  - The row that closes the cards is the application's total, and it is there exactly when nothing else gives it:
 *    not the price summary (showsPriceSummary, drawn only for a catering that was read), not the Romleie total (there
 *    is no add-on line, so that IS the sum) and not the add-ons' own total (when there is no Romleie card). So it
 *    closes two cards, or one card whose total is withheld because billing cannot be ruled out. It is
 *      `combined` («Total inkl. mva») when the catering was read and there is none, and
 *      `registered` («Registrert total inkl. mva.») otherwise, i.e. with catering whose billing cannot be ruled out
 *      or with a catering that could not be read: every stored line added up, per line in øre, which is what the
 *      server's `order.sum` adds up and what the old «Pris og betaling» block ended on. It is not called the total
 *      price: a catering order that is not billed yet is not among the stored lines.
 *  - While the catering is being read nothing is decided: no total of either kind.
 */
export const applicationTotals = (
    orders: ReadonlyArray<Pick<IOrder, 'lines'>>,
    cateringOrders: ReadonlyArray<Pick<IHospitalityOrder, 'actions' | 'lines'>>,
    catering: CateringState
): ApplicationTotals => {
    if (catering === 'pending') return {showServicesTotal: false, closing: null};
    const showServicesTotal = !cateringBillingNotRuledOut(cateringOrders);

    const {rental, services} = splitStoredLines(orders);
    const showRental = rental.length > 0 && !storedLinesAreFree(rental);
    // A catering that could not be read is not drawn, whatever an earlier read left in the query's data.
    const summaryDrawn = catering === 'read' && showsPriceSummary(cateringOrders, applicationPriceSummary(orders, cateringOrders).total.inclOre);
    const totalIsGiven = summaryDrawn || services.length === 0 || (!showRental && showServicesTotal);
    if (totalIsGiven) return {showServicesTotal, closing: null};

    const noCatering = catering === 'read' && cateringOrders.length === 0;
    return {
        showServicesTotal,
        closing: {kind: noCatering ? 'combined' : 'registered', ore: storedLinesInclOre(orders.flatMap(order => order.lines))},
    };
};
