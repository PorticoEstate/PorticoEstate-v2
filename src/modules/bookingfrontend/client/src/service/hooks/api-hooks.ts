import {
    keepPreviousData,
    skipToken,
    useMutation,
    useQuery,
    useQueryClient,
    UseQueryResult,
    MutationOptions
} from "@tanstack/react-query";
import {IBookingUser, IServerSettings} from "@/service/types/api.types";
import {
	fetchArticlesForResources,
	fetchBuildingAgeGroups, fetchBuildingAudience,
	fetchBuildingSchedule, fetchBuildingSeasons,
	fetchDeliveredApplications, fetchFreeTimeSlotsForRange,
	fetchInvoices,
	fetchPartialApplications, fetchSearchDataClient, fetchServerMessages, fetchServerSettings, patchBookingUser
} from "@/service/api/api-utils";
import {IApplication, IUpdatePartialApplication, NewPartialApplication} from "@/service/types/api/application.types";
import {ICompletedReservation} from "@/service/types/api/invoices.types";
import {phpGWLink} from "@/service/util";
import {IEvent, IFreeTimeSlot} from "@/service/pecalendar.types";
import {DateTime} from "luxon";
import {useCallback, useEffect} from "react";
import {IAgeGroup, IAudience, Season} from "@/service/types/Building";
import {IServerMessage} from "@/service/types/api/server-messages.types";
import { IArticle } from "../types/api/order-articles.types";
import {ISearchDataAll, ISearchDataOptimized} from "@/service/types/api/search.types";
import {fetchSearchData} from "@/service/api/api-utils-static";

/**
 * Custom hook that wraps useMutation and adds server message invalidation
 * @param options The mutation options
 * @returns The mutation result with server message invalidation added
 */
function useServerMessageMutation<TData = unknown, TError = unknown, TVariables = void, TContext = unknown>(
    options: MutationOptions<TData, TError, TVariables, TContext>
) {
    const queryClient = useQueryClient();
    const originalOnSuccess = options.onSuccess;
    const originalOnSettled = options.onSettled;

    return useMutation<TData, TError, TVariables, TContext>({
        ...options,
        onSuccess: (data, variables, context) => {
            // First call the original onSuccess if it exists
            if (originalOnSuccess) {
                originalOnSuccess(data, variables, context);
            }

            // Then invalidate server messages
            queryClient.invalidateQueries({ queryKey: ['serverMessages'] });
        },
        onSettled: (data, error, variables, context) => {
            // First call the original onSettled if it exists
            if (originalOnSettled) {
                originalOnSettled(data, error, variables, context);
            }

            // Then invalidate server messages if not already done in onSuccess
            // This ensures messages are refreshed even after errors
            queryClient.invalidateQueries({ queryKey: ['serverMessages'] });
        }
    });
}
// require('log-timestamp');
//
// if(typeof window !== "undefined") {
// 	require( 'console-stamp' )( console );
// }
interface UseScheduleOptions {
    building_id: number;
    weeks: DateTime[];
    instance?: string;
    initialWeekSchedule?: Record<string, IEvent[]>
}

export interface FreeTimeSlotsResponse {
	[resourceId: string]: IFreeTimeSlot[];
}


export function useBuildingFreeTimeSlots({
											 building_id,
											 weeks,
											 instance,
											 initialFreeTime
										 }: {
	building_id: number;
	weeks: DateTime[];
	instance?: string;
	initialFreeTime?: FreeTimeSlotsResponse;
}) {
	const queryClient = useQueryClient();
	const weekStarts = weeks.map(d => d.set({weekday: 1}).startOf('day'));
	const weekEnds = weekStarts.map(d => d.plus({ weeks: 1 }).endOf('day'));

	const getWeekCacheKey = (weekStart: string): readonly ['buildingFreeTime', number, string] => {
		return ['buildingFreeTime', building_id, weekStart] as const;
	};

	useEffect(() => {
		if (initialFreeTime) {
			Object.entries(initialFreeTime).forEach(([resourceId, slots]) => {
				weekStarts.forEach(weekStart => {
					const weekKey = weekStart.toFormat("y-MM-dd");
					const cacheKey = getWeekCacheKey(weekKey);
					const weekSlots = slots.filter((slot: IFreeTimeSlot) => {
						const slotDate = DateTime.fromISO(slot.start_iso);
						return slotDate >= weekStart && slotDate < weekStart.plus({ weeks: 1 });
					});

					if (!queryClient.getQueryData(cacheKey)) {
						queryClient.setQueryData(cacheKey, { [resourceId]: weekSlots });
					}
				});
			});
		}
	}, [initialFreeTime, building_id, queryClient, weekStarts]);

	const fetchFreeTimeSlots = async (): Promise<FreeTimeSlotsResponse> => {
		const uncachedWeeks = weekStarts.filter(weekStart => {
			const cacheKey = getWeekCacheKey(weekStart.toFormat("y-MM-dd"));
			return !queryClient.getQueryData(cacheKey);
		});

		if (uncachedWeeks.length === 0) {
			const combinedData: FreeTimeSlotsResponse = {};
			weekStarts.forEach(weekStart => {
				const cacheKey = getWeekCacheKey(weekStart.toFormat("y-MM-dd"));
				const weekData = queryClient.getQueryData<FreeTimeSlotsResponse>(cacheKey);
				if (weekData) {
					Object.entries(weekData).forEach(([resourceId, slots]) => {
						if (!combinedData[resourceId]) combinedData[resourceId] = [];
						combinedData[resourceId].push(...slots);
					});
				}
			});
			return combinedData;
		}

		const freeTimeData = await fetchFreeTimeSlotsForRange(
			building_id,
			uncachedWeeks[0],
			uncachedWeeks[uncachedWeeks.length - 1].plus({ weeks: 1 }),
			instance
		);

		uncachedWeeks.forEach(weekStart => {
			const weekKey = weekStart.toFormat("y-MM-dd");
			const weekEnd = weekStart.plus({ weeks: 1 });
			const weekData: FreeTimeSlotsResponse = {};

			Object.entries(freeTimeData).forEach(([resourceId, slots]) => {
				weekData[resourceId] = slots.filter((slot: IFreeTimeSlot) => {
					const slotDate = DateTime.fromISO(slot.start_iso);
					return slotDate >= weekStart && slotDate < weekEnd;
				});
			});

			queryClient.setQueryData(getWeekCacheKey(weekKey), weekData);
		});

		return freeTimeData;
	};

	return useQuery({
		queryKey: ['buildingFreeTime', building_id, weekStarts.map(d => d.toFormat("y-MM-dd")).join(',')],
		queryFn: fetchFreeTimeSlots,
	});
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
    const getWeekCacheKey = useCallback((key: string) => {
        return ['buildingSchedule', building_id, key];
    }, [building_id]);
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
    }, [initialWeekSchedule, building_id, queryClient, getWeekCacheKey]);


    // Fetch function that gets all uncached weeks
    const fetchUncachedWeeks = async () => {
		// Filter out weeks that are already in cache
		const uncachedWeeks = keys.filter(weekStart => {
			const cacheKey = getWeekCacheKey(weekStart);
			const d = queryClient.getQueryData(cacheKey);

			console.log("Query state", cacheKey, queryClient.getQueryState(cacheKey), d);
			return !d;
		});
		console.log('weeks', uncachedWeeks);
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

            queryClient.setQueryData(cacheKey, weekData, {});
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
		// staleTime: 10000
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


export function useLogin() {
    const queryClient = useQueryClient();

    return useServerMessageMutation({
        mutationFn: async () => {
            const url = phpGWLink(['bookingfrontend', 'auth', 'login']);
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'include',
            });

            if (!response.ok) {
                throw new Error('Login failed');
            }

            return response.json();
        },
        onSuccess: () => {
            // Refetch user data after successful login
            queryClient.invalidateQueries({queryKey: ['bookingUser']});
        },
    });
}

// Update the existing useLogout hook
export function useLogout() {
    const queryClient = useQueryClient();

    return useServerMessageMutation({
        mutationFn: async () => {
            const url = phpGWLink(['bookingfrontend', 'auth', 'logout']);
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'include',
            });

            if (!response.ok) {
                throw new Error('Logout failed');
            }

            const data = await response.json();

            // Handle external logout if provided
            if (data.external_logout_url) {
                window.location.href = data.external_logout_url;
                return;
            }

            return data;
        },
        onSuccess: () => {
            // Clear user data after successful logout
            queryClient.setQueryData(['bookingUser'], null);
        },
    });
}

export function useUpdateBookingUser() {
    const queryClient = useQueryClient();

    return useServerMessageMutation({
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
export function useServerMessages(): UseQueryResult<IServerMessage[]> {
    return useQuery(
        {
            queryKey: ['serverMessages'],
            queryFn: () => fetchServerMessages(), // Fetch function
            retry: 2, // Number of retry attempts if the query fails
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
        }
    );
}


/**
 * Hook to fetch search data using the optimized endpoint
 * @param options - Query options including initialData for server-side rendering
 */
export function useSearchData(options?: {
	initialData?: ISearchDataOptimized
}): UseQueryResult<ISearchDataOptimized> {
	return useQuery(
		{
			queryKey: ['searchData'],
			queryFn: () => fetchSearchDataClient(), // Fetch function
			retry: 2, // Number of retry attempts if the query fails
			staleTime: 60 * 60 * 1000, // Consider data fresh for 1 hour
			refetchOnWindowFocus: false, // Do not refetch on window focus by default
			initialData: options?.initialData, // Use server-side fetched data if available
		}
	);
}



export function useDeleteServerMessage() {
	const queryClient = useQueryClient();

	return useServerMessageMutation({
		mutationFn: async (id: string) => {
			const url = phpGWLink(['bookingfrontend', 'user','messages', id]);
			const response = await fetch(url, {method: 'DELETE'});

			if (!response.ok) {
				throw new Error('Failed to delete server message');
			}

			return id
		},
		onMutate: async (id: string) => {
			// Cancel any outgoing refetches to avoid overwriting optimistic update
			await queryClient.cancelQueries({queryKey: ['serverMessages']});

			// Snapshot current server messages
			const previousMessages = queryClient.getQueryData<IServerMessage[]>(['serverMessages']);

			// Optimistically update server messages list
			if (previousMessages) {
				queryClient.setQueryData(['serverMessages'], previousMessages.filter(message => message.id !== id));
			}

			return {previousMessages: previousMessages};
		},
		onError: (err, variables, context) => {
			// On error, rollback to previous state
			if (context?.previousMessages) {
				queryClient.setQueryData(['serverMessages'], context.previousMessages);
			}
		},
		onSettled: () => {
			// Always refetch after error or success to ensure data is correct
			queryClient.invalidateQueries({queryKey: ['serverMessages']});
		},
	});
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


export function useCreatePartialApplication() {
    const queryClient = useQueryClient();

    return useServerMessageMutation({
        mutationFn: async (newApplication: Partial<NewPartialApplication>) => {
            const url = phpGWLink(['bookingfrontend', 'applications', 'partials']);
            const data = {
                ...newApplication,
                agegroups: newApplication.agegroups?.map(a => ({agegroup_id: a.id, male: a.male, female: a.female}))
            }
            const response = await fetch(url, {
                method: 'POST',
                body: JSON.stringify(data),
                headers: {
                    'Content-Type': 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to create partial application');
            }

            return response.json();
        },
        onSuccess: () => {
            // Invalidate and refetch partial applications queries
            queryClient.invalidateQueries({queryKey: ['partialApplications']});
        },
    });
}

export function useUpdatePartialApplication() {
    const queryClient = useQueryClient();

    return useServerMessageMutation({
        mutationFn: async ({id, application}: { id: number, application: IUpdatePartialApplication }) => {
            const url = phpGWLink(['bookingfrontend', 'applications', 'partials', id]);
            const data: Omit<IUpdatePartialApplication, 'resources' | 'agegroups'> & {
                resources?: number[],
                agegroups?: any[]
            } = {
                ...application,
                resources: application.resources?.map(a => a.id),
                agegroups: application.agegroups?.map(a => ({agegroup_id: a.id, male: a.male, female: a.female}))
            }
            const response = await fetch(url, {
                method: 'PATCH',
                body: JSON.stringify(data),
                headers: {
                    'Content-Type': 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to update partial application');
            }

            return response.json();
        },
        onMutate: async ({id, application}) => {
            // Cancel any outgoing refetches to avoid overwriting optimistic update
            await queryClient.cancelQueries({queryKey: ['partialApplications']});
            console.log("update partial application", id, application);

            // Snapshot current applications
            const previousApplications = queryClient.getQueryData<{
                list: IApplication[],
                total_sum: number
            }>(['partialApplications']);

            // Optimistically update applications list
            if (previousApplications) {
                queryClient.setQueryData(['partialApplications'], {
                    ...previousApplications,
                    list: previousApplications.list.map(app =>
                        app.id === id ? {...app, ...application, dates: application.dates ?? app.dates} : app
                    ),
                });
            }

            return {previousApplications};
        },
        onError: (err, variables, context) => {
            // On error, rollback to previous state
            if (context?.previousApplications) {
                queryClient.setQueryData(['partialApplications'], context.previousApplications);
            }
        },
        onSettled: () => {
            // Always refetch after error or success to ensure data is correct
            queryClient.invalidateQueries({queryKey: ['partialApplications']});
        },
    });
}

export function useDeletePartialApplication() {
    const queryClient = useQueryClient();

    return useServerMessageMutation({
        mutationFn: async (id: number) => {
            const url = phpGWLink(['bookingfrontend', 'applications', id]);
            const response = await fetch(url, {method: 'DELETE'});

            if (!response.ok) {
                throw new Error('Failed to update partial application');
            }

            return id
        },
        onMutate: async (id: number) => {
            // Cancel any outgoing refetches to avoid overwriting optimistic update
            await queryClient.cancelQueries({queryKey: ['partialApplications']});

            // Snapshot current applications
            const previousApplications = queryClient.getQueryData<{
                list: IApplication[],
                total_sum: number
            }>(['partialApplications']);

            // Optimistically update applications list
            if (previousApplications) {
                queryClient.setQueryData(['partialApplications'], {
                    ...previousApplications,
                    list: previousApplications.list.filter(app =>
                        app.id !== id
                    ),
                });
            }

            return {previousApplications};
        },
        onError: (err, variables, context) => {
            // On error, rollback to previous state
            if (context?.previousApplications) {
                queryClient.setQueryData(['partialApplications'], context.previousApplications);
            }
        },
        onSettled: () => {
            // Always refetch after error or success to ensure data is correct
            queryClient.invalidateQueries({queryKey: ['partialApplications']});
        },
    });
}



export function useCreateSimpleApplication() {
	const queryClient = useQueryClient();

	return useServerMessageMutation({
		mutationFn: async (params: { timeslot: IFreeTimeSlot, building_id: number }) => {
			const url = phpGWLink(['bookingfrontend', 'applications', 'simple']);

			const response = await fetch(url, {
				method: 'POST',
				body: JSON.stringify({
					from: params.timeslot.start,
					to: params.timeslot.end,
					resource_id: params.timeslot.resource_id,
					building_id: params.building_id,
				}),
				headers: {
					'Content-Type': 'application/json',
				},
			});

			if (!response.ok) {
				// Parse error message from response if available
				const errorData = await response.json().catch(() => ({}));
				throw new Error(errorData.error || 'Failed to create simple application');
			}

			return response.json();
		},
		onSuccess: (data, variables) => {
			// Invalidate and refetch partial applications queries
			queryClient.invalidateQueries({queryKey: ['partialApplications']});

			// Invalidate timeslots to refresh available slots after booking
			const buildingId = variables.building_id;
			if (buildingId) {
				// Most thorough approach - invalidate ALL buildingFreeTime queries for this building
				queryClient.invalidateQueries({
					predicate: (query) => {
						const queryKey = query.queryKey;
						return (
							Array.isArray(queryKey) &&
							queryKey[0] === 'buildingFreeTime' &&
							(queryKey[1] === buildingId || queryKey.includes(buildingId.toString()))
						);
					}
				});

				// Force a refresh of any combined queries that may use comma-separated week keys
				setTimeout(() => {
					queryClient.refetchQueries({
						predicate: (query) => {
							const queryKey = query.queryKey;
							return (
								Array.isArray(queryKey) &&
								queryKey[0] === 'buildingFreeTime' &&
								query.queryKey.length > 2
							);
						}
					});
				}, 100);
			}
		},
	});
}



export function useBuildingAgeGroups(building_id?: number): UseQueryResult<IAgeGroup[]> {
    return useQuery(
        {
            queryKey: ['building_agegroups', building_id],
            queryFn: building_id === undefined ? skipToken : () => fetchBuildingAgeGroups(building_id), // Fetch function
            retry: 2, // Number of retry attempts if the query fails
            enabled: building_id !== undefined,
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
        }
    );
}



export function useBuildingSeasons(building_id?: number): UseQueryResult<Season[]> {
    return useQuery(
        {
            queryKey: ['building_seasons', building_id],
            queryFn: building_id === undefined ? skipToken : () => fetchBuildingSeasons(building_id), // Fetch function
            retry: 2, // Number of retry attempts if the query fails
            enabled: building_id !== undefined,
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
        }
    );
}



export function useServerSettings(): UseQueryResult<IServerSettings> {
    return useQuery(
        {
            queryKey: ['building_seasons'],
            queryFn: () => fetchServerSettings(), // Fetch function
            retry: 2, // Number of retry attempts if the query fails
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
        }
    );
}


export function useBuildingAudience(building_id?: number): UseQueryResult<IAudience[]> {
    return useQuery(
        {
            queryKey: ['building_audience', building_id],
            queryFn: building_id === undefined ? skipToken : () => fetchBuildingAudience(building_id), // Fetch function
            retry: 2, // Number of retry attempts if the query fails
            enabled: building_id !== undefined,
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
        }
    );
}


interface UploadDocumentParams {
    id: number;
    files: FormData;
}

export function useUploadApplicationDocument() {
    const queryClient = useQueryClient();

    return useServerMessageMutation({
        mutationFn: async ({id, files}: UploadDocumentParams) => {
            const url = phpGWLink(['bookingfrontend', 'applications', id, 'documents']);
            const response = await fetch(url, {
                method: 'POST',
                body: files,
                credentials: 'include'
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to upload documents');
            }

            return response.json();
        },
        onSuccess: (data, variables) => {
            // Invalidate and refetch partial applications to update documents list
            queryClient.invalidateQueries({
                queryKey: ['partialApplications']
            });

            // Could also invalidate specific application if needed
            queryClient.invalidateQueries({
                queryKey: ['partialApplications', variables.id]
            });
        }
    });
}
export function useDeleteApplicationDocument() {
    const queryClient = useQueryClient();

    return useServerMessageMutation({
        mutationFn: async (documentId: number) => {
            const url = phpGWLink(['bookingfrontend', 'applications', 'document', documentId]);
            const response = await fetch(url, {
                method: 'DELETE',
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error('Failed to delete document');
            }
        },
        onSuccess: () => {
            queryClient.invalidateQueries({
                queryKey: ['partialApplications']
            });
        }
    });
}

export function useResourceArticles({
										resourceIds,
										initialArticles
									}: {
	resourceIds: number[];
	initialArticles?: IArticle[];
}) {
	const queryClient = useQueryClient();

	// Helper to get cache key for a resource
	const getResourceCacheKey = (resourceId: number): readonly ['resourceArticles', number] => {
		return ['resourceArticles', resourceId] as const;
	};

	// Initialize cache with provided initial articles data
	useEffect(() => {
		if (initialArticles) {
			// First, create a map of all articles by ID for quick lookup of parent articles
			const articlesById = new Map<number, IArticle>();
			initialArticles.forEach(article => {
				articlesById.set(article.id, article);
			});

			// For each article, determine its resource_id (either direct or via parent)
			initialArticles.forEach((article) => {
				let resourceId = article.resource_id;

				// If no resource_id but has parent_mapping_id, try to get resource_id from parent
				if (!resourceId && article.parent_mapping_id) {
					let parentArticle = articlesById.get(article.parent_mapping_id);
					// Follow the parent chain to find a resource_id
					while (parentArticle && !resourceId) {
						resourceId = parentArticle.resource_id;
						if (!resourceId && parentArticle.parent_mapping_id) {
							parentArticle = articlesById.get(parentArticle.parent_mapping_id);
						} else {
							break;
						}
					}
				}

				// Only cache if we found a resource_id
				if (resourceId) {
					const cacheKey = getResourceCacheKey(resourceId);
					const existingData = queryClient.getQueryData<IArticle[]>(cacheKey);

					if (!existingData) {
						// If no data exists for this resource, create new cache entry
						queryClient.setQueryData(cacheKey, [article]);
					} else if (!existingData.some(a => a.id === article.id)) {
						// If the article doesn't exist in the cache, add it
						queryClient.setQueryData(cacheKey, [...existingData, article]);
					}
				}
			});
		}
	}, [initialArticles, queryClient]);

	const fetchArticles = async (): Promise<IArticle[]> => {
		// Check which resource IDs don't have cached data
		const uncachedResourceIds = resourceIds.filter(id => {
			const cacheKey = getResourceCacheKey(id);
			return !queryClient.getQueryData(cacheKey);
		});

		if (uncachedResourceIds.length === 0) {
			// If all resources have cached articles, combine and return them
			const combinedArticles: IArticle[] = [];
			resourceIds.forEach(resourceId => {
				const cacheKey = getResourceCacheKey(resourceId);
				const resourceArticles = queryClient.getQueryData<IArticle[]>(cacheKey);
				if (resourceArticles) {
					combinedArticles.push(...resourceArticles);
				}
			});
			return combinedArticles;
		}

		// Fetch data for uncached resource IDs
		const articlesData = await fetchArticlesForResources(uncachedResourceIds);

		// Create a map of all articles by ID for parent lookups
		const articlesById = new Map<number, IArticle>();
		articlesData.forEach(article => {
			articlesById.set(article.id, article);
		});

		// Cache articles by resource ID, resolving parent relationships
		articlesData.forEach(article => {
			let resourceId = article.resource_id;

			// If no resource_id but has parent_mapping_id, try to get resource_id from parent
			if (!resourceId && article.parent_mapping_id) {
				let parentArticle = articlesById.get(article.parent_mapping_id);
				// Follow the parent chain to find a resource_id
				while (parentArticle && !resourceId) {
					resourceId = parentArticle.resource_id;
					if (!resourceId && parentArticle.parent_mapping_id) {
						parentArticle = articlesById.get(parentArticle.parent_mapping_id);
					} else {
						break;
					}
				}
			}

			// Associate the article with its resource, even if determined from parent
			if (resourceId) {
				const cacheKey = getResourceCacheKey(resourceId);
				const existingData = queryClient.getQueryData<IArticle[]>(cacheKey) || [];
				if (!existingData.some(a => a.id === article.id)) {
					queryClient.setQueryData(cacheKey, [...existingData, article]);
				}
			}
		});

		// Return combined articles for all requested resources
		const combinedArticles: IArticle[] = [];
		resourceIds.forEach(resourceId => {
			const cacheKey = getResourceCacheKey(resourceId);
			const resourceArticles = queryClient.getQueryData<IArticle[]>(cacheKey);
			if (resourceArticles) {
				combinedArticles.push(...resourceArticles);
			}
		});

		return combinedArticles;
	};

	return useQuery({
		queryKey: ['resourceArticles', resourceIds.sort().join(',')],
		queryFn: fetchArticles,
	});
}