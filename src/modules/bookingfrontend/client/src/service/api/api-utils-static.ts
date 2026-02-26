import {
	ISearchDataOptimized,
	ISearchDataTown,
	ISearchOrganization,
	ISearchResource
} from "@/service/types/api/search.types";
import {phpGWLink} from "@/service/util";
import {unstable_cache} from 'next/cache';
import {IShortEvent} from '@/service/pecalendar.types';
import {IMultiDomain} from "@/service/types/api.types";
import {extractBaseUrl} from "@/service/multi-domain-utils";

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
	{
		revalidate: 3600,
		tags: ['search-data', 'resources', 'buildings', 'images']
	}
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
	{
		revalidate: 3600,
		tags: ['organizations', 'search-data']
	}
);

/**
 * Server-side function to fetch multi-domains data
 * This is used for initial data loading on the server
 * Uses Next.js cache function to memoize the fetch result for 1 hour
 */
export const fetchMultiDomainsStatic = unstable_cache(
	async (): Promise<IMultiDomain[]> => {
		const url = phpGWLink(['bookingfrontend', 'multi-domains']);
		const response = await fetch(url);

		if (!response.ok) {
			console.error(`Failed to fetch multi-domains: ${response.status}`);
			return []; // Return empty array rather than throwing to improve resilience
		}

		const result = await response.json();
		console.log("MULTI-DOMAINS DATA FETCHED - Should only see this once per hour");
		return result.results || [];
	},
	['multi-domains-data'], // A unique cache key
	{
		revalidate: 3600,
		tags: ['multi-domains']
	}
);

/**
 * Creates a phpGWLink-style URL for external domains
 */
export function createExternalDomainUrl(domain: IMultiDomain, strURL: string | (string | number)[],
								 oArgs: Record<string, string | number | boolean | (string | number)[]> | null = {},
								 bAsJSON: boolean = true): string {
	const baseUrl = extractBaseUrl(domain.webservicehost);
	return phpGWLink(strURL, oArgs, bAsJSON, baseUrl);
	// Create URL similar to phpGWLink but for external domain
	// const pathStr = path.join('/');
	// return `${baseUrl}/?click_history=165dde2af0dd4b589e3a3c8e26f0da86/${pathStr}?phpgw_return_as=json`;
}

/**
 * Creates a unique ID for external domain entities to avoid ID collisions
 * Format: "domain_{domain_id}_{original_id}"
 */
function createDomainUniqueId(domainId: number, originalId: number): number {
	// Use a large offset to ensure no collisions with local IDs
	// Domain ID in the thousands place, original ID in the ones place
	return domainId * 1000000 + originalId;
}

/**
 * Fetches search data from a specific domain
 */
async function fetchDomainSearchData(domain: IMultiDomain): Promise<ISearchDataOptimized | null> {
	try {
		const searchUrl = createExternalDomainUrl(domain, ['bookingfrontend', 'searchdataalloptimised']);

		const response = await fetch(searchUrl, {
			headers: {
				'Accept': 'application/json',
				'Content-Type': 'application/json'
			},
			// Add timeout to prevent hanging requests
			signal: AbortSignal.timeout(10000) // 10 second timeout
		});
		if (!response.ok) {
			console.warn(`Failed to fetch search data from ${domain.name}: ${response.status}`);
			return null;
		}

		const data: ISearchDataOptimized = await response.json();
		const baseUrl = extractBaseUrl(domain.webservicehost);

		// Transform IDs to prevent collisions and add domain information
		if (data.resources) {
			data.resources = data.resources.map((resource: ISearchResource) => ({
				...resource,
				id: createDomainUniqueId(domain.id, resource.id),
				// Transform related IDs
				activity_id: resource.activity_id ? createDomainUniqueId(domain.id, resource.activity_id) : null,
				rescategory_id: resource.rescategory_id ? createDomainUniqueId(domain.id, resource.rescategory_id) : null,
				domain_name: domain.name,
				domain_url: baseUrl,
				original_id: resource.id // Keep original ID for redirects
			}));
		}

		// Transform towns to prevent ID collisions
		if (data.towns) {
			data.towns = data.towns.map((town: ISearchDataTown) => ({
				...town,
				id: createDomainUniqueId(domain.id, town.id),
				original_id: town.id,
				domain_name: domain.name
			}));
		}

		// Transform buildings to prevent ID collisions
		if (data.buildings) {
			data.buildings = data.buildings.map((building) => ({
				...building,
				id: createDomainUniqueId(domain.id, building.id),
				town_id: building.town_id ? createDomainUniqueId(domain.id, building.town_id) : building.town_id,
				activity_id: building.activity_id ? createDomainUniqueId(domain.id, building.activity_id) : building.activity_id,
				original_id: building.id,
				domain_name: domain.name
			}));
		}

		// Transform building_resources relationships
		if (data.building_resources) {
			data.building_resources = data.building_resources.map(br => ({
				...br,
				building_id: createDomainUniqueId(domain.id, br.building_id),
				resource_id: createDomainUniqueId(domain.id, br.resource_id)
			}));
		}

		// Transform activities to prevent ID collisions
		if (data.activities) {
			data.activities = data.activities.map(activity => ({
				...activity,
				id: createDomainUniqueId(domain.id, activity.id),
				parent_id: activity.parent_id ? createDomainUniqueId(domain.id, activity.parent_id) : activity.parent_id,
				original_id: activity.id,
				domain_name: domain.name
			}));
		}

		// Transform facilities to prevent ID collisions
		if (data.facilities) {
			data.facilities = data.facilities.map(facility => ({
				...facility,
				id: createDomainUniqueId(domain.id, facility.id),
				original_id: facility.id,
				domain_name: domain.name
			}));
		}

		// Transform resource_activities relationships
		if (data.resource_activities) {
			data.resource_activities = data.resource_activities.map(ra => ({
				...ra,
				resource_id: createDomainUniqueId(domain.id, ra.resource_id),
				activity_id: createDomainUniqueId(domain.id, ra.activity_id)
			}));
		}

		// Transform resource_facilities relationships
		if (data.resource_facilities) {
			data.resource_facilities = data.resource_facilities.map(rf => ({
				...rf,
				resource_id: createDomainUniqueId(domain.id, rf.resource_id),
				facility_id: createDomainUniqueId(domain.id, rf.facility_id)
			}));
		}

		// Transform resource_categories to prevent ID collisions
		if (data.resource_categories) {
			data.resource_categories = data.resource_categories.map(rc => ({
				...rc,
				id: createDomainUniqueId(domain.id, rc.id),
				parent_id: rc.parent_id ? createDomainUniqueId(domain.id, rc.parent_id) : rc.parent_id,
				original_id: rc.id,
				domain_name: domain.name
			}));
		}

		// Transform resource_category_activity relationships
		if (data.resource_category_activity) {
			data.resource_category_activity = data.resource_category_activity.map(rca => ({
				...rca,
				rescategory_id: createDomainUniqueId(domain.id, rca.rescategory_id),
				activity_id: createDomainUniqueId(domain.id, rca.activity_id)
			}));
		}

		return data;
	} catch (error) {
		console.warn(`Error fetching search data from ${domain.name}:`, error);
		return null;
	}
}

/**
 * Server-side function to fetch and merge search data from all domains
 * This combines local search data with data from other domains
 */
export const fetchSearchDataWithMultiDomains = unstable_cache(
	async (multiDomains: IMultiDomain[] | undefined): Promise<ISearchDataOptimized> => {
		// Fetch local search data and multi-domains in parallel
		const localSearchData = await fetchSearchData()

		// If no multi-domains, return local data only
		if (!multiDomains || multiDomains.length === 0) {
			return localSearchData;
		}

		// Fetch search data from all other domains in parallel
		const domainDataPromises = multiDomains.map(domain => fetchDomainSearchData(domain));
		const domainDataResults = await Promise.allSettled(domainDataPromises);

		// Collect successfully fetched domain data
		const domainSearchData: ISearchDataOptimized[] = domainDataResults
			.filter((result): result is PromiseFulfilledResult<ISearchDataOptimized> =>
				result.status === 'fulfilled' && result.value !== null
			)
			.map(result => result.value);

		// Merge all search data, including all arrays to provide full cross-domain search
		const mergedData: ISearchDataOptimized = {
			...localSearchData,
			resources: [
				...localSearchData.resources,
				...domainSearchData.flatMap(data => data.resources || [])
			],
			towns: [
				...localSearchData.towns,
				...domainSearchData.flatMap(data => data.towns || [])
			],
			buildings: [
				...localSearchData.buildings,
				...domainSearchData.flatMap(data => data.buildings || [])
			],
			building_resources: [
				...localSearchData.building_resources,
				...domainSearchData.flatMap(data => data.building_resources || [])
			],
			activities: [
				...localSearchData.activities,
				...domainSearchData.flatMap(data => data.activities || [])
			],
			facilities: [
				...localSearchData.facilities,
				...domainSearchData.flatMap(data => data.facilities || [])
			],
			resource_activities: [
				...localSearchData.resource_activities,
				...domainSearchData.flatMap(data => data.resource_activities || [])
			],
			resource_facilities: [
				...localSearchData.resource_facilities,
				...domainSearchData.flatMap(data => data.resource_facilities || [])
			],
			resource_categories: [
				...localSearchData.resource_categories,
				...domainSearchData.flatMap(data => data.resource_categories || [])
			],
			resource_category_activity: [
				...localSearchData.resource_category_activity,
				...domainSearchData.flatMap(data => data.resource_category_activity || [])
			],
		};

		return mergedData;
	},
	['search-data-with-multi-domains'], // A unique cache key
	{
		revalidate: 3600,
		tags: ['search-data', 'resources', 'buildings', 'images']
	}
);