import {phpGWLink} from "@/service/util";

/**
 * The two Slim4 allocation-cancellation endpoints, typed.
 *
 * These are deliberately NOT written with the house `fetch` + `throw new Error(errorData.error)`
 * shape the other POST helpers in api-utils.ts use. That shape discards the HTTP status, and this
 * flow has three different failures that all have to be told apart by the caller:
 *
 *   400  confirm_token missing, or an unusable scope/repeat_until/field_interval
 *   409  + is_request_mode:true  -> the installation does not permit direct deletion at all
 *   409  without that flag       -> the confirm_token is stale; the series changed under us
 *
 * The 409s are distinguished structurally, by the presence of `is_request_mode`, not by matching
 * the error text: AllocationController::cancel() emits `is_request_mode` on the request-mode
 * branch only, while the stale-token 409 comes from a RuntimeException carrying just `error`.
 * That flag is the only discriminator the payload offers.
 */

export type AllocationCancelScope = 'occurrence' | 'season' | 'until';

export type AllocationOccurrenceStatus =
	| 'cancellable'
	| 'blocked_by_booking'
	| 'no_allocation';

export interface IAllocationBlockingBooking {
	id: number;
	/** bb_booking.active as stored. 0 means the blocking booking is itself dead. */
	active: number;
	group_id: number | null;
	group_name: string | null;
	from_: string;
	to_: string;
}

export interface IAllocationCancelOccurrence {
	index: number;
	/** null exactly when status is 'no_allocation' — the series has no row that week. */
	allocation_id: number | null;
	from_: string;
	to_: string;
	status: AllocationOccurrenceStatus;
	cancellable: boolean;
	reason: string | null;
	blocking_bookings: IAllocationBlockingBooking[];
	/**
	 * Present ONLY on status 'blocked_by_booking'. False means every booking blocking this
	 * occurrence is inactive — the legacy rule blocks on the existence of any bb_booking row
	 * regardless of `active`, and that rule is unchanged, so this is the difference between a
	 * live block and a dead one.
	 */
	has_active_blocking_booking?: boolean;
}

export interface IAllocationCancelPreview {
	allocation_id: number;
	scope: AllocationCancelScope;
	scope_resolved: {
		effective_repeat_until: string;
		field_interval: number;
		season_id: number;
		season_name: string | null;
		season_to: string | null;
	};
	organization_id: number;
	organization_name: string | null;
	building_name: string | null;
	resources: { id: number; name: string | null }[];
	allocation_group_id: number | null;
	/** Every date the walk visited, INCLUDING dates with no allocation at all. */
	total: number;
	cancellable: number;
	blocked: number;
	no_allocation: number;
	occurrences: IAllocationCancelOccurrence[];
	confirm_token: string;
}

export interface IAllocationCancelResult {
	mode: 'deleted';
	allocation_id: number;
	scope: AllocationCancelScope;
	scope_resolved: IAllocationCancelPreview['scope_resolved'];
	deleted: number[];
	deleted_count: number;
	skipped: {
		allocation_id: number | null;
		from_: string;
		to_: string;
		status: AllocationOccurrenceStatus;
		reason: string | null;
		blocking_bookings: IAllocationBlockingBooking[];
	}[];
	skipped_count: number;
}

export interface IAllocationCancelRequest {
	scope: AllocationCancelScope;
	/** Required by the server iff scope is 'until'. */
	repeat_until?: string;
	field_interval?: number;
	confirm_token?: string;
	/**
	 * The design's "message to the building". NOTHING on the server consumes this today —
	 * neither AllocationController nor AllocationCancellationService nor the repository reads
	 * a `message` key. It is sent so the field is wired the moment a notification path exists,
	 * and the UI does not claim a message was delivered. See the modal's own note.
	 */
	message?: string;
}

export class AllocationCancellationError extends Error {
	readonly status: number;
	readonly payload: Record<string, unknown>;

	constructor(status: number, payload: Record<string, unknown>) {
		super(
			typeof payload?.error === 'string'
				? payload.error
				: `Allocation cancellation failed with status ${status}`
		);
		this.name = 'AllocationCancellationError';
		this.status = status;
		this.payload = payload ?? {};
	}

	/**
	 * The installation refuses direct deletion (booking_config.user_can_delete_allocations is
	 * not 'yes'). Gated on `=== true`, never on truthiness: the server sends a real JSON
	 * boolean here, and treating anything else as "on" is the bug class this whole flag family
	 * carries.
	 */
	get isRequestMode(): boolean {
		return this.status === 409 && this.payload.is_request_mode === true;
	}

	/** A booking appeared under one of the occurrences between the preview and the confirm. */
	get isStaleToken(): boolean {
		return this.status === 409 && this.payload.is_request_mode !== true;
	}
}

async function postAllocationCancellation<T>(
	allocationId: number,
	action: 'cancel-preview' | 'cancel',
	body: IAllocationCancelRequest,
	secret?: string
): Promise<T> {
	const url = phpGWLink(
		['bookingfrontend', 'allocations', allocationId, action],
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
		throw new AllocationCancellationError(response.status, payload);
	}

	return payload as T;
}

export function previewAllocationCancellation(
	allocationId: number,
	body: IAllocationCancelRequest,
	secret?: string
): Promise<IAllocationCancelPreview> {
	return postAllocationCancellation<IAllocationCancelPreview>(
		allocationId,
		'cancel-preview',
		body,
		secret
	);
}

export function cancelAllocation(
	allocationId: number,
	body: IAllocationCancelRequest,
	secret?: string
): Promise<IAllocationCancelResult> {
	return postAllocationCancellation<IAllocationCancelResult>(
		allocationId,
		'cancel',
		body,
		secret
	);
}

/**
 * The occurrences the series actually HAS.
 *
 * The server walks every date in the scope and reports dates with no allocation as
 * `no_allocation`, so `preview.total` counts calendar slots, not occurrences: a season-scoped
 * walk over a season that outlives the series returns one entry per remaining week forever.
 * The design's "N of M occurrences can be cancelled" means real occurrences, so M is derived
 * here rather than read from `total`.
 */
export function realOccurrences(
	preview: IAllocationCancelPreview
): IAllocationCancelOccurrence[] {
	return preview.occurrences.filter((o) => o.status !== 'no_allocation');
}

/** Blocked, and every booking blocking it is inactive. */
export function isDeadBlocked(occurrence: IAllocationCancelOccurrence): boolean {
	return (
		occurrence.status === 'blocked_by_booking' &&
		occurrence.has_active_blocking_booking !== true
	);
}
