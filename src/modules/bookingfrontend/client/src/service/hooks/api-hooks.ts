import {useMutation, useQuery, useQueryClient, UseQueryResult} from "@tanstack/react-query";
import {IBookingUser} from "@/service/types/api.types";
import {
    fetchDeliveredApplications,
    fetchInvoices,
    fetchPartialApplications, patchBookingUser
} from "@/service/api/api-utils";
import {IApplication} from "@/service/types/api/application.types";
import {ICompletedReservation} from "@/service/types/api/invoices.types";
import {phpGWLink} from "@/service/util";


export function useBookingUser() {
    return useQuery<IBookingUser>({
        queryKey: ['bookingUser'],
        queryFn: async () => {

            const url = phpGWLink(['bookingfrontend', 'user']);

            const response = await fetch(url, {
                credentials: 'include',
            });

            if (!response.ok) {
                throw new Error('Failed to fetch user');
            }

            return response.json();
        },
        retry: (failureCount, error) => {
            // Don't retry on 401
            if (error instanceof Error && error.message.includes('401')) {
                return false;
            }
            return failureCount < 3;
        },
        retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 30000),
        staleTime: 5 * 60 * 1000, // Consider data fresh for 5 minutes
        refetchOnWindowFocus: true,
    });
}


export function useLogout() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async () => {
            const url = phpGWLink(['bookingfrontend', 'logout']);
            const response = await fetch(url, {
                credentials: 'include',
            });

            if (!response.ok) {
                throw new Error('Logout failed');
            }
        },
        onSuccess: () => {
            queryClient.setQueryData(['bookingUser'], null);
        },
    });
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