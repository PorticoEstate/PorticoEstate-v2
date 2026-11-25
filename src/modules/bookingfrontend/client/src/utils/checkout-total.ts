/**
 * The checkout's totals with the catering in them, and the catering as the checkout lists it (GH #1478, operator D1).
 *
 * The total the checkout shows is the sum of what it lists:
 *   - each application's stored order lines (room rental and equipment / services) incl. VAT, as the cart has always
 *     counted them: the server's `order.sum` (Σ amount + tax per stored line), times the recurring instances for a
 *     recurring application;
 *   - every catering order of those applications that is not cancelled, line by line: the stored net `amount` plus
 *     the VAT at that line's OWN `tax_percent` (the VAT % of its own tax code, as the API sends it), rounded once per
 *     line (hospitalityLineTotalOre, the billing job's rule). Never a fixed rate, never the order's net
 *     `total_amount`. A catering order is ONE serving, so it is never multiplied by recurring instances.
 * Everything is added in whole øre and divided once, for showing.
 *
 * A line whose VAT % is not known makes the catering, and so the total, unknown (null, shown «-»): a total must not
 * hide a gap, and an ex-VAT amount is never shown in its place. So does a catering read that FAILED: the orders are
 * then not known, and a sum of the ones that were read would leave some out (cartCateringInput).
 *
 * This number is only SHOWN. The server works out what is charged by itself (ApplicationService::calculateTotalSum),
 * from nothing the client sends.
 *
 * Pure (no React, no DOM), so tests/checkout-total/run.mts can load it.
 */
import {IApplication} from '@/service/types/api/application.types';
import {IHospitalityOrder, IHospitalityOrderLine} from '@/service/types/api/hospitality.types';
import {CateringState, hospitalityLineTotalOre, hospitalityUnitPriceIncl, sumOreOrNull} from './article-pricing';
import {groupByServingDay} from './catering-venue-time';

type CateringOrderStatus = Pick<IHospitalityOrder, 'status'>;
type CateringOrderLines = Pick<IHospitalityOrder, 'status' | 'lines'>;

/** The catering orders the checkout lists and counts: every one that is not cancelled. */
export const activeCateringOrders = <T extends CateringOrderStatus>(orders: readonly T[]): T[] =>
    orders.filter(order => order.status !== 'cancelled');

/** One catering line incl. VAT in whole øre, as the checkout shows it and adds it; null when its VAT % is unknown. */
export const cateringLineOre = (line: Pick<IHospitalityOrderLine, 'amount' | 'tax_percent'>): number | null =>
    hospitalityLineTotalOre(line);

/** These catering orders incl. VAT in whole øre, the cancelled ones left out; null if one line's VAT is unknown. */
export const cateringTotalOre = (orders: readonly CateringOrderLines[]): number | null =>
    sumOreOrNull(activeCateringOrders(orders).flatMap(order => order.lines.map(cateringLineOre)));

/** An application's stored order lines incl. VAT in whole øre: the server's order sums, as the cart has always shown them. */
export const applicationStoredOre = (application: Pick<IApplication, 'orders'>): number =>
    (application.orders ?? []).reduce((sum, order) => sum + Math.round(Number(order.sum || 0) * 100), 0);

/**
 * What the cart knows of reading its applications' catering orders, from the state of each read: `unreadable` as soon
 * as ONE read failed (whatever the others are doing, the catering is not known), else `pending` while one is still
 * being read, else `read`. A read that failed on a refetch is `unreadable` too, though the query still holds the
 * orders it read before.
 */
export const cartCateringState = (reads: readonly {isError: boolean; isPending: boolean}[]): CateringState =>
    reads.some(read => read.isError) ? 'unreadable' : reads.some(read => read.isPending) ? 'pending' : 'read';

/**
 * The catering orders the cart's totals are worked out from, given what is known of reading them: null when a read
 * failed (the catering is unknown, so the total is: shown «-», never a sum that leaves some orders out), else the
 * orders. While they are still being read (`pending`) it is the orders read so far, and the cart shows no total at
 * all until they are all read. This is the ONE path from the cart's catering orders into a total.
 */
export const cartCateringInput = <T>(state: CateringState, orders: readonly T[]): readonly T[] | null =>
    state === 'unreadable' ? null : orders;

/**
 * What a set of the cart's applications costs incl. VAT in whole øre: their stored lines (times `instancesOf`, the
 * recurring instances, 1 for a single booking) plus the catering orders that belong to THESE applications. Null when
 * the catering is unknown: a line's VAT % is not known, or `cateringOrders` is null (cartCateringInput: a read failed).
 */
export const cartTotalOre = <A extends Pick<IApplication, 'id' | 'orders'>>(
    applications: readonly A[],
    cateringOrders: readonly (CateringOrderLines & Pick<IHospitalityOrder, 'application_id'>)[] | null,
    instancesOf: (application: A) => number,
): number | null => {
    if (cateringOrders === null) return null;
    const ids = new Set(applications.map(application => application.id));
    const stored = applications.reduce((sum, application) => sum + applicationStoredOre(application) * instancesOf(application), 0);
    const catering = cateringTotalOre(cateringOrders.filter(order => ids.has(order.application_id)));
    return catering === null ? null : stored + catering;
};

/** One catering line as the checkout lists it: «<quantity> × <unit price incl. VAT>» and the line's amount. */
export interface CheckoutCateringLine {
    line: IHospitalityOrderLine;
    /** The price per unit incl. VAT in kroner, rounded to the øre for showing; null when the VAT % is unknown. */
    unitIncl: number | null;
    /** The line incl. VAT in whole øre (cateringLineOre): what the total adds. Never quantity × unitIncl. */
    ore: number | null;
}

export interface CheckoutCateringOrder<T> {
    order: T;
    /** The venue wall-clock time of the serving, 'HH:mm'; null without one. */
    time: string | null;
    lines: CheckoutCateringLine[];
    /** The order incl. VAT in whole øre, the sum of its lines; null if one is unknown. */
    ore: number | null;
}

export interface CheckoutCateringDay<T> {
    /** 'YYYY-MM-DD' at the venue; null for the orders without a serving time (listed last). */
    dayKey: string | null;
    /** «Torsdag 12. november»; null for the orders without a serving time. */
    heading: string | null;
    orders: Array<CheckoutCateringOrder<T>>;
}

/**
 * The catering the checkout lists, per venue day with the earliest day first, each day by serving time (the grouping
 * of Min side, groupByServingDay), each order with its lines. Cancelled orders are not listed, as they are not
 * counted, so the total is always the sum of what is listed.
 */
export const checkoutCateringDays = <T extends Pick<IHospitalityOrder, 'id' | 'status' | 'serving_time_iso' | 'lines'>>(
    orders: readonly T[],
    lang: string,
    nowMs: number,
): Array<CheckoutCateringDay<T>> =>
    groupByServingDay(activeCateringOrders(orders), lang, nowMs).map(group => ({
        dayKey: group.dayKey,
        heading: group.heading,
        orders: group.items.map(({order, time}) => {
            const lines = order.lines.map(line => ({
                line,
                unitIncl: hospitalityUnitPriceIncl({effective_price: line.unit_price, effective_tax_percent: line.tax_percent}),
                ore: cateringLineOre(line),
            }));
            return {order, time, lines, ore: sumOreOrNull(lines.map(line => line.ore))};
        }),
    }));
