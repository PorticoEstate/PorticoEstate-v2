import {phpGWLink} from "@/service/util";

/**
 * The two Slim4 booking-cancellation endpoints, typed.
 *
 * Sibling of allocation-cancellation.ts, built the same way (see that file's docblock for why the
 * house `fetch` + `throw new Error(errorData.error)` shape is deliberately not used). The shape
 * differs from the allocation side in exactly the places BookingCancellationService departs from
 * AllocationCancellationService:
 *
 *   - occurrences carry `booking_id`, not `allocation_id`, as their primary key, and their status
 *     is 'cancellable' | 'no_booking' — there is no 'blocked_by_booking' state, because nothing
 *     ever prevents cancelling a booking once the #1210 guard has passed (only the caller's OWN
 *     booking is reachable at all).
 *   - every occurrence also names its own `allocation_would_cascade`, and the preview carries an
 *     aggregate `cascade` (allocation_ids/count) plus `split_pool` (active) — both disclosed
 *     regardless of whether delete_allocation was requested, so a caller can see the effect before
 *     opting in. `cascade.note` and `split_pool.effect` are dead English prose (server-authored,
 *     never rendered) — the client renders its own copy from the structured fields instead.
 *   - the destructive endpoint's request-mode 409 carries `user_can_delete_bookings: false`
 *     alongside `is_request_mode: true`; the discriminator used here is still `is_request_mode`,
 *     same as the allocation side.
 */

export type BookingCancelScope = 'occurrence' | 'season' | 'until';

export type BookingOccurrenceStatus = 'cancellable' | 'no_booking';

export interface IBookingCancelOccurrence {
	index: number;
	/** null exactly when status is 'no_booking' — no booking matched this week. */
	booking_id: number | null;
	from_: string;
	to_: string;
	/** The allocation this occurrence resolves to, if any — matched booking's parent, or a bare
	 *  (unbooked) allocation found the same way legacy's sobooking::check_for_booking fallback does. */
	allocation_id: number | null;
	status: BookingOccurrenceStatus;
	cancellable: boolean;
	reason: 'no_booking_at_this_time' | 'no_booking_bare_allocation_only' | null;
	/** Whether deleting this occurrence's booking would leave `allocation_id` with no other
	 *  booking under it — the per-occurrence half of the cascade the preview discloses in aggregate. */
	allocation_would_cascade: boolean;
	allocation_other_bookings: number | null;
}

export interface IBookingCancelCascade {
	allocation_ids: number[];
	count: number;
	/** Dead — server-authored English prose, never rendered by this client. See the file docblock. */
	note: string;
}

export interface IBookingCancelSplitPool {
	active: boolean;
	/** Dead — see IBookingCancelCascade.note. */
	effect: string;
}

export interface IBookingCancelPreview {
	booking_id: number;
	scope: BookingCancelScope;
	scope_resolved: {
		effective_repeat_until: string | null;
		field_interval: number;
		season_id: number;
		season_name: string | null;
		season_to: string | null;
	};
	group_id: number;
	group_name: string | null;
	organization_id: number | null;
	organization_name: string | null;
	building_name: string | null;
	resources: { id: number; name: string | null }[];
	delete_allocation_requested: boolean;
	cascade: IBookingCancelCascade;
	split_pool: IBookingCancelSplitPool;
	/** Every date the walk visited, INCLUDING dates with no booking at all. */
	total: number;
	cancellable: number;
	no_booking: number;
	occurrences: IBookingCancelOccurrence[];
	confirm_token: string;
}

export interface IBookingCancelResult {
	mode: 'deleted';
	booking_id: number;
	scope: BookingCancelScope;
	scope_resolved: IBookingCancelPreview['scope_resolved'];
	delete_allocation_requested: boolean;
	deleted_bookings: number[];
	deleted_bookings_count: number;
	deleted_allocations: number[];
	deleted_allocations_count: number;
	skipped: {
		booking_id: number | null;
		from_: string;
		to_: string;
		status: BookingOccurrenceStatus;
		reason: string | null;
	}[];
	skipped_count: number;
}

export interface IBookingCancelRequest {
	scope: BookingCancelScope;
	/** Required by the server iff scope is 'until'. */
	repeat_until?: string;
	field_interval?: number;
	/** Whether a cancel with this same body should also delete each occurrence's parent
	 *  allocation, where `allocation_would_cascade`. The preview discloses the cascade either way. */
	delete_allocation?: boolean;
	confirm_token?: string;
	/** Same unconsumed-today wiring note as IAllocationCancelRequest.message. */
	message?: string;
}

export class BookingCancellationError extends Error {
	readonly status: number;
	readonly payload: Record<string, unknown>;

	constructor(status: number, payload: Record<string, unknown>) {
		super(
			typeof payload?.error === 'string'
				? payload.error
				: `Booking cancellation failed with status ${status}`
		);
		this.name = 'BookingCancellationError';
		this.status = status;
		this.payload = payload ?? {};
	}

	/** Same `=== true` discipline as AllocationCancellationError.isRequestMode. */
	get isRequestMode(): boolean {
		return this.status === 409 && this.payload.is_request_mode === true;
	}

	/** A booking appeared or vanished under one of the occurrences between preview and confirm. */
	get isStaleToken(): boolean {
		return this.status === 409 && this.payload.is_request_mode !== true;
	}
}

async function postBookingCancellation<T>(
	bookingId: number,
	action: 'cancel-preview' | 'cancel',
	body: IBookingCancelRequest,
	secret?: string
): Promise<T> {
	const url = phpGWLink(
		['bookingfrontend', 'bookings', bookingId, action],
		secret ? {secret} : {}
	);

	const response = await fetch(url, {
		method: 'POST',
		credentials: 'include',
		headers: {'Content-Type': 'application/json'},
		body: JSON.stringify(body),
	});

	let payload: Record<string, unknown> = {};
	try {
		payload = await response.json();
	} catch {
		// A non-JSON body is itself the failure; the status still carries the meaning.
	}

	if (!response.ok) {
		throw new BookingCancellationError(response.status, payload);
	}

	return payload as T;
}

export function previewBookingCancellation(
	bookingId: number,
	body: IBookingCancelRequest,
	secret?: string
): Promise<IBookingCancelPreview> {
	return postBookingCancellation<IBookingCancelPreview>(bookingId, 'cancel-preview', body, secret);
}

export function cancelBooking(
	bookingId: number,
	body: IBookingCancelRequest,
	secret?: string
): Promise<IBookingCancelResult> {
	return postBookingCancellation<IBookingCancelResult>(bookingId, 'cancel', body, secret);
}

/**
 * The occurrences worth SHOWING individually, same "exclude only the truly-empty dates" rule
 * allocation's realOccurrences applies (there `status !== 'no_allocation'`). A booking occurrence
 * has no "exists but blocked" state, but it has an analogous split within 'no_booking': `reason
 * 'no_booking_at_this_time'` is a week with nothing there at all (excluded, counted by
 * emptyOccurrenceCount instead), while `'no_booking_bare_allocation_only'` is a week where a bare
 * (unbooked) allocation exists — a real thing the caller may want to see, same as allocation's
 * 'blocked_by_booking' rows.
 */
export function realBookingOccurrences(
	preview: IBookingCancelPreview
): IBookingCancelOccurrence[] {
	return preview.occurrences.filter(
		(o) => o.status === 'cancellable' || o.reason === 'no_booking_bare_allocation_only'
	);
}

/** The subset of `preview.no_booking` that is genuinely empty weeks, excluded from
 *  realBookingOccurrences and reported only as a footnote count. */
export function emptyBookingOccurrenceCount(preview: IBookingCancelPreview): number {
	return preview.occurrences.filter((o) => o.reason === 'no_booking_at_this_time').length;
}
