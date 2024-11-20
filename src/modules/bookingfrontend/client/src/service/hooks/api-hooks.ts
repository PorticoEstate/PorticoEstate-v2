import {QueryClient, useMutation, useQuery, useQueryClient, UseQueryResult} from "@tanstack/react-query";
import {IBookingUser, IBookingUserBase} from "@/service/types/api.types";
import {
    fetchBookingUser,
    fetchDeliveredApplications,
    fetchInvoices,
    fetchPartialApplications, patchBookingUser
} from "@/service/api/api-utils";
import {IApplication} from "@/service/types/api/application.types";
import {ICompletedReservation} from "@/service/types/api/invoices.types";
import {redirect} from "next/navigation";

export function useBookingUser(): UseQueryResult<IBookingUserBase> {
    return useQuery(
        {
            queryKey: ['bookingUser'],
            queryFn: () => fetchBookingUser(), // Fetch function
            retry: 2, // Number of retry attempts if the query fails
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
        }
    );
}

export function useAuthenticatedUser(): UseQueryResult<IBookingUser> {
    const baseBookingUser = useBookingUser();

    if(!baseBookingUser.isLoading && baseBookingUser?.data && !baseBookingUser.data.is_logged_in) {
        redirect('/');
    }

    return baseBookingUser as UseQueryResult<IBookingUser>;
}



export function useUpdateBookingUser() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: patchBookingUser,
        onMutate: async (newData: Partial<IBookingUser>) => {
            // Cancel any outgoing refetches to avoid overwriting optimistic update
            await queryClient.cancelQueries({ queryKey: ['bookingUser'] })

            // Snapshot current user
            const previousUser = queryClient.getQueryData<IBookingUser>(['bookingUser'])

            // Optimistically update user
            queryClient.setQueryData(['bookingUser'], (old: IBookingUser | undefined) => ({
                ...old,
                ...newData
            }))

            return { previousUser }
        },
        onError: (err, newData, context) => {
            // On error, rollback to previous state
            queryClient.setQueryData(['bookingUser'], context?.previousUser)
        },
        onSettled: () => {
            // Always refetch after error or success to ensure data is correct
            queryClient.invalidateQueries({ queryKey: ['bookingUser'] })
        }
    })
}

export function usePartialApplications(): UseQueryResult<{list: IApplication[], total_sum: number}> {
    return useQuery(
        {
            queryKey: ['partialApplications'],
            queryFn: () => fetchPartialApplications(), // Fetch function
            retry: 2, // Number of retry attempts if the query fails
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
        }
    );
}

export function useApplications(): UseQueryResult<{list: IApplication[], total_sum: number}> {
    return useQuery(
        {
            queryKey: ['deliveredApplications'],
            queryFn: () => fetchDeliveredApplications(), // Fetch function
            retry: 2, // Number of retry attempts if the query fails
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
        }
    );
}
export function useInvoices(): UseQueryResult<ICompletedReservation[]> {
    return useQuery(
        {
            queryKey: ['invoices'],
            queryFn: () => fetchInvoices(), // Fetch function
            retry: 2, // Number of retry attempts if the query fails
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
        }
    );
}