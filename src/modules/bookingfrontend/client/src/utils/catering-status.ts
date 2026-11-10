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

