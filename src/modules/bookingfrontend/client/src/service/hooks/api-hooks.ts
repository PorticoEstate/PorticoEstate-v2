import {keepPreviousData, useMutation, useQuery, useQueryClient, UseQueryResult} from "@tanstack/react-query";
import {IBookingUser} from "@/service/types/api.types";
import {
    fetchBuildingSchedule,
    fetchDeliveredApplications,
    fetchInvoices,
    fetchPartialApplications, patchBookingUser
} from "@/service/api/api-utils";
import {IApplication} from "@/service/types/api/application.types";
import {ICompletedReservation} from "@/service/types/api/invoices.types";
import {phpGWLink} from "@/service/util";
import {IEvent} from "@/service/pecalendar.types";
import {DateTime} from "luxon";
import {useEffect} from "react";


interface UseScheduleOptions {
    building_id: number;
    weeks: DateTime[];
    instance?: string;
    initialWeekSchedule?: Record<string, IEvent[]>
}


/**
 * Custom hook to fetch and cache building schedule data by weeks
 * @param options.building_id - The ID of the building
 * @param options.weekStarts - Array of dates representing the start of each week needed
 * @param options.instance - Optional instance parameter
 */
export const useBuildingSchedule = ({building_id, weeks, instance, initialWeekSchedule}: UseScheduleOptions) => {
    const queryClient = useQueryClient();
    const weekStarts = weeks.map(d => d.set({weekday: 1}).startOf('day'));
    const keys = weekStarts.map(a => a.toFormat("y-MM-dd"))

    // Helper to get cache key for a week
    const getWeekCacheKey = (key: string) => {
        return ['buildingSchedule', building_id, key];
    };
    // Initialize cache with provided initial schedule data
    useEffect(() => {
        if (initialWeekSchedule) {
            Object.entries(initialWeekSchedule).forEach(([weekStart, events]) => {
                const cacheKey = getWeekCacheKey(weekStart);
                if (!queryClient.getQueryData(cacheKey)) {
                    queryClient.setQueryData(cacheKey, events);
                }
            });
        }
    }, [initialWeekSchedule, building_id, queryClient]);
    // Filter out weeks that are already in cache
    const uncachedWeeks = keys.filter(weekStart => {
        const cacheKey = getWeekCacheKey(weekStart);
        const d = queryClient.getQueryData(cacheKey);
        return !d;
    });

    // Fetch function that gets all uncached weeks
    const fetchUncachedWeeks = async () => {
        if (uncachedWeeks.length === 0) {
            // If all weeks are cached, combine and return cached data
            const combinedData: IEvent[] = [];
            keys.forEach(weekStart => {
                const cacheKey = getWeekCacheKey(weekStart);
                const weekData = queryClient.getQueryData<IEvent[]>(cacheKey);
                if (weekData) {
                    combinedData.push(...weekData);
                }
            });
            return combinedData;
        }

        // Fetch data for all uncached weeks at once
        const scheduleData = await fetchBuildingSchedule(building_id, uncachedWeeks, instance);

        // Cache each week's data separately
        uncachedWeeks.forEach(weekStart => {
            const weekData: IEvent[] = scheduleData[weekStart] || [];
            const cacheKey = getWeekCacheKey(weekStart);
            console.log("uncachedWeek", weekStart);

            queryClient.setQueryData(cacheKey, weekData);
        });

        // Return combined data for all requested weeks
        const combinedData: IEvent[] = [];
        keys.forEach(weekStart => {
            const cacheKey = getWeekCacheKey(weekStart);
            const weekData = queryClient.getQueryData<IEvent[]>(cacheKey);
            if (weekData) {
                combinedData.push(...weekData);
            }
        });

        return combinedData;
    };

    // Main query hook
    return useQuery({
        queryKey: ['buildingSchedule', building_id, keys.join(',')],
        queryFn: fetchUncachedWeeks,
        // staleTime: 1000 * 60 * 5, // 5 minutes
        // cacheTime: 1000 * 60 * 30, // 30 minutes
    });
};

class AuthenticationError extends Error {
    statusCode: number;

    constructor(message: string = "Failed to fetch user", statusCode?: number) {
        super(message);
        this.name = "AuthenticationError";
        this.statusCode = 401; // HTTP status code for "Unauthorized"
    }
}

export function useBookingUser() {
    return useQuery<IBookingUser>({
        queryKey: ['bookingUser'],
        queryFn: async () => {

            const url = phpGWLink(['bookingfrontend', 'user']);

            const response = await fetch(url, {
                credentials: 'include',
            });

            if (!response.ok) {
                throw new AuthenticationError('Failed to fetch user', response.status);
            }

            return response.json();
        },
        retry: (failureCount, error: AuthenticationError | Error) => {
            console.log('useBookingUser', failureCount, error);
            // Don't retry on 401
            if (error instanceof AuthenticationError && error.statusCode === 401) {
                return false;
            }
            return failureCount < 3;
        },
        retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 30000),
        staleTime: 5 * 60 * 1000, // Consider data fresh for 5 minutes
        refetchOnWindowFocus: true,
        placeholderData: keepPreviousData,
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
            await queryClient.cancelQueries({queryKey: ['bookingUser']})

            // Snapshot current user
            const previousUser = queryClient.getQueryData<IBookingUser>(['bookingUser'])

            // Optimistically update user
            queryClient.setQueryData(['bookingUser'], (old: IBookingUser | undefined) => ({
                ...old,
                ...newData
            }))

            return {previousUser}
        },
        onError: (err, newData, context) => {
            // On error, rollback to previous state
            queryClient.setQueryData(['bookingUser'], context?.previousUser)
        },
        onSettled: () => {
            // Always refetch after error or success to ensure data is correct
            queryClient.invalidateQueries({queryKey: ['bookingUser']})
        }
    })
}

export function usePartialApplications(): UseQueryResult<{ list: IApplication[], total_sum: number }> {
    return useQuery(
        {
            queryKey: ['partialApplications'],
            queryFn: () => fetchPartialApplications(), // Fetch function
            retry: 2, // Number of retry attempts if the query fails
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
        }
    );
}

export function useApplications(): UseQueryResult<{ list: IApplication[], total_sum: number }> {
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