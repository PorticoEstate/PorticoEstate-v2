import {ISearchDataOptimized, ISearchOrganization} from "@/service/types/api/search.types";
import {phpGWLink} from "@/service/util";
import { unstable_cache } from 'next/cache';
import {IShortEvent} from '@/service/pecalendar.types';

// Use Next.js cache function to memoize the fetch result
export const fetchSearchData = unstable_cache(
	async (): Promise<ISearchDataOptimized> => {
		const url = phpGWLink(['bookingfrontend', 'searchdataalloptimised']);
		const response = await fetch(url);

		if (!response.ok) {
			throw new Error(`Failed to fetch search data: ${response.status}`);
		}

		const result = await response.json();
		console.log("SEARCH DATA FETCHED - Should only see this once per hour");
		return result;
	},
	['search-data'], // A unique cache key
	{ revalidate: 3600 }
);

/**
 * Server-side function to fetch upcoming events
 * This is used for initial data loading on the server
 */
export const fetchUpcomingEventsStatic = async (fromDate?: string, toDate?: string): Promise<IShortEvent[]> => {
	// Build query parameters
	const queryParams = new URLSearchParams();

	if (fromDate) queryParams.append('fromDate', fromDate);
	if (toDate) queryParams.append('toDate', toDate);

	// Default limit
	queryParams.append('limit', '1000');

	const url = phpGWLink(['bookingfrontend', 'events', 'upcoming'], Object.fromEntries(queryParams.entries()));
	const response = await fetch(url, {
		headers: {
			'Content-Type': 'application/json',
			'Accept': 'application/json'
		},
		cache: 'no-store' // Ensure fresh data on each server load
	});

	if (!response.ok) {
		console.error(`Failed to fetch upcoming events: ${response.status}`);
		return []; // Return empty array rather than throwing to improve resilience
	}

	return await response.json() as IShortEvent[];
};

/**
 * Server-side function to fetch organizations data
 * This is used for initial data loading on the server
 * Uses Next.js cache function to memoize the fetch result for 1 hour
 */
export const fetchOrganizationsStatic = unstable_cache(
	async (): Promise<ISearchOrganization[]> => {
		const url = phpGWLink(['bookingfrontend', 'organizations']);
		const response = await fetch(url);

		if (!response.ok) {
			throw new Error(`Failed to fetch organizations data: ${response.status}`);
		}

		const result = await response.json();
		console.log("ORGANIZATIONS DATA FETCHED - Should only see this once per hour");
		return result;
	},
	['organizations-data'], // A unique cache key
	{ revalidate: 3600 }
);