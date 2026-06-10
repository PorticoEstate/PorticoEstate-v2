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
import { SubscriptionManager } from '../websocket/subscription-manager';
import {IBookingUser, IDocument, IServerSettings, IMultiDomain, IDocumentCategoryQuery} from "@/service/types/api.types";
import {
	fetchApplication,
	fetchApplicationComments,
	fetchApplicationDocuments,
	fetchApplicationScheduleEntities,
	addApplicationComment,
	updateApplicationStatus,
	fetchArticlesForResources,
	fetchAvailableResources,
	fetchAvailableResourcesMultiDomain,
	fetchBuildingAgeGroups,
	fetchBuildingAudience,
	fetchBuildingSchedule,
	fetchOrganizationSchedule,
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
	fetchMultiDomains,
	patchBookingUser
} from "@/service/api/api-utils";
import {IApplication, IUpdatePartialApplication, NewPartialApplication, GetCommentsResponse, AddCommentRequest, AddCommentResponse, UpdateStatusRequest, UpdateStatusResponse, ApplicationComment} from "@/service/types/api/application.types";
import {INotification, INotificationListResponse, IUnreadCountResponse, IMarkReadResponse} from "@/service/types/api/notification.types";
import {ICompletedReservation} from "@/service/types/api/invoices.types";
import {phpGWLink} from "@/service/util";
import {IEvent, IFreeTimeSlot, IShortEvent, IAPIEvent, IAPIBooking, IAPIAllocation} from "@/service/pecalendar.types";
import {DateTime} from "luxon";
import {useCallback, useEffect, useRef} from "react";
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
import { pendingDeletions } from './pending-deletions';

/**
 * Custom hook that wraps useMutation and adds server message invalidation
 * @param options The mutation options
 * @returns The mutation result with server message invalidation added
 */
function useServerMessageMutation<TData = unknown, TError = unknown, TVariables = void, TContext = unknown>(
	options: MutationOptions<TData, TError, TVariables, TContext>
) {
	const queryClient = useQueryClient();
	const { status: wsStatus, sessionConnected, isReady: wsReady } = useWebSocketContext();
	const originalOnSuccess = options.onSuccess;
	const originalOnSettled = options.onSettled;

	return useMutation<TData, TError, TVariables, TContext>({
		...options,
		onSuccess: (data, variables, context) => {
			if (originalOnSuccess) {
				originalOnSuccess(data, variables, context);
			}
		},
		onSettled: (data, error, variables, context) => {
			if (originalOnSettled) {
				originalOnSettled(data, error, variables, context);
			}

			// When WS is connected, the server_message subscription keeps the cache
			// in sync via real-time pushes — no need to re-fetch from REST.
			// Only invalidate when WS is not available.
			const isWebSocketActive = wsReady && wsStatus === 'OPEN' && sessionConnected;
			if (!isWebSocketActive) {
				queryClient.invalidateQueries({queryKey: ['serverMessages']});
			}
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

interface UseOrganizationScheduleOptions {
	organization_id?: number;
	weeks: DateTime[];
	instance?: string;
	initialWeekSchedule?: Record<string, IEvent[]>
}

export interface FreeTimeSlotsResponse {
	[resourceId: string]: IFreeTimeSlot[];
}


/**
 * Number of weeks to prefetch ahead of the viewed week.
 */
const PREFETCH_WEEKS_AHEAD = 3;

/**
 * Helper: get the Monday-based week key for a DateTime.
 */
function weekKeyFor(dt: DateTime): string {
	return dt.set({weekday: 1}).startOf('day').toFormat('y-MM-dd');
}

/**
 * Split a flat freetime response into per-week buckets.
 * Each slot is assigned to the week its start falls into.
 */
function splitIntoWeeks(
	data: FreeTimeSlotsResponse,
	startWeek: DateTime,
	numWeeks: number,
): Map<string, FreeTimeSlotsResponse> {
	// Build week boundaries: [weekStart, weekEnd) for each week
	const weekRanges: Array<{key: string; startMs: number; endMs: number}> = [];
	for (let w = 0; w < numWeeks; w++) {
		const wk = startWeek.plus({weeks: w}).set({weekday: 1}).startOf('day');
		weekRanges.push({
			key: wk.toFormat('y-MM-dd'),
			startMs: wk.toMillis(),
			endMs: wk.plus({weeks: 1}).toMillis(),
		});
	}

	const buckets = new Map<string, FreeTimeSlotsResponse>();
	for (const wr of weekRanges) {
		buckets.set(wr.key, {});
	}

	for (const [resourceId, slots] of Object.entries(data)) {
		for (const slot of slots) {
			const ms = parseInt(slot.start, 10);
			// Find which week this slot's start falls into
			const wr = weekRanges.find(w => ms >= w.startMs && ms < w.endMs);
			if (wr) {
				const bucket = buckets.get(wr.key)!;
				if (!bucket[resourceId]) bucket[resourceId] = [];
				bucket[resourceId].push(slot);
			}
		}
	}
	return buckets;
}

/**
 * Filter slots to only include those whose start falls within [weekStart, weekEnd).
 * Prevents multi-day slots from leaking into adjacent week caches.
 */
function filterSlotsToWeek(
	data: FreeTimeSlotsResponse,
	weekStart: DateTime,
	weekEnd: DateTime,
): FreeTimeSlotsResponse {
	const startMs = weekStart.toMillis();
	const endMs = weekEnd.toMillis();
	const filtered: FreeTimeSlotsResponse = {};
	for (const [resourceId, slots] of Object.entries(data)) {
		filtered[resourceId] = slots.filter(slot => {
			const ms = parseInt(slot.start, 10);
			return ms >= startMs && ms < endMs;
		});
	}
	return filtered;
}

/**
 * Patch a week's cache in-place with affected_timeslots from a WS room message.
 */
function patchWeekCache(
	current: FreeTimeSlotsResponse,
	affectedTimeslots: Record<string, any[]>,
): FreeTimeSlotsResponse {
	const updated: FreeTimeSlotsResponse = JSON.parse(JSON.stringify(current));
	for (const [resourceId, timeslots] of Object.entries(affectedTimeslots)) {
		if (!Array.isArray(timeslots) || !updated[resourceId]) continue;
		for (const ts of timeslots) {
			// Compare by epoch timestamp — ISO strings may differ in timezone format
			// (PHP: +02:00, Node: Z) but epoch values are identical
			const tsStart = String(ts.start);
			const tsEnd = String(ts.end);
			const idx = updated[resourceId].findIndex(
				s => String(s.start) === tsStart && String(s.end) === tsEnd,
			);
			if (idx >= 0) {
				const existing = updated[resourceId][idx];
				// Don't downgrade: if cached data already has an application-level
				// overlap, don't overwrite it with a block-level overlap from
				// a session-agnostic room_message
				if (
					existing.overlap_event?.type === 'application' &&
					ts.overlap_event?.type === 'block' &&
					ts.overlap
				) {
					continue;
				}
				updated[resourceId][idx] = {
					...existing,
					overlap: ts.overlap,
					overlap_reason: ts.overlap_reason,
					overlap_type: ts.overlap_type,
					overlap_event: ts.overlap_event,
				};
			} else {
				updated[resourceId].push({
					when: ts.when, start: ts.start, end: ts.end,
					start_iso: ts.start_iso, end_iso: ts.end_iso,
					overlap: ts.overlap, overlap_reason: ts.overlap_reason,
					overlap_type: ts.overlap_type, resource_id: parseInt(resourceId),
					overlap_event: ts.overlap_event,
				});
			}
		}
	}
	return updated;
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
	const { status: wsStatus, sessionConnected, isReady: wsReady, sendMessage } = useWebSocketContext();
	const isWebSocketActive = wsReady && wsStatus === 'OPEN' && sessionConnected;

	// The week the user is currently viewing
	const viewedWeek = weeks[0].set({weekday: 1}).startOf('day');
	const weekKey = viewedWeek.toFormat('y-MM-dd');
	const todayWeek = DateTime.now().set({weekday: 1}).startOf('day');
	const isPastWeek = viewedWeek < todayWeek;

	// Seed SSR initial data into the viewed week's cache
	useEffect(() => {
		if (initialFreeTime) {
			queryClient.setQueryData(['buildingFreeTime', building_id, weekKey], initialFreeTime);
		}
	}, [initialFreeTime, building_id, queryClient, weekKey]);

	// --- Prefetch current + 3 weeks ahead when WS becomes active ---
	const hasPrefetched = useRef(false);
	useEffect(() => {
		if (!isWebSocketActive || hasPrefetched.current) return;
		hasPrefetched.current = true;

		const prefetchStart = todayWeek < viewedWeek ? todayWeek : viewedWeek;
		const prefetchEnd = prefetchStart.plus({weeks: PREFETCH_WEEKS_AHEAD + 1});
		const numWeeks = PREFETCH_WEEKS_AHEAD + 1;

		(async () => {
			try {
				const data = await fetchFreeTimeViaWs(
					sendMessage, building_id,
					prefetchStart.minus({days: 1}).toFormat('yyyy-MM-dd'),
					prefetchEnd.plus({days: 1}).toFormat('yyyy-MM-dd'),
				);
				const weekBuckets = splitIntoWeeks(data, prefetchStart, numWeeks);
				for (const [wk, weekData] of weekBuckets) {
					queryClient.setQueryData(['buildingFreeTime', building_id, wk], weekData);
				}
			} catch {
				// Prefetch failed — individual queries will fetch on demand
			}
		})();
	}, [isWebSocketActive, building_id, sendMessage, queryClient, todayWeek, viewedWeek]);

	// Reset prefetch flag on unmount so it refetches fresh on return
	useEffect(() => {
		return () => { hasPrefetched.current = false; };
	}, []);

	// --- WS room updates: patch ALL cached weeks for this building ---
	const handleBuildingUpdate = useCallback((message: WebSocketMessage) => {
		if (message.type !== 'room_message') return;
		if (message.entityId !== building_id || message.entityType !== 'building') return;

		if (message.data?.affected_timeslots && (message.action === 'updated' || message.action === 'deleted')) {
			// Patch ALL cached weeks for this building
			const allQueries = queryClient.getQueriesData<FreeTimeSlotsResponse>({
				queryKey: ['buildingFreeTime', building_id],
			});
			for (const [qk, data] of allQueries) {
				if (!data) continue;
				queryClient.setQueryData(qk, patchWeekCache(data, message.data.affected_timeslots));
			}
		} else {
			// For deletes or unknown actions, invalidate all weeks
			queryClient.invalidateQueries({queryKey: ['buildingFreeTime', building_id]});
		}
	}, [building_id, queryClient]);

	useEntitySubscriptionWithPing('building', building_id, handleBuildingUpdate);

	// --- Per-week query: serves from prefetch cache, fetches on demand for cache misses ---
	const fetchWeek = async (): Promise<FreeTimeSlotsResponse> => {
		if (isPastWeek) return {};

		// Use exact week boundaries for filtering, but fetch 1 day earlier
		// to catch multi-day slots that start within the week but need a
		// prior-day seed in the FreeTimeService generation loop
		const weekStart = viewedWeek;
		const weekEnd = viewedWeek.plus({weeks: 1});
		const fetchStart = weekStart.minus({days: 1});

		if (isWebSocketActive) {
			try {
				const raw = await fetchFreeTimeViaWs(
					sendMessage, building_id,
					fetchStart.toFormat('yyyy-MM-dd'),
					weekEnd.toFormat('yyyy-MM-dd'),
				);
				// Only keep slots whose start falls within this week
				return filterSlotsToWeek(raw, weekStart, weekEnd);
			} catch {
				// Fall back to REST
			}
		}
		const raw = await fetchFreeTimeSlotsForRange(building_id, fetchStart, weekEnd, instance);
		return filterSlotsToWeek(raw, weekStart, weekEnd);
	};

	return useQuery({
		queryKey: ['buildingFreeTime', building_id, weekKey],
		queryFn: fetchWeek,
		// Prefetch sets cache data directly — give it 10s before considering stale
		// so the per-week query doesn't immediately re-fetch on mount
		staleTime: 10_000,
		refetchOnMount: true,
		refetchOnWindowFocus: true,
		placeholderData: keepPreviousData,
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
		// Filter out weeks that are stale or not in cache
		const staleTime = 60 * 1000; // 60 seconds
		const uncachedWeeks = keys.filter(weekStart => {
			const cacheKey = getWeekCacheKey(weekStart);
			const state = queryClient.getQueryState(cacheKey);

			// Refetch if: doesn't exist, is invalidated, or is stale (older than 60s)
			if (!state?.data) return true;
			const isStale = Date.now() - state.dataUpdatedAt > staleTime;
			return isStale || state.isInvalidated;
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

/**
 * Custom hook to fetch and cache organization schedule data by weeks
 * @param options.organization_id - The ID of the organization
 * @param options.weekStarts - Array of dates representing the start of each week needed
 * @param options.instance - Optional instance parameter
 */
export const useOrganizationSchedule = ({organization_id, weeks, instance, initialWeekSchedule}: UseOrganizationScheduleOptions) => {
	const queryClient = useQueryClient();
	const weekStarts = weeks.map(d => d.set({weekday: 1}).startOf('day'));
	const keys = weekStarts.map(a => a.toFormat("y-MM-dd"))

	// Helper to get cache key for a week
	const getWeekCacheKey = useCallback((key: string) => {
		return ['organizationSchedule', organization_id, key];
	}, [organization_id]);

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
	}, [initialWeekSchedule, organization_id, queryClient, getWeekCacheKey]);

	// Fetch function that gets all uncached weeks
	const fetchUncachedWeeks = async () => {
		// Filter out weeks that are stale or not in cache
		const staleTime = 60 * 1000; // 60 seconds
		const uncachedWeeks = keys.filter(weekStart => {
			const cacheKey = getWeekCacheKey(weekStart);
			const state = queryClient.getQueryState(cacheKey);

			// Refetch if: doesn't exist, is invalidated, or is stale (older than 60s)
			if (!state?.data) return true;
			const isStale = Date.now() - state.dataUpdatedAt > staleTime;
			return isStale || state.isInvalidated;
		});

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
		const scheduleData = await fetchOrganizationSchedule(organization_id!, uncachedWeeks, instance);
		// Cache each week's data separately
		uncachedWeeks.forEach(weekStart => {
			const weekData: IEvent[] = scheduleData[weekStart] || [];
			const cacheKey = getWeekCacheKey(weekStart);
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
		queryKey: ['organizationSchedule', organization_id, keys.join(',')],
		queryFn: organization_id === undefined ? skipToken : fetchUncachedWeeks,
		enabled: organization_id !== undefined,
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
	const queryClient = useQueryClient();

	// Handle WebSocket messages for booking user refresh
	useMessageTypeSubscription('refresh_bookinguser', (message) => {
		console.log('Received booking user refresh WebSocket update');

		// Invalidate the booking user query to trigger a refetch
		// This ensures the user gets the latest data from the server
		queryClient.invalidateQueries({queryKey: ['bookingUser']});
	});

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

			const userData = await response.json();

			// Check if this is a first-time user with minimal data
			// If so, trigger a refetch after a short delay to allow backend initialization to complete
			// This serves as a fallback in case WebSocket notifications fail
			// if (userData.is_logged_in && (!userData.name || userData.name === '')) {
			// 	setTimeout(() => {
			// 		queryClient.invalidateQueries({queryKey: ['bookingUser']});
			// 	}, 2000); // 2 second delay to allow external data fetch to complete
			// }

			return userData;
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
	return useQuery<{ sessionId: string; accountId?: number; ssn?: string }>({
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

/**
 * Hook to create a new booking user
 */
export function useCreateBookingUser() {
	const queryClient = useQueryClient();

	const createBookingUser = async (userData: Partial<IBookingUser>): Promise<IBookingUser> => {
		const response = await fetch('/bookingfrontend/user/create', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify(userData),
		});

		if (!response.ok) {
			const errorData = await response.json().catch(() => ({}));
			throw new Error(errorData.error || 'Failed to create user');
		}

		const result = await response.json();

		// Invalidate and refetch user data after creation
		await queryClient.invalidateQueries({queryKey: ['bookingUser']});

		return result.user;
	};

	return { mutateAsync: createBookingUser };
}

/**
 * Hook to fetch external user data for form pre-filling
 */
export function useExternalUserData() {
	return useQuery({
		queryKey: ['externalUserData'],
		queryFn: async (): Promise<Partial<IBookingUser> | null> => {
			const response = await fetch('/bookingfrontend/user/external-data');

			if (!response.ok) {
				if (response.status === 404) {
					return null; // No external data available
				}
				const errorData = await response.json().catch(() => ({}));
				throw new Error(errorData.error || 'Failed to fetch external data');
			}

			return await response.json();
		},
		retry: false, // Don't retry if external data is not available
		staleTime: 5 * 60 * 1000, // Consider data stale after 5 minutes
	});
}


/**
 * Helper that requests free time data over WebSocket.
 * Returns a Promise that rejects after 10s so the caller can fall back to REST.
 */
function fetchFreeTimeViaWs(
	sendMessage: (type: string, message: string, additionalData?: Record<string, any>) => boolean,
	buildingId: number,
	startDate: string,
	endDate: string,
): Promise<FreeTimeSlotsResponse> {
	const subscriptionManager = SubscriptionManager.getInstance();

	return new Promise((resolve, reject) => {
		const timeout = setTimeout(() => {
			cleanup();
			reject(new Error('WebSocket free_time timeout'));
		}, 10000);

		const cleanup = subscriptionManager.subscribeToMessageType(
			'free_time_response',
			(message) => {
				if (message.type !== 'free_time_response') return;
				// Only handle responses for this specific request
				if (message.data?.buildingId !== buildingId) return;

				clearTimeout(timeout);
				cleanup();
				if (message.data.error === false && message.data.result) {
					resolve(message.data.result as FreeTimeSlotsResponse);
				} else {
					reject(new Error(message.data.message || 'WebSocket free_time error'));
				}
			}
		);

		sendMessage('get_free_time', 'Requesting free time', {
			buildingId,
			startDate,
			endDate,
			detailedOverlap: true,
			stopOnEndDate: true,
		});
	});
}

/**
 * Helper that requests partial applications over WebSocket and resolves
 * with the response.  Returns a Promise that rejects after `timeoutMs`
 * so the caller can fall back to REST.
 */
function fetchPartialApplicationsViaWs(
	sendMessage: (type: string, message: string, additionalData?: Record<string, any>) => boolean
): Promise<{ list: IApplication[], total_sum: number }> {
	const subscriptionManager = SubscriptionManager.getInstance();

	return new Promise((resolve, reject) => {
		const timeout = setTimeout(() => {
			cleanup();
			reject(new Error('WebSocket partial_applications timeout'));
		}, 5000);

		const cleanup = subscriptionManager.subscribeToMessageType(
			'partial_applications_response',
			(message) => {
				if (message.type !== 'partial_applications_response') return;
				clearTimeout(timeout);
				cleanup();
				if (message.data.error === false) {
					resolve({
						list: message.data.applications,
						total_sum: message.data.applications.reduce((sum: number, app: IApplication) => {
							const orderSum = app.orders?.reduce((acc: number, order: any) => acc + (Number(order.sum) || 0), 0) || 0;
							return sum + orderSum;
						}, 0)
					});
				} else {
					reject(new Error('WebSocket partial_applications error'));
				}
			}
		);

		sendMessage('get_partial_applications', 'Requesting partial applications');
	});
}

export function usePartialApplications(): UseQueryResult<{ list: IApplication[], total_sum: number }> {
	const queryClient = useQueryClient();
	const { status: wsStatus, sessionConnected, isReady: wsReady, sendMessage } = useWebSocketContext();
	const lastSeqRef = useRef(0);

	const calcTotalSum = (apps: IApplication[]) =>
		apps.reduce((sum: number, app: IApplication) => {
			const orderSum = app.orders?.reduce((acc: number, order: any) => acc + (Number(order.sum) || 0), 0) || 0;
			return sum + orderSum;
		}, 0);

	// Handle server-pushed partial application updates
	useMessageTypeSubscription('partial_applications_response', (message) => {
		if (message.data.error !== false) return;

		const seq = message.data.seq ?? 0;
		const diff = message.data.diff;

		// If this message has a seq older than what we've already processed, try diff only
		if (seq > 0 && seq < lastSeqRef.current && !diff) {
			return; // Reject stale full update without diff
		}

		if (seq > lastSeqRef.current) {
			lastSeqRef.current = seq;
		}

		// Apply diff for fast incremental update (even if seq is stale,
		// diffs are safe to apply — they add/remove specific IDs)
		if (diff) {
			queryClient.setQueryData<{ list: IApplication[], total_sum: number }>(
				['partialApplications'],
				(prev) => {
					if (!prev) return prev;
					let list = [...prev.list];

					// Remove deleted apps
					if (diff.removed?.length) {
						list = list.filter((app: IApplication) => !diff.removed!.includes(app.id));
					}

					// Add new apps (from full response, avoiding duplicates)
					if (diff.added?.length && message.data.applications) {
						const existingIds = new Set(list.map((a: IApplication) => a.id));
						for (const app of message.data.applications) {
							if (diff.added.includes(app.id) && !existingIds.has(app.id)) {
								list.push(app);
							}
						}
					}

					return { list, total_sum: calcTotalSum(list) };
				}
			);
		}

		// Also set the full list if seq is newest (authoritative update)
		if (seq >= lastSeqRef.current) {
			// Filter out any apps that are pending deletion client-side
			const apps = pendingDeletions.size > 0
				? message.data.applications.filter((a: IApplication) => !pendingDeletions.has(a.id))
				: message.data.applications;
			queryClient.setQueryData(['partialApplications'], {
				list: apps,
				total_sum: calcTotalSum(apps),
			});
		}
	});

	const isWebSocketActive = wsReady && wsStatus === 'OPEN' && sessionConnected;

	return useQuery(
		{
			queryKey: ['partialApplications'],
			queryFn: async () => {
				// Prefer WebSocket when connected — avoids HTTP round-trip
				if (isWebSocketActive) {
					try {
						return await fetchPartialApplicationsViaWs(sendMessage);
					} catch {
						// WebSocket request failed or timed out — fall back to REST
					}
				}
				return fetchPartialApplications();
			},
			retry: 2,
			refetchOnWindowFocus: false,
			refetchInterval: () => {
				return isWebSocketActive ? false : 30000;
			}
		}
	);
}

const WS_PAGE_SIZE = 50;

/**
 * Fetch the first page of delivered applications via WebSocket.
 * Resolves as soon as the first batch arrives so the table can render immediately.
 * Returns a cleanup function and whether more pages are expected.
 */
function fetchFirstPageViaWs(
	sendMessage: (type: string, message: string, additionalData?: Record<string, any>) => boolean,
): Promise<{ list: IApplication[]; total_sum: number; totalCount: number; hasMore: boolean; cleanup: () => void }> {
	const subscriptionManager = SubscriptionManager.getInstance();

	return new Promise((resolve, reject) => {
		const timeout = setTimeout(() => {
			cleanup();
			reject(new Error('WebSocket delivered_applications timeout'));
		}, 8000);

		const cleanup = subscriptionManager.subscribeToMessageType(
			'delivered_applications_response',
			(message) => {
				if (message.type !== 'delivered_applications_response') return;
				clearTimeout(timeout);

				if (message.data.error) {
					cleanup();
					reject(new Error(message.data.message || 'WebSocket delivered_applications error'));
					return;
				}

				const apps = message.data.applications || [];
				const totalCount = message.data.totalCount || 0;
				const hasMore = message.data.hasMore || false;
				const total_sum = apps.reduce((sum: number, app: IApplication) => {
					const orderSum = app.orders?.reduce((acc: number, order: any) => acc + (Number(order.sum) || 0), 0) || 0;
					return sum + orderSum;
				}, 0);

				// Don't cleanup — the caller will reuse the subscription for subsequent pages
				resolve({ list: apps, total_sum, totalCount, hasMore, cleanup });
			}
		);

		sendMessage('get_delivered_applications', 'Requesting delivered applications', {
			offset: 0,
			limit: WS_PAGE_SIZE,
		});
	});
}

export function useApplications(
  options?: {
    initialData?: { list: IApplication[], total_sum: number };
    includeOrganizations?: boolean;
  }
): UseQueryResult<{ list: IApplication[], total_sum: number }> {
	const includeOrganizations = options?.includeOrganizations ?? false;
	const queryClient = useQueryClient();
	const { sessionConnected, isReady: wsReady, sendMessage } = useWebSocketContext();
	const queryKey = ['deliveredApplications', includeOrganizations];
	// Track background pagination so we don't start multiple
	const paginating = useRef(false);

	return useQuery(
		{
			queryKey,
			queryFn: async () => {
				const firstPage = await fetchFirstPageViaWs(sendMessage);

				// If there are more pages, fetch them in the background and
				// progressively update the query cache
				if (firstPage.hasMore && !paginating.current) {
					paginating.current = true;
					const subscriptionManager = SubscriptionManager.getInstance();
					let currentOffset = firstPage.list.length;

					// Subscribe for subsequent page responses
					const cleanupSub = subscriptionManager.subscribeToMessageType(
						'delivered_applications_response',
						(message) => {
							if (message.type !== 'delivered_applications_response') return;
							if (message.data.error) return;

							const newApps = message.data.applications || [];
							const hasMore = message.data.hasMore || false;

							// Append to existing cache
							queryClient.setQueryData<{ list: IApplication[]; total_sum: number }>(
								queryKey,
								(prev) => {
									if (!prev) return prev;
									const existingIds = new Set(prev.list.map(a => a.id));
									const deduped = newApps.filter((a: IApplication) => !existingIds.has(a.id));
									const newList = [...prev.list, ...deduped];
									const newSum = deduped.reduce((sum: number, app: IApplication) => {
										const orderSum = app.orders?.reduce((acc: number, order: any) => acc + (Number(order.sum) || 0), 0) || 0;
										return sum + orderSum;
									}, prev.total_sum);
									return { list: newList, total_sum: newSum };
								}
							);

							if (hasMore) {
								currentOffset += newApps.length;
								sendMessage('get_delivered_applications', 'Fetching next page', {
									offset: currentOffset,
									limit: WS_PAGE_SIZE,
								});
							} else {
								// All pages received
								cleanupSub();
								paginating.current = false;
							}
						}
					);

					// Request second page
					sendMessage('get_delivered_applications', 'Fetching next page', {
						offset: currentOffset,
						limit: WS_PAGE_SIZE,
					});

					// Clean up first page subscription
					firstPage.cleanup();
				} else {
					firstPage.cleanup();
				}

				return { list: firstPage.list, total_sum: firstPage.total_sum };
			},
			// Only fetch once WS session is ready
			enabled: wsReady && sessionConnected,
			retry: 2,
			refetchOnWindowFocus: false,
			initialData: options?.initialData,
		}
	);
}

/**
 * Fetch a single application via WebSocket.
 * Resolves with the application data or rejects on error/timeout.
 */
function fetchApplicationViaWs(
	sendMessage: (type: string, message: string, additionalData?: Record<string, any>) => boolean,
	id: number,
	secret?: string,
): Promise<IApplication> {
	const subscriptionManager = SubscriptionManager.getInstance();

	return new Promise((resolve, reject) => {
		const timeout = setTimeout(() => {
			cleanup();
			reject(new Error('WebSocket application_detail timeout'));
		}, 8000);

		const cleanup = subscriptionManager.subscribeToMessageType(
			'application_detail_response',
			(message) => {
				if (message.type !== 'application_detail_response') return;
				// Only handle responses for our specific application ID
				if (message.data.id !== undefined && message.data.id !== id) return;

				clearTimeout(timeout);
				cleanup();

				if (message.data.error || !message.data.application) {
					reject(new Error(message.data.message || 'Application not found'));
					return;
				}

				resolve(message.data.application);
			}
		);

		sendMessage('get_application_detail', 'Requesting application detail', {
			id,
			...(secret && { secret }),
		});
	});
}

export function useApplication(
    id: number,
    options?: { initialData?: IApplication; secret?: string }
): UseQueryResult<IApplication> {
	const { sessionConnected, isReady: wsReady, sendMessage } = useWebSocketContext();

    return useQuery(
        {
            queryKey: ['application', id, options?.secret],
            queryFn: () => fetchApplicationViaWs(sendMessage, id, options?.secret),
            // Only fetch once WS session is ready
            enabled: wsReady && sessionConnected,
            retry: 2,
            refetchOnWindowFocus: false,
            initialData: options?.initialData,
        }
    );
}

/**
 * Add an application comment via WebSocket.
 * Resolves with the created comment, rejects on error/timeout.
 * The server also broadcasts a `new_comment` entity_event to the application
 * room, which the detail page subscription uses to refetch the thread.
 */
function addApplicationCommentViaWs(
    sendMessage: (type: string, message: string, additionalData?: Record<string, any>) => boolean,
    applicationId: number,
    comment: string,
    secret?: string,
): Promise<ApplicationComment> {
    const subscriptionManager = SubscriptionManager.getInstance();
    const requestId = `comment_${applicationId}_${Date.now()}_${Math.random().toString(36).slice(2)}`;

    return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
            cleanup();
            reject(new Error('WebSocket add_comment timeout'));
        }, 8000);

        const cleanup = subscriptionManager.subscribeToMessageType(
            'add_comment_response',
            (message: any) => {
                if (message.type !== 'add_comment_response') return;
                if (message.requestId !== requestId) return;

                clearTimeout(timeout);
                cleanup();

                if (message.data?.error || !message.data?.comment) {
                    reject(new Error(message.data?.message || 'Failed to add comment'));
                    return;
                }
                resolve(message.data.comment);
            }
        );

        const sent = sendMessage('add_application_comment', 'Adding application comment', {
            applicationId,
            comment,
            ...(secret && { secret }),
            requestId,
        });

        if (!sent) {
            clearTimeout(timeout);
            cleanup();
            reject(new Error('WebSocket not connected'));
        }
    });
}

/**
 * Hook to add an application comment over WebSocket.
 * Mirrors the shape of useAddApplicationComment so it is a drop-in replacement.
 */
export function useAddApplicationCommentWs() {
    const { sendMessage } = useWebSocketContext();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ applicationId, comment, secret }: { applicationId: number; comment: string; secret?: string }) =>
            addApplicationCommentViaWs(sendMessage, applicationId, comment, secret),
        onSuccess: (_data, vars) => {
            queryClient.invalidateQueries({ queryKey: ['applicationComments', vars.applicationId] });
        },
    });
}

/**
 * Hook to fetch comments for an application
 * @param applicationId The application ID
 * @param types Optional comma-separated list of comment types to filter by
 * @param secret Optional secret for external access
 * @returns Comments and statistics
 */
export function useApplicationComments(
    applicationId: number,
    types?: string,
    secret?: string
): UseQueryResult<GetCommentsResponse> {
    return useQuery({
        queryKey: ['applicationComments', applicationId, types, secret],
        queryFn: () => fetchApplicationComments(applicationId, types, secret),
        retry: 2,
        refetchOnWindowFocus: false,
    });
}

/**
 * Hook to fetch events, allocations and bookings related to an application
 * @param applicationId The application ID
 * @param secret Optional secret for external access
 * @returns Query result with events, allocations and bookings
 */
export function useApplicationScheduleEntities(
    applicationId: number,
    secret?: string
): UseQueryResult<{events: IAPIEvent[], allocations: IAPIAllocation[], bookings: IAPIBooking[]}> {
    return useQuery({
        queryKey: ['applicationEventsAllocationsBookings', applicationId, secret],
        queryFn: () => fetchApplicationScheduleEntities(applicationId, secret),
        retry: 2,
        refetchOnWindowFocus: false,
    });
}

/**
 * Hook to add a comment to an application
 * @param options Mutation options
 * @returns Mutation object for adding comments
 */
export function useAddApplicationComment(
    options?: MutationOptions<AddCommentResponse, Error, { applicationId: number; commentData: AddCommentRequest; secret?: string }>
) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ applicationId, commentData, secret }) =>
            addApplicationComment(applicationId, commentData, secret),
        onSuccess: (data, variables) => {
            // Invalidate comments cache
            queryClient.invalidateQueries({
                queryKey: ['applicationComments', variables.applicationId]
            });

            // Invalidate application cache to refresh any related data
            queryClient.invalidateQueries({
                queryKey: ['application', variables.applicationId]
            });
        },
        ...options,
    });
}

/**
 * Hook to update an application's status
 * @param options Mutation options
 * @returns Mutation object for updating status
 */
export function useUpdateApplicationStatus(
    options?: MutationOptions<UpdateStatusResponse, Error, { applicationId: number; statusData: UpdateStatusRequest; secret?: string }>
) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ applicationId, statusData, secret }) =>
            updateApplicationStatus(applicationId, statusData, secret),
        onSuccess: (data, variables) => {
            // Invalidate comments cache (status changes create comments)
            queryClient.invalidateQueries({
                queryKey: ['applicationComments', variables.applicationId]
            });

            // Invalidate application cache to refresh status
            queryClient.invalidateQueries({
                queryKey: ['application', variables.applicationId]
            });

            // Invalidate applications list cache
            queryClient.invalidateQueries({
                queryKey: ['applications']
            });
        },
        ...options,
    });
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


export function useInvoices(
    options?: { initialData?: ICompletedReservation[] }
): UseQueryResult<ICompletedReservation[]> {
    return useQuery(
        {
            queryKey: ['invoices'],
            queryFn: () => fetchInvoices(), // Fetch function
            retry: 2, // Number of retry attempts if the query fails
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
            initialData: options?.initialData,
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

function deletePartialViaWs(
	sendMessage: (type: string, message: string, additionalData?: Record<string, any>) => boolean,
	applicationId: number,
): Promise<number> {
	const subscriptionManager = SubscriptionManager.getInstance();
	const requestId = `delete_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

	return new Promise((resolve, reject) => {
		const timeout = setTimeout(() => {
			cleanup();
			reject(new Error('WebSocket delete timeout'));
		}, 15000);

		const cleanup = subscriptionManager.subscribeToMessageType(
			'delete_application_response',
			(message) => {
				if (message.type !== 'delete_application_response') return;
				if ((message as any).requestId !== requestId) return;
				clearTimeout(timeout);
				cleanup();
				if (message.data.error === false) {
					resolve(applicationId);
				} else {
					reject(new Error(message.data.message || 'Delete failed'));
				}
			},
		);

		sendMessage('delete_partial_application', 'Deleting partial application', {
			applicationId,
			requestId,
		});
	});
}

export function useDeletePartialApplication() {
	const queryClient = useQueryClient();
	const { status: wsStatus, sessionConnected, isReady: wsReady, sendMessage } = useWebSocketContext();

	return useServerMessageMutation({
		mutationFn: async (id: number) => {
			const isWebSocketActive = wsReady && wsStatus === 'OPEN' && sessionConnected;

			if (isWebSocketActive) {
				try {
					return await deletePartialViaWs(sendMessage, id);
				} catch (err) {
					if (err instanceof Error && !err.message.includes('timeout')) {
						throw err;
					}
				}
			}

			// REST fallback
			const url = phpGWLink(['bookingfrontend', 'applications', id]);
			const response = await fetch(url, {method: 'DELETE'});
			if (!response.ok) {
				throw new Error('Failed to delete partial application');
			}
			return id;
		},
		onMutate: async (id: number) => {
			pendingDeletions.add(id);
			await queryClient.cancelQueries({queryKey: ['partialApplications']});

			const previousApplications = queryClient.getQueryData<{
				list: IApplication[],
				total_sum: number
			}>(['partialApplications']);

			if (previousApplications) {
				queryClient.setQueryData(['partialApplications'], {
					...previousApplications,
					list: previousApplications.list.filter(app => app.id !== id),
				});
			}

			return {previousApplications};
		},
		onError: (_err, variables, context) => {
			pendingDeletions.delete(variables);
			if (context?.previousApplications) {
				queryClient.setQueryData(['partialApplications'], context.previousApplications);
			}
		},
		onSuccess: (_data, variables) => {
			pendingDeletions.delete(variables);
			const isWebSocketActive = wsReady && wsStatus === 'OPEN' && sessionConnected;
			if (!isWebSocketActive) {
				queryClient.invalidateQueries({queryKey: ['partialApplications']});
			}
		},
		onSettled: () => {
			const isWebSocketActive = wsReady && wsStatus === 'OPEN' && sessionConnected;
			if (!isWebSocketActive) {
				queryClient.invalidateQueries({queryKey: ['partialApplications']});
			}
		},
	});
}


/**
 * Create a simple application via WebSocket.
 * Returns a promise that resolves with the response or rejects on error/timeout.
 */
function createSimpleApplicationViaWs(
	sendMessage: (type: string, message: string, additionalData?: Record<string, any>) => boolean,
	timeslot: IFreeTimeSlot,
	buildingId: number,
	queryClient: ReturnType<typeof useQueryClient>,
): Promise<{ id: number; status: string; message: string }> {
	const subscriptionManager = SubscriptionManager.getInstance();
	const requestId = `booking_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

	return new Promise((resolve, reject) => {
		const timeout = setTimeout(() => {
			cleanup();
			reject(new Error('WebSocket booking timeout'));
		}, 15000);

		const cleanup = subscriptionManager.subscribeToMessageType(
			'create_application_response',
			(message) => {
				if (message.type !== 'create_application_response') return;
				// Only match our request
				if (message.requestId !== requestId) return;
				clearTimeout(timeout);
				cleanup();
				if (message.data.error === false) {
					const appId = message.data.id!;

					// Optimistically patch the timeslot cache immediately —
					// don't wait for the slower partial_applications_response + room_message
					const resourceId = String(timeslot.resource_id);
					const allQueries = queryClient.getQueriesData<FreeTimeSlotsResponse>({
						queryKey: ['buildingFreeTime', buildingId],
					});
					for (const [qk, data] of allQueries) {
						if (!data?.[resourceId]) continue;
						queryClient.setQueryData(qk, patchWeekCache(data, {
							[resourceId]: [{
								start: timeslot.start,
								end: timeslot.end,
								when: timeslot.when,
								start_iso: timeslot.start_iso,
								end_iso: timeslot.end_iso,
								overlap: 2,
								overlap_reason: 'complete_overlap',
								overlap_type: 'complete',
								overlap_event: {
									id: appId,
									type: 'application',
									status: 'NEWPARTIAL1',
									from: timeslot.start,
									to: timeslot.end,
								},
							}],
						}));
					}

					// Optimistically add to partial applications so the delete button shows
					queryClient.setQueryData<{ list: IApplication[], total_sum: number }>(
						['partialApplications'],
						(prev) => {
							if (!prev) return prev;
							// Add a minimal placeholder — the full data arrives with partial_applications_response
							const placeholder = { id: appId, status: 'NEWPARTIAL1' } as IApplication;
							return {
								list: [...prev.list, placeholder],
								total_sum: prev.total_sum,
							};
						},
					);

					resolve({
						id: message.data.id!,
						status: message.data.status || 'NEWPARTIAL1',
						message: message.data.message || 'Created',
					});
				} else {
					reject(new Error(message.data.message || 'Booking failed'));
				}
			},
		);

		sendMessage('create_simple_application', 'Creating simple application', {
			resourceId: timeslot.resource_id,
			buildingId,
			from: timeslot.start,
			to: timeslot.end,
			requestId,
		});
	});
}

export function useCreateSimpleApplication() {
	const queryClient = useQueryClient();
	const { status: wsStatus, sessionConnected, isReady: wsReady, sendMessage } = useWebSocketContext();

	return useServerMessageMutation({
		mutationFn: async (params: { timeslot: IFreeTimeSlot, building_id: number }) => {
			const isWebSocketActive = wsReady && wsStatus === 'OPEN' && sessionConnected;

			// Prefer WebSocket — avoids HTTP round-trip and gets atomic Redis lock + DB transaction
			if (isWebSocketActive) {
				try {
					return await createSimpleApplicationViaWs(
						sendMessage, params.timeslot, params.building_id, queryClient,
					);
				} catch (err) {
					// If the WS error is a real booking error (not a timeout), throw it
					if (err instanceof Error && !err.message.includes('timeout')) {
						throw err;
					}
					// Timeout — fall back to REST
				}
			}

			// REST fallback — queue via Redis, poll for result
			const url = phpGWLink(['bookingfrontend', 'applications', 'simple']);
			const postResponse = await fetch(url, {
				method: 'POST',
				body: JSON.stringify({
					from: params.timeslot.start,
					to: params.timeslot.end,
					resource_id: params.timeslot.resource_id,
					building_id: params.building_id,
				}),
				headers: { 'Content-Type': 'application/json' },
			});

			if (postResponse.status !== 202) {
				const errorData = await postResponse.json().catch(() => ({}));
				throw new Error(errorData.error || 'Failed to queue booking');
			}

			const { requestId } = await postResponse.json();

			// Poll for result from Node FIFO queue
			const statusUrl = phpGWLink(['bookingfrontend', 'applications', 'simple', 'status', requestId]);
			const maxAttempts = 30; // 15 seconds at 500ms intervals
			for (let attempt = 0; attempt < maxAttempts; attempt++) {
				await new Promise(r => setTimeout(r, 500));

				const statusResponse = await fetch(statusUrl);
				const statusData = await statusResponse.json();

				if (statusData.status === 'pending') continue;

				if (statusData.error) {
					throw new Error(statusData.message || 'Booking failed');
				}

				return {
					id: statusData.id,
					status: statusData.status,
					message: statusData.message || 'Created',
				};
			}

			throw new Error('Booking timed out');
		},
		onSuccess: (_data, variables) => {
			const isWebSocketActive = wsReady && wsStatus === 'OPEN' && sessionConnected;
			// When WS is active, server pushes handle updates (partial_apps_response
			// + room_message). Only invalidate when WS is not available.
			if (!isWebSocketActive) {
				queryClient.invalidateQueries({queryKey: ['partialApplications']});
				if (variables.building_id) {
					queryClient.invalidateQueries({
						predicate: (query) =>
							Array.isArray(query.queryKey) &&
							query.queryKey[0] === 'buildingFreeTime' &&
							query.queryKey[1] === variables.building_id,
					});
				}
			}
		},
		onError: (_error, variables) => {
			queryClient.invalidateQueries({queryKey: ['partialApplications']});
			if (variables.building_id) {
				queryClient.invalidateQueries({
					predicate: (query) =>
						Array.isArray(query.queryKey) &&
						query.queryKey[0] === 'buildingFreeTime' &&
						query.queryKey[1] === variables.building_id,
				});
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

export function useResourceRegulationDocuments(resources: { id: number, building_id?: number | null }[]) {
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
						const docs = await fetchResourceDocuments(resourceId, ['regulation', 'HMS_document', 'price_list']);

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
						const docs = await fetchBuildingDocuments(buildingId, ['regulation', 'HMS_document', 'price_list']);

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

export function useBuildingDocuments(buildingId: string) {
	return useQuery({
		queryKey: ['buildingDocuments', buildingId],
		queryFn: async () => {
			try {
				// Fetch building documents (excluding only pictures)
				const buildingDocs = await fetchBuildingDocuments(buildingId, ['drawing', 'price_list', 'other', 'regulation', 'HMS_document']);

				// Add owner type to identify the document source
				const docsWithType = buildingDocs.map((doc: IDocument) => ({
					...doc,
					owner_type: 'building' as const
				}));

				return docsWithType;
			} catch (error) {
				console.error(`Error fetching documents for building ${buildingId}:`, error);
				return [];
			}
		},
		enabled: Boolean(buildingId),
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

/**
 * Hook to fetch multi-domains data using the dedicated endpoint
 * @param options - Query options including initialData for server-side rendering
 */
export function useMultiDomains(options?: {
	initialData?: IMultiDomain[]
}): UseQueryResult<IMultiDomain[]> {
	return useQuery(
		{
			queryKey: ['multiDomains'],
			queryFn: () => fetchMultiDomains(), // Fetch function
			retry: 2, // Number of retry attempts if the query fails
			staleTime: 60 * 60 * 1000, // Consider data fresh for 1 hour (cached)
			refetchOnWindowFocus: false, // Do not refetch on window focus by default
			initialData: options?.initialData, // Use server-side fetched data if available
		}
	);
}

/**
 * Hook to fetch available resources for a specific date
 * @param date - The date to check availability for (format: YYYY-MM-DD)
 * @returns Query result containing array of available resource IDs
 */
export function useAvailableResources(date?: string): UseQueryResult<number[]> {
	return useQuery(
		{
			queryKey: ['availableResources', date],
			queryFn: date ? () => fetchAvailableResources(date) : skipToken,
			retry: 2, // Number of retry attempts if the query fails
			staleTime: 5 * 60 * 1000, // Consider data fresh for 5 minutes
			refetchOnWindowFocus: false, // Do not refetch on window focus by default
			enabled: !!date, // Only run query if date is provided
		}
	);
}

/**
 * Hook to fetch available resources for a specific date across all domains
 * @param date - The date to check availability for (format: YYYY-MM-DD)
 * @param multiDomains - Array of domain configurations
 * @returns Query result containing map of domain names to available resource IDs
 */
export function useAvailableResourcesMultiDomain(
	date?: string,
	multiDomains?: IMultiDomain[]
): UseQueryResult<Record<string, number[]>> {
	return useQuery(
		{
			queryKey: ['availableResourcesMultiDomain', date, multiDomains?.map(d => d.name).join(',')],
			queryFn: (date && multiDomains) ? () => fetchAvailableResourcesMultiDomain(date, multiDomains) : skipToken,
			retry: 2, // Number of retry attempts if the query fails
			staleTime: 5 * 60 * 1000, // Consider data fresh for 5 minutes
			refetchOnWindowFocus: false, // Do not refetch on window focus by default
			enabled: !!(date && multiDomains), // Only run query if date and domains are provided
		}
	);
}

/**
 * Hook to fetch documents for an application
 * @param applicationId The application ID
 * @param typeFilter Optional document type filter(s)
 * @returns Query result containing array of documents
 */
export function useApplicationDocuments(
	applicationId: number | string,
	typeFilter?: IDocumentCategoryQuery | IDocumentCategoryQuery[]
): UseQueryResult<IDocument[]> {
	return useQuery({
		queryKey: ['applicationDocuments', applicationId, typeFilter],
		queryFn: () => fetchApplicationDocuments(applicationId, typeFilter),
		retry: 2,
		refetchOnWindowFocus: false,
	});
}

interface UseNotificationsParams {
	unread?: boolean;
	limit?: number;
	offset?: number;
	/** Gate the request — e.g. only fetch the list once the dropdown is opened. */
	enabled?: boolean;
}

/**
 * Fetch a page of notifications, newest first.
 */
export function useNotifications(
	params: UseNotificationsParams = {}
): UseQueryResult<INotificationListResponse> {
	const {unread, limit = 10, offset = 0, enabled = true} = params;

	return useQuery<INotificationListResponse>({
		enabled,
		queryKey: ['notifications', {unread: unread ?? null, limit, offset}],
		queryFn: async () => {
			const args: Record<string, string | number> = {limit, offset};
			if (unread !== undefined) {
				args.unread = unread ? 1 : 0;
			}
			const url = phpGWLink(['bookingfrontend', 'notifications'], args);

			const response = await fetch(url, {credentials: 'include'});
			if (!response.ok) {
				throw new Error('Failed to fetch notifications');
			}
			return response.json();
		},
		retry: 2,
		refetchOnWindowFocus: true,
	});
}

/**
 * Unread notification count + per-application breakdown.
 *
 * No polling: the count is kept fresh by WebSocket pushes. The WS server emits a
 * `notification_event` to the user's identity room whenever a notification is
 * created, so we invalidate the count (and any open list) on that channel.
 */
export function useUnreadNotificationCount(): UseQueryResult<IUnreadCountResponse> {
	const queryClient = useQueryClient();

	useMessageTypeSubscription('notification_event', () => {
		queryClient.invalidateQueries({queryKey: ['unreadNotificationCount']});
		queryClient.invalidateQueries({queryKey: ['notifications']});
	});

	return useQuery<IUnreadCountResponse>({
		queryKey: ['unreadNotificationCount'],
		queryFn: async () => {
			const url = phpGWLink(['bookingfrontend', 'notifications', 'unread-count']);
			const response = await fetch(url, {credentials: 'include'});
			if (!response.ok) {
				throw new Error('Failed to fetch unread notification count');
			}
			return response.json();
		},
		retry: 2,
		refetchOnWindowFocus: true,
	});
}

/**
 * Mark every notification for a given entity as read.
 * Plain helper (not a hook) so it can be called from event handlers; the caller
 * is responsible for invalidating ['unreadNotificationCount'] and ['notifications'].
 */
export async function markNotificationsAsRead(
	entityType: string,
	entityId: number,
): Promise<IMarkReadResponse> {
	const url = phpGWLink(['bookingfrontend', 'notifications', entityType, entityId, 'mark-read']);
	const response = await fetch(url, {
		method: 'PUT',
		credentials: 'include',
	});
	if (!response.ok) {
		throw new Error('Failed to mark notifications as read');
	}
	return response.json();
}

/**
 * Mark several entities as read in one go (used by "Marker alle som lest").
 * De-duplicates by entity so we issue one PUT per (entity_type, entity_id).
 */
export async function markNotificationGroupsAsRead(
	notifications: Pick<INotification, 'entity_type' | 'entity_id'>[],
): Promise<void> {
	const seen = new Set<string>();
	const tasks: Promise<IMarkReadResponse>[] = [];
	for (const n of notifications) {
		const key = `${n.entity_type}:${n.entity_id}`;
		if (seen.has(key)) continue;
		seen.add(key);
		tasks.push(markNotificationsAsRead(n.entity_type, n.entity_id));
	}
	await Promise.all(tasks);
}