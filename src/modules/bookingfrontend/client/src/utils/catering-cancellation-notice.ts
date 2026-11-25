/**
 * The cancellation notice of the checkout's catering section: «Kan avbestilles innen …» / «Avbestillingsfristen
 * har utløpt», worked out from each order's OWN serving time and never from the other applications in the cart.
 *
 * Per order the inputs are the ones the server's lock reads (HospitalityOrderController::actionsFor in
 * src/modules/bookingfrontend/controllers/applications/, not the booking/ controller of the same name):
 *   - the anchor S = the order's stored serving time, else the earliest date of the order's OWN application;
 *   - the kitchen's cancellation lead of the order's offer (and its open days), counted back from S with
 *     computeHospitalityDeadline, the function the order dialog uses.
 * An order with no basis for it is SKIPPED: it is neither «past» (the server fails closed with no_deadline_basis,
 * the notice just has nothing to say) nor measured from some other application. So is an order whose offer is not
 * sent (switched off), whose lead is not configured, and one in a final status, like the guard's
 * FINAL_ORDER_STATUSES. The cart holds drafts, which the guard never locks by the cancellation deadline: this
 * is a statement about what applies once the application is submitted.
 *
 * Several orders: the notice carries EVERY order whose deadline has passed (`past`) and EVERY order whose deadline is
 * still open with its date (`open`, soonest first), so the checkout can say which order each statement is about.
 * `soonestOpen` and `somePast` are the same facts in short. A past order never hides an open order's date.
 *
 * Pure (no React, no DOM, no translation), so tests/hospitality-deadline/run_cancellation_notice.mts can load it.
 */
import {computeHospitalityDeadline, earliestApplicationDate, isDeadlineOpen} from './hospitality-deadline';

/** Order statuses nothing can be cancelled from any more (HospitalityOrderGuard::FINAL_ORDER_STATUSES). */
export const FINAL_ORDER_STATUSES: readonly string[] = ['cancelled', 'delivered'];

export interface NoticeOrder {
    id: number;
    application_id: number;
    hospitality_id: number;
    status: string;
    serving_time_iso?: string | null;
}

/** The fields of an offer (IHospitality) the cancellation deadline is built from. */
export interface NoticeOffer {
    id: number;
    resource_cancellation_deadline_value: number | null;
    resource_cancellation_deadline_unit: string | null;
    open_days_list?: number[] | null;
}

export interface NoticeApplication {
    id: number;
    dates?: ReadonlyArray<{from_: string}> | null;
}

/** A stored serving time as a Date; none, or one that cannot be read, is «no serving time». */
function servingTimeOf(iso: string | null | undefined): Date | null {
    if (!iso) return null;
    const date = new Date(iso);
    return Number.isFinite(date.getTime()) ? date : null;
}

/**
 * The instant an order's deadline counts back from, and the date the checkout names the order by: its stored
 * serving time, else the earliest date of its OWN application; null when there is neither.
 */
export function orderAnchor(
    order: NoticeOrder,
    applications: ReadonlyArray<NoticeApplication>,
): Date | null {
    return servingTimeOf(order.serving_time_iso)
        ?? earliestApplicationDate(applications.find(application => application.id === order.application_id));
}

/**
 * The last instant this order can be cancelled, or null when the notice has nothing to say about it
 * (no basis, offer not sent, no lead configured, final status).
 */
export function orderCancelBy(
    order: NoticeOrder,
    offers: ReadonlyArray<NoticeOffer>,
    applications: ReadonlyArray<NoticeApplication>,
): Date | null {
    if (FINAL_ORDER_STATUSES.includes(order.status)) return null;

    const offer = offers.find(candidate => candidate.id === order.hospitality_id);
    if (!offer) return null;

    const anchor = orderAnchor(order, applications);
    if (anchor === null || !Number.isFinite(anchor.getTime())) return null;

    const cancelBy = computeHospitalityDeadline(
        anchor,
        offer.resource_cancellation_deadline_value,
        offer.resource_cancellation_deadline_unit,
        offer.open_days_list,
    );
    return cancelBy !== null && Number.isFinite(cancelBy.getTime()) ? cancelBy : null;
}

export interface CancellationNotice<T extends NoticeOrder = NoticeOrder> {
    /** The soonest cancellation deadline that is still open; null when every deadline has passed. */
    soonestOpen: Date | null;
    /** At least one order's own deadline has passed. */
    somePast: boolean;
    /** EVERY order whose own deadline has passed, by order id. */
    past: T[];
    /** EVERY order whose deadline is still open, with that deadline: soonest first, equal deadlines by order id. */
    open: Array<{order: T; cancelBy: Date}>;
}

/** null = nothing to say (no order has a deadline). `now` is an instant or epoch milliseconds. */
export function cancellationNotice<T extends NoticeOrder>(
    orders: ReadonlyArray<T>,
    offers: ReadonlyArray<NoticeOffer>,
    applications: ReadonlyArray<NoticeApplication>,
    now: Date | number,
): CancellationNotice<T> | null {
    let soonestOpen: Date | null = null;
    let somePast = false;
    const past: T[] = [];
    const open: Array<{order: T; cancelBy: Date}> = [];
    for (const order of orders) {
        const cancelBy = orderCancelBy(order, offers, applications);
        if (cancelBy === null) continue;
        if (!isDeadlineOpen(now, cancelBy)) {
            somePast = true;
            past.push(order);
        } else {
            open.push({order, cancelBy});
            if (soonestOpen === null || cancelBy < soonestOpen) {
                soonestOpen = cancelBy;
            }
        }
    }
    past.sort((a, b) => a.id - b.id);
    open.sort((a, b) => a.cancelBy.getTime() - b.cancelBy.getTime() || a.order.id - b.order.id);
    return soonestOpen === null && !somePast ? null : {soonestOpen, somePast, past, open};
}
