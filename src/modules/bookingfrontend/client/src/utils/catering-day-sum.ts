/**
 * The catering of one venue day as the checkout sums and names it (GH #1478, unit C2).
 *
 * A day's sum is the sum of its orders' amounts incl. VAT in whole øre, the same per-order amounts the checkout's total
 * adds (checkout-total.ts: cateringTotalOre). So the sums of a checkout's days foot to that total exactly. A day holding
 * an order whose VAT % is unknown has no sum (null, shown «-»), as the total has none.
 *
 * Pure (no React, no DOM), so tests/catering-day-sums/run.mts can load it.
 */
import {sumOreOrNull} from './article-pricing';
import {localeFor} from './hospitality-deadline';

/** What a day's sum reads: each of its orders' amount incl. VAT in øre (CheckoutCateringOrder.ore). */
export interface CateringDaySumInput {
    orders: ReadonlyArray<{ore: number | null}>;
}

/** The day's catering incl. VAT in whole øre; null when one of its orders' VAT % is unknown. */
export const cateringDaySumOre = (day: CateringDaySumInput): number | null =>
    sumOreOrNull(day.orders.map(order => order.ore));

/**
 * The date of a venue day, 'YYYY-MM-DD', as the day's sum names it: «22. april» (no weekday, as the design's «Sum
 * bevertning 22. april»). Built in UTC from the calendar key, so no zone can move it to a neighbouring day.
 */
export const cateringDaySumDate = (dayKey: string, lang: string): string => {
    const [year, month, day] = dayKey.split('-').map(Number);
    return new Intl.DateTimeFormat(localeFor(lang), {day: 'numeric', month: 'long', timeZone: 'UTC'})
        .format(new Date(Date.UTC(year, month - 1, day)));
};

/** The names of a building's offers as one line: «Kantina», «Kantina og Spiseriet», «A, B og C» in the UI language. */
export const cateringOffersText = (names: readonly string[], lang: string): string =>
    new Intl.ListFormat(localeFor(lang), {style: 'long', type: 'conjunction'}).format([...new Set(names)]);
