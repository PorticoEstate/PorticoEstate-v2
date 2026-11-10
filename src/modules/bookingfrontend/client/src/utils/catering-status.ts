/**
 * Application statuses in which Min side shows catering READ-ONLY (GH #1423): the orders already
 * placed stay on view, but nothing is offered: no "Legg til bevertning", no Rediger / Avbestill, no
 * deadline hints. The server refuses every write on these with `application_closed`
 * (HospitalityOrderGuard); this only stops the page from presenting something the applicant can no
 * longer act on.
 *
 * A deny-list on purpose, kept apart from the component so it is one line to change: an unknown,
 * lower-case or missing status keeps today's behaviour (the section is shown, and the server still
 * decides what is allowed). `status` is a plain string in IApplication, so a misspelt status here
 * compiles, which is why tests/hospitality-deadline/run_closed.mts checks the values themselves.
 */
export const CATERING_CLOSED_APPLICATION_STATUSES: readonly string[] = ['REJECTED', 'CANCELLED'];

export const isCateringClosed = (status: string | null | undefined): boolean =>
    status !== null && status !== undefined && CATERING_CLOSED_APPLICATION_STATUSES.includes(status);

/**
 * Whether the catering section is shown at all. `orderCount` is the orders that are still on (a
 * cancelled order is not counted), `hospitalityCount` what is offered for the booked resources.
 *
 * A section is shown for an order, or for something to add. A read-only application has nothing to
 * add, so only its orders bring the section: no orders means no section, whatever is on offer.
 */
export const showsCateringSection = (
    status: string | null | undefined,
    orderCount: number,
    hospitalityCount: number,
): boolean => orderCount > 0 || (!isCateringClosed(status) && hospitalityCount > 0);

/**
 * Refusals that say nothing about whether an order has been BILLED. HospitalityOrderGuard checks, in this
 * order: the application's status, the order's own status (delivered / cancelled), billed, paid, and only then
 * the deadlines. So `order_billed` is billed, `application_closed` and `order_status` hide it, and every other
 * refusal was decided after the billed check passed.
 */
const BILLED_NOT_VISIBLE_REASONS: readonly string[] = ['order_billed', 'order_status', 'application_closed'];

/**
 * Is this order PROVEN not to be billed, from what the server says may be done with it? A billed order is
 * also a line of the application's stored order lines (the billing job turns it into a purchase order), so a
 * price summary that adds the stored lines AND the catering orders would count it twice. The server sends no
 * `billed` flag, only `actions`: an allowed action, or a refusal decided after the billed check, proves the
 * order is not billed; billed, delivered, a closed application and a missing `actions` cannot rule it out.
 * Both actions are held to it (they pass the same earlier checks, so they agree; if they ever do not, the
 * answer is «not proven»).
 */
export const isProvablyNotBilled = (
    actions: {change: {allowed: boolean; reason: string | null}; cancel: {allowed: boolean; reason: string | null}} | null | undefined,
): boolean =>
    !!actions && [actions.change, actions.cancel].every(
        action => action.allowed || (action.reason !== null && !BILLED_NOT_VISIBLE_REASONS.includes(action.reason)),
    );

/**
 * Can billing NOT be ruled out for these catering orders (the «D1» rule)? True as soon as ONE order is not proven
 * not billed (isProvablyNotBilled). No orders at all = nothing that could be billed = false.
 */
export const cateringBillingNotRuledOut = (
    orders: ReadonlyArray<{actions?: Parameters<typeof isProvablyNotBilled>[0]}>,
): boolean => orders.some(order => !isProvablyNotBilled(order.actions));

/**
 * Is the price summary (Prissammendrag) shown? THE one statement, used by the catering overview, which draws the
 * summary, and by the application's price lines (applicationTotals), which close with a total of their own exactly
 * when it is not drawn, so the two cannot drift apart. It is shown when
 *   - there is at least one (not cancelled) catering order: the summary is the catering's;
 *   - billing is ruled out for ALL of them (D1): a billed order is also a stored line, so the summary, which adds the
 *     stored lines and the catering orders, would count it twice;
 *   - and the application's total is not 0 kr (an unknown total, null, shown «-», is not 0).
 * `totalInclOre` is the summary's own total: applicationPriceSummary(stored orders, these orders).total.inclOre.
 */
export const showsPriceSummary = (
    cateringOrders: ReadonlyArray<{actions?: Parameters<typeof isProvablyNotBilled>[0]}>,
    totalInclOre: number | null,
): boolean =>
    cateringOrders.length > 0
    && !cateringBillingNotRuledOut(cateringOrders)
    && (totalInclOre === null || totalInclOre > 0);

/**
 * How the catering section ends (GH #1423, the Min side design A): the VAT breakdown is shown ONCE on the page.
 *   - `none`: no order, or the catering costs nothing (a 0 kr catering has nothing to sum; an unknown total, null, is
 *     not 0 and is shown);
 *   - `total`: the price summary is drawn after the section (`summaryDrawn` = showsPriceSummary), and it carries the sum
 *     ex VAT and the VAT, so the section ends with the one row «Bevertning total inkl. mva.»;
 *   - `breakdown`: the summary is not drawn (billing cannot be ruled out, or the application's total is 0 kr), so the
 *     section keeps its own sum ex VAT, VAT and total, the only place on the page that shows the catering's VAT.
 */
export type CateringEnding = 'none' | 'total' | 'breakdown';

export const cateringEnding = (orderCount: number, cateringInclOre: number | null, summaryDrawn: boolean): CateringEnding =>
    orderCount === 0 || (cateringInclOre !== null && cateringInclOre <= 0)
        ? 'none'
        : summaryDrawn ? 'total' : 'breakdown';
