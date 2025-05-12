import {
	keepPreviousData,
	skipToken,
	useMutation,
	useQuery,
	useQueryClient,
	UseQueryResult,
	MutationOptions
} from "@tanstack/react-query";
import { useWebSocketContext } from '../websocket/websocket-context';
import {IBookingUser, IDocument, IServerSettings} from "@/service/types/api.types";
import {
	fetchArticlesForResources,
	fetchBuildingAgeGroups,
	fetchBuildingAudience,
	fetchBuildingSchedule,
	fetchBuildingSeasons,
	fetchDeliveredApplications,
	fetchFreeTimeSlotsForRange,
	fetchInvoices,
	fetchOrganizations,
	fetchPartialApplications,
	fetchSearchDataClient,
	fetchServerMessages,
	fetchServerSettings,
	fetchSessionId,
	fetchTowns,
	fetchUpcomingEvents,
	patchBookingUser
} from "@/service/api/api-utils";
import {IApplication, IUpdatePartialApplication, NewPartialApplication} from "@/service/types/api/application.types";
import {ICompletedReservation} from "@/service/types/api/invoices.types";
import {phpGWLink} from "@/service/util";
import {IEvent, IFreeTimeSlot, IShortEvent} from "@/service/pecalendar.types";
import {DateTime} from "luxon";
import {useCallback, useEffect} from "react";
import {IAgeGroup, IAudience, Season} from "@/service/types/Building";
import {IServerMessage} from "@/service/types/api/server-messages.types";
import {IArticle} from "../types/api/order-articles.types";
import {
	ISearchDataAll,
	ISearchDataOptimized,
	ISearchDataTown,
	ISearchOrganization
} from "@/service/types/api/search.types";
import {fetchSearchData} from "@/service/api/api-utils-static";
import {fetchBuildingDocuments, fetchResourceDocuments} from "@/service/api/building";
import {
	useMessageTypeSubscription,
	useEntitySubscription,
	useEntitySubscriptionWithPing
} from './use-websocket-subscriptions';
import {IWSServerDeletedMessage, IWSServerNewMessage, WebSocketMessage} from '../websocket/websocket.types';

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
			queryClient.invalidateQueries({queryKey: ['serverMessages']});
		},
		onSettled: (data, error, variables, context) => {
			// First call the original onSettled if it exists
			if (originalOnSettled) {
				originalOnSettled(data, error, variables, context);
			}

			// Then invalidate server messages if not already done in onSuccess
			// This ensures messages are refreshed even after errors
			queryClient.invalidateQueries({queryKey: ['serverMessages']});
		}
	});
}

// require('log-timestamp');
//
// if(typeof window !== "undefined") {
// 	require( 'console-stamp' )( console );
// }
interface UseScheduleOptions {
	building_id?: number;
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
	// Get just the current week that includes the first date in the array
	const currentWeek = weeks[0].set({weekday: 1}).startOf('day');
	const weekEnd = currentWeek.plus({weeks: 1});
	const weekKey = currentWeek.toFormat("y-MM-dd");

	// Set initial data if provided, but don't use cache for normal operation
	useEffect(() => {
		if (initialFreeTime) {
			// Just for initial server-side rendered data
			queryClient.setQueryData(
				['buildingFreeTime', building_id, weekKey],
				initialFreeTime
			);
		}
	}, [initialFreeTime, building_id, queryClient, weekKey]);

	// Create a handler function that can be used in the subscription
	const handleBuildingUpdate = useCallback((message: WebSocketMessage) => {
		if (message.type !== 'room_message') {
			// console.log("Should probably be caught before this (room_message), but not sure why we got this message: ", message);

			return;
		}

		if (message.entityId !== building_id || message.entityType !== 'building') {
			console.log("Should probably be caught before this (wrong place), but not sure why we got this message: ", message);
			return;
		}
		// console.log(`Received building update for ${building_id}:`, message);

		switch (message.action) {
			case 'updated': {
				// Get the current cache data
				const cacheKey = ['buildingFreeTime', building_id, weekKey];
				const currentData = queryClient.getQueryData<FreeTimeSlotsResponse>(cacheKey);
				const {affected_timeslots, application_id, change_type} = message.data;

				if (currentData) {
					// Create a copy of the current data to modify
					const updatedData: FreeTimeSlotsResponse = JSON.parse(JSON.stringify(currentData));

					// Iterate through each resource in affected_timeslots
					Object.entries(affected_timeslots).forEach(([resourceId, timeslots]) => {
						if (Array.isArray(timeslots) && updatedData[resourceId]) {
							// Process each timeslot for this resource
							timeslots.forEach(timeslot => {
								// Find if we already have this timeslot in our cache
								const existingIndex = updatedData[resourceId].findIndex(
									slot => slot.start_iso === timeslot.start_iso &&
										slot.end_iso === timeslot.end_iso
								);

								if (existingIndex >= 0) {
									// Update the existing timeslot with the new overlap information
									updatedData[resourceId][existingIndex] = {
										...updatedData[resourceId][existingIndex],
										overlap: timeslot.overlap,
										overlap_reason: timeslot.overlap_reason,
										overlap_type: timeslot.overlap_type,
										overlap_event: timeslot.overlap_event
									};
								} else {
									// This is a new timeslot we don't have in our cache yet
									// Add it to the array for this resource
									updatedData[resourceId].push({
										when: timeslot.when,
										start: timeslot.start,
										end: timeslot.end,
										start_iso: timeslot.start_iso,
										end_iso: timeslot.end_iso,
										overlap: timeslot.overlap,
										overlap_reason: timeslot.overlap_reason,
										overlap_type: timeslot.overlap_type,
										resource_id: parseInt(resourceId),
										overlap_event: timeslot.overlap_event
									});
								}
							});
						}
					});

					// Update the cache with our modified data
					queryClient.setQueryData(cacheKey, updatedData);
				} else {
					// If we don't have the data in cache yet, just invalidate
					queryClient.invalidateQueries({queryKey: ['buildingFreeTime', building_id]});
				}
				break;
			}
			case 'deleted': {
				queryClient.invalidateQueries({queryKey: ['buildingFreeTime', building_id]});
				break;
			}
			default: {
				queryClient.invalidateQueries({queryKey: ['buildingFreeTime', building_id]});
				break;

			}
		}
	}, [building_id, queryClient, weekKey]);

	// Use the standard useEntitySubscription hook to subscribe to building updates
	// The service will queue this subscription if WebSocket is not ready yet
	useEntitySubscriptionWithPing('building', building_id, handleBuildingUpdate);

	const fetchFreeTimeSlots = async (): Promise<FreeTimeSlotsResponse> => {
		// Always fetch from API for just the current week
		return await fetchFreeTimeSlotsForRange(
			building_id,
			currentWeek,
			weekEnd,
			instance
		);
	};

	return useQuery({
		queryKey: ['buildingFreeTime', building_id, weekKey],
		queryFn: fetchFreeTimeSlots,
		staleTime: 0, // Consider data stale immediately
		refetchOnMount: true, // Always refetch when component mounts
		refetchOnWindowFocus: true, // Refetch when window regains focus
		// cacheTime: 5 * 60 * 1000 // Cache for 5 minutes max
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

			// console.log("Query state", cacheKey, queryClient.getQueryState(cacheKey), d);
			return !d;
		});
		// console.log('weeks', uncachedWeeks);
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
		const scheduleData = await fetchBuildingSchedule(building_id!, uncachedWeeks, instance);
		// Cache each week's data separately
		uncachedWeeks.forEach(weekStart => {
			const weekData: IEvent[] = scheduleData[weekStart] || [];
			const cacheKey = getWeekCacheKey(weekStart);
			// console.log("uncachedWeek", weekStart);

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
		queryFn: building_id === undefined ? skipToken : fetchUncachedWeeks,
		enabled: building_id !== undefined,

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

/**
 * Hook to fetch session ID for the current user
 * @returns A query result containing the session ID
 */
export function useSessionId() {
	return useQuery<{ sessionId: string }>({
		queryKey: ['sessionId'],
		queryFn: fetchSessionId,
		staleTime: 5 * 60 * 1000, // Consider data fresh for 5 minutes
		retry: 3,
		retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 30000),
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
	const queryClient = useQueryClient();
	const { status: wsStatus, sessionConnected, isReady: wsReady } = useWebSocketContext();

	// Handle WebSocket messages with partial application updates
	useMessageTypeSubscription('partial_applications_response', (message) => {
		console.log('Received partial applications WebSocket update');

		// Update the query cache with the new data
		if (message.data.error === false) {
			queryClient.setQueryData(['partialApplications'], {
				list: message.data.applications,
				total_sum: message.data.applications.reduce((sum, app) => {
					// Calculate total sum from orders if they exist
					const orderSum = app.orders?.reduce((acc, order) => acc + (Number(order.sum) || 0), 0) || 0;
					return sum + orderSum;
				}, 0)
			});
		}
	});

	return useQuery(
		{
			queryKey: ['partialApplications'],
			queryFn: () => fetchPartialApplications(), // Fetch function
			retry: 2, // Number of retry attempts if the query fails
			refetchOnWindowFocus: false, // Do not refetch on window focus by default,
			refetchInterval: () => {
				// Check if websocket connection is active
				const isWebSocketActive = wsReady &&
					wsStatus === 'OPEN' &&
					sessionConnected;

				// If websocket is not active, refetch every 30 seconds
				// Otherwise rely on WebSocket updates
				return isWebSocketActive ? false : 30000;
			}
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
	const queryClient = useQueryClient();

	// Subscribe to server_message WebSocket messages with type safety
	useMessageTypeSubscription('server_message', (data) => {
		console.log(`Received server message [action: ${data.action}]`);

		// Different handling based on the action type
		switch (data.action) {
			case 'new': {
				// New server messages - add to the cache directly
				console.log('New server messages received:', data.messages);

				// Update the cache directly instead of invalidating
				queryClient.setQueryData<IServerMessage[]>(['serverMessages'], (oldMessages: IServerMessage[] | undefined) => {
					if (!oldMessages) return data.messages;

					// Add the new messages to the existing messages
					// Note: In case of duplicates by ID, new messages will replace old ones
					const messageMap = new Map<string, IServerMessage>();
					for (const oldMessage of oldMessages) {
						messageMap.set(oldMessage.id, oldMessage);
					}
					for (const message of data.messages) {
						messageMap.set(message.id, message);
					}

					return Array.from(messageMap.values());
				});
				break;
			}

			case 'changed': {
				// Changed server messages - update in the cache directly
				console.log('Server messages changed:', data.messages);

				// Update the cache directly instead of invalidating
				queryClient.setQueryData<IServerMessage[]>(['serverMessages'], (oldMessages: IServerMessage[] | undefined) => {
					if (!oldMessages) return data.messages;

					// Create a map for easy lookup
					const messageMap = new Map<string, IServerMessage>(oldMessages.map(msg => [msg.id, msg]));

					// Update changed messages
					data.messages.forEach((message: IServerMessage) => {
						messageMap.set(message.id, message);
					});

					return Array.from(messageMap.values());
				});
				break;
			}

			case 'deleted': {
				// Message IDs to delete
				const messageIds = data.message_ids;
				console.log('Server messages deleted:', messageIds);

				// Update the cache directly instead of invalidating
				queryClient.setQueryData<IServerMessage[]>(['serverMessages'], (oldMessages: IServerMessage[] | undefined) => {
					if (!oldMessages) return [];

					// Filter out the deleted messages
					return oldMessages.filter(msg => !messageIds.includes(msg.id));
				});
				break;
			}

			default:
				console.warn(`Unknown server_message action:`, data);
				// Fall back to invalidation for unknown actions
				queryClient.invalidateQueries({queryKey: ['serverMessages']});
		}
	});

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

/**
 * Hook to fetch just towns data using the dedicated endpoint
 * @param options - Query options including initialData for server-side rendering
 */
export function useTowns(options?: {
	initialData?: ISearchDataTown[]
}): UseQueryResult<ISearchDataTown[]> {
	return useQuery(
		{
			queryKey: ['towns'],
			queryFn: () => fetchTowns(), // Fetch function
			retry: 2, // Number of retry attempts if the query fails
			staleTime: 60 * 60 * 1000, // Consider data fresh for 1 hour (cached)
			refetchOnWindowFocus: false, // Do not refetch on window focus by default
			initialData: options?.initialData, // Use server-side fetched data if available
		}
	);
}

/**
 * Hook to fetch organizations data using the dedicated endpoint
 * @param options - Query options including initialData for server-side rendering
 */
export function useOrganizations(options?: {
	initialData?: ISearchOrganization[]
}): UseQueryResult<ISearchOrganization[]> {
	return useQuery(
		{
			queryKey: ['organizations'],
			queryFn: () => fetchOrganizations(), // Fetch function
			retry: 2, // Number of retry attempts if the query fails
			staleTime: 60 * 60 * 1000, // Consider data fresh for 1 hour (cached)
			refetchOnWindowFocus: false, // Do not refetch on window focus by default
			initialData: options?.initialData, // Use server-side fetched data if available
		}
	);
}


export function useDeleteServerMessage() {
	const queryClient = useQueryClient();

	return useServerMessageMutation({
		mutationFn: async (id: string) => {
			const url = phpGWLink(['bookingfrontend', 'user', 'messages', id]);
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
	const { status: wsStatus, sessionConnected,isReady: wsReady } = useWebSocketContext();

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
			// Check if websocket connection is active
			const isWebSocketActive = wsReady &&
				wsStatus === 'OPEN' &&
				sessionConnected;

			// Only invalidate if WebSocket is not active
			// If WebSocket is active, the server will send a message with the updated data
			if (!isWebSocketActive) {
				// Invalidate and refetch partial applications queries
				queryClient.invalidateQueries({queryKey: ['partialApplications']});
			}
		},
	});
}

export function useUpdatePartialApplication() {
	const queryClient = useQueryClient();
	const { status: wsStatus, sessionConnected, isReady: wsReady } = useWebSocketContext();

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
			// Check if websocket connection is active
			const isWebSocketActive = wsReady &&
				wsStatus === 'OPEN' &&
				sessionConnected;

			// Only invalidate if WebSocket is not active
			// If WebSocket is active, the server will send a message with the updated data
			if (!isWebSocketActive) {
				// Always refetch after error or success to ensure data is correct
				queryClient.invalidateQueries({queryKey: ['partialApplications']});
			}
		},
	});
}

export function useDeletePartialApplication() {
	const queryClient = useQueryClient();
	const { status: wsStatus, sessionConnected, isReady: wsReady } = useWebSocketContext();

	return useServerMessageMutation({
		mutationFn: async (id: number) => {
			const url = phpGWLink(['bookingfrontend', 'applications', id]);
			const response = await fetch(url, {method: 'DELETE'});

			if (!response.ok) {
				throw new Error('Failed to delete partial application');
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
			// Check if websocket connection is active
			const isWebSocketActive = wsReady &&
				wsStatus === 'OPEN' &&
				sessionConnected;

			// Only invalidate if WebSocket is not active
			// If WebSocket is active, the server will send a message with the updated data
			if (!isWebSocketActive) {
				// Always refetch after error or success to ensure data is correct
				queryClient.invalidateQueries({queryKey: ['partialApplications']});
			}
		},
	});
}


export function useCreateSimpleApplication() {
	const queryClient = useQueryClient();
	const { status: wsStatus, sessionConnected, isReady: wsReady } = useWebSocketContext();

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
			// Check if websocket connection is active
			const isWebSocketActive = wsReady &&
				wsStatus === 'OPEN' &&
				sessionConnected;

			// Only invalidate if WebSocket is not active
			// If WebSocket is active, the server will send messages with the updated data
			if (!isWebSocketActive) {
				// Invalidate and refetch partial applications queries
				queryClient.invalidateQueries({queryKey: ['partialApplications']});

				// Invalidate building timeslots if needed
				const buildingId = variables.building_id;
				if (buildingId) {
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
				}
			}
			// Note: When WebSocket is active, the server will send:
			// 1. partial_applications_response for updating applications
			// 2. room_message for updating building timeslots
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

/**
 * Hook to fetch upcoming events with React Query
 */
export function useUpcomingEvents(params: {
	fromDate?: string;
	toDate?: string;
	buildingId?: number;
	facilityTypeId?: number;
	loggedInOnly?: boolean;
	initialEvents?: IShortEvent[];
}) {
	const {fromDate, toDate, buildingId, facilityTypeId, loggedInOnly, initialEvents} = params;

	// Create a query key array that will change when params change
	// This ensures React Query knows when to refetch based on changed parameters
	const queryKey = [
		'upcomingEvents',
		fromDate || '',
		toDate || '',
		buildingId || '',
		facilityTypeId || '',
		loggedInOnly ? 'true' : 'false'
	];

	return useQuery({
		queryKey: queryKey,
		queryFn: () => fetchUpcomingEvents({
			fromDate,
			toDate,
			buildingId,
			facilityTypeId,
			loggedInOnly
		}),
		initialData: initialEvents,
		staleTime: 60 * 60 * 1000, // Data stays fresh for 1 hour
		refetchOnWindowFocus: false // Disable refetch on window focus
	});
}

export function useResourceRegulationDocuments(resources: { id: number, building_id?: number }[]) {
	const queryClient = useQueryClient();
	const resourceIds = resources.map(r => r.id);

	// Extract unique building IDs from resources
	const buildingIds = resources
		.filter(r => r.building_id)
		.map(r => r.building_id)
		.filter((value, index, self) => self.indexOf(value) === index) as number[];

	// Helper to get a unique key for documents from a specific resource
	const getResourceDocsKey = (resourceId: number) => ['resourceDocuments', resourceId, 'regulation'];
	// Helper to get a unique key for documents from a specific building
	const getBuildingDocsKey = (buildingId: number) => ['buildingDocuments', buildingId, 'regulation'];

	return useQuery({
		queryKey: ['allRegulationDocuments', resourceIds.join(','), buildingIds.join(',')],
		queryFn: async () => {
			const allDocsPromises = [
				// Fetch resource documents
				...resourceIds.map(async (resourceId) => {
					const cacheKey = getResourceDocsKey(resourceId);
					const cachedDocs = queryClient.getQueryData<IDocument[]>(cacheKey);

					if (cachedDocs) {
						return cachedDocs;
					}

					try {
						// Fetch regulation documents for this resource
						const docs = await fetchResourceDocuments(resourceId, 'regulation');

						// Add owner type to identify the document source
						const docsWithType = docs.map(doc => ({
							...doc,
							owner_type: 'resource' as const
						}));

						// Cache the documents
						queryClient.setQueryData(cacheKey, docsWithType);

						return docsWithType;
					} catch (error) {
						console.error(`Error fetching documents for resource ${resourceId}:`, error);
						return [];
					}
				}),

				// Fetch building documents
				...buildingIds.map(async (buildingId) => {
					const cacheKey = getBuildingDocsKey(buildingId);
					const cachedDocs = queryClient.getQueryData<IDocument[]>(cacheKey);

					if (cachedDocs) {
						return cachedDocs;
					}

					try {
						// Fetch regulation documents for this building
						const docs = await fetchBuildingDocuments(buildingId, 'regulation');

						// Add owner type to identify the document source
						const docsWithType = docs.map(doc => ({
							...doc,
							owner_type: 'building' as const
						}));

						// Cache the documents
						queryClient.setQueryData(cacheKey, docsWithType);

						return docsWithType;
					} catch (error) {
						console.error(`Error fetching documents for building ${buildingId}:`, error);
						return [];
					}
				})
			];

			// Wait for all document fetches to complete
			const allDocuments = await Promise.all(allDocsPromises);

			// Flatten and filter unique documents by ID
			const flattenedDocs = allDocuments.flat();
			const uniqueDocs = Array.from(
				new Map(flattenedDocs.map(doc => [doc.id, doc])).values()
			);

			return uniqueDocs;
		},
		enabled: resourceIds.length > 0 || buildingIds.length > 0,
		staleTime: 5 * 60 * 1000 // Consider data fresh for 5 minutes
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