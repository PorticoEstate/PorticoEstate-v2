import {QueryClient, useQuery, useQueryClient, UseQueryResult} from "@tanstack/react-query";
import {IBookingUser} from "@/service/types/api.types";
import {
    fetchBookingUser,
    fetchDeliveredApplications,
    fetchInvoices,
    fetchPartialApplications
} from "@/service/api/api-utils";
import {IApplication} from "@/service/types/api/application.types";
import {ICompletedReservation} from "@/service/types/api/invoices.types";

export function useBookingUser(): UseQueryResult<IBookingUser> {
    return useQuery(
        {
            queryKey: ['bookingUser'],
            queryFn: () => fetchBookingUser(), // Fetch function
            retry: 2, // Number of retry attempts if the query fails
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
        }
    );
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