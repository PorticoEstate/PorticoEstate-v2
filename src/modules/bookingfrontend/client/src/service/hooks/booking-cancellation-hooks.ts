import {useMutation, useQueryClient} from "@tanstack/react-query";
import {
	BookingCancellationError,
	cancelBooking,
	IBookingCancelPreview,
	IBookingCancelRequest,
	IBookingCancelResult,
	previewBookingCancellation,
} from "@/service/api/booking-cancellation";

interface BookingCancellationVariables {
	bookingId: number;
	body: IBookingCancelRequest;
	secret?: string;
}

/**
 * The preview step. Sibling of useAllocationCancelPreview — same mutation-not-query reasoning
 * (never cached, deliberately re-run as the TOCTOU recovery from a stale confirm_token).
 */
export function useBookingCancelPreview() {
	return useMutation<IBookingCancelPreview, BookingCancellationError, BookingCancellationVariables>({
		mutationFn: ({bookingId, body, secret}) =>
			previewBookingCancellation(bookingId, body, secret),
		retry: false,
	});
}

/**
 * The destructive step. Sibling of useAllocationCancel — same `retry: false` reasoning (a 409
 * stale confirm_token must be recovered by re-previewing, never by retrying the cancel itself).
 *
 * On success both calendar caches for the building are invalidated, same as the allocation hook:
 * a cancelled booking can itself delete rows (the booking, and where cascaded its parent
 * allocation), so a stale cache would keep drawing entities that no longer exist.
 */
export function useBookingCancel(buildingId?: number) {
	const queryClient = useQueryClient();

	return useMutation<IBookingCancelResult, BookingCancellationError, BookingCancellationVariables>({
		mutationFn: ({bookingId, body, secret}) =>
			cancelBooking(bookingId, body, secret),
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
