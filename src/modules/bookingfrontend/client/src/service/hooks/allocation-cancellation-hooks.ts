import {useMutation, useQueryClient} from "@tanstack/react-query";
import {
	AllocationCancellationError,
	cancelAllocation,
	IAllocationCancelPreview,
	IAllocationCancelRequest,
	IAllocationCancelResult,
	IAllocationWithdrawResult,
	previewAllocationCancellation,
	withdrawAllocationTimeslot,
} from "@/service/api/allocation-cancellation";

interface AllocationCancellationVariables {
	allocationId: number;
	body: IAllocationCancelRequest;
	secret?: string;
}

/**
 * The preview step.
 *
 * A mutation rather than a query even though the server treats it as read-only: it is a POST
 * carrying a scope body, it must never be served from cache (its whole purpose is to report the
 * series as it is RIGHT NOW), and it is re-run deliberately — including as the recovery from a
 * stale confirm_token.
 */
export function useAllocationCancelPreview() {
	return useMutation<IAllocationCancelPreview, AllocationCancellationError, AllocationCancellationVariables>({
		mutationFn: ({allocationId, body, secret}) =>
			previewAllocationCancellation(allocationId, body, secret),
		retry: false,
	});
}

/**
 * The destructive step.
 *
 * `retry: false` is load-bearing, not a default worth changing: the failure this call is most
 * likely to hit is a 409 stale confirm_token, and the correct response to that is to re-run the
 * PREVIEW and let the user look at the changed series again — never to repeat the cancel. A
 * retry here would re-submit a token the server has already told us is stale.
 *
 * On success both calendar caches for the building are invalidated. The rows are hard-deleted,
 * so a stale cache would keep drawing allocations that no longer exist.
 */
export function useAllocationCancel(buildingId?: number) {
	const queryClient = useQueryClient();

	return useMutation<IAllocationCancelResult, AllocationCancellationError, AllocationCancellationVariables>({
		mutationFn: ({allocationId, body, secret}) =>
			cancelAllocation(allocationId, body, secret),
		retry: false,
		onSuccess: () => {
			if (buildingId === undefined) {
				return;
			}
			queryClient.invalidateQueries({queryKey: ['buildingSchedule', buildingId]});
			queryClient.invalidateQueries({queryKey: ['buildingFreeTime', buildingId]});
		},
	});
}

interface AllocationWithdrawVariables {
	allocationId: number;
	applicationId: number;
	comment: string;
	secret?: string;
}

/**
 * Min side single-timeslot withdrawal. On success, the withdrawn occurrence's row is still there
 * (soft-deactivated) but no longer active=1, so it drops out of the application's reserved-times
 * list; the comment it left is visible in the same application's comment thread. Both caches are
 * invalidated by application id, not building id - this is Min side, not the calendar.
 */
export function useAllocationWithdraw() {
	const queryClient = useQueryClient();

	return useMutation<IAllocationWithdrawResult, AllocationCancellationError, AllocationWithdrawVariables>({
		mutationFn: ({allocationId, comment, secret}) =>
			withdrawAllocationTimeslot(allocationId, comment, secret),
		retry: false,
		onSuccess: (_data, variables) => {
			queryClient.invalidateQueries({queryKey: ['applicationEventsAllocationsBookings', variables.applicationId]});
			queryClient.invalidateQueries({queryKey: ['applicationComments', variables.applicationId]});
			queryClient.invalidateQueries({queryKey: ['application', variables.applicationId]});
		},
	});
}
