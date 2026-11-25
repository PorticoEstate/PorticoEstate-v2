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
 * hide a gap, and an ex-VAT amount is never shown in its place.
 *
 * This number is only SHOWN. The server works out what is charged by itself (ApplicationService::calculateTotalSum),
 * from nothing the client sends.
 *
 * Pure (no React, no DOM), so tests/checkout-total/run.mts can load it.
 */
import {IApplication} from '@/service/types/api/application.types';
import {IHospitalityOrder, IHospitalityOrderLine} from '@/service/types/api/hospitality.types';
import {hospitalityLineTotalOre, hospitalityUnitPriceIncl, sumOreOrNull} from './article-pricing';
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
 * What a set of the cart's applications costs incl. VAT in whole øre: their stored lines (times `instancesOf`, the
 * recurring instances, 1 for a single booking) plus the catering orders that belong to THESE applications. Null when
 * the catering is unknown.
 */
export const cartTotalOre = <A extends Pick<IApplication, 'id' | 'orders'>>(
    applications: readonly A[],
    cateringOrders: readonly (CateringOrderLines & Pick<IHospitalityOrder, 'application_id'>)[],
    instancesOf: (application: A) => number,
): number | null => {
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
