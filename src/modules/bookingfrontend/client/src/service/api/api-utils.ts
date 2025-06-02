import {DateTime} from "luxon";
import {phpGWLink} from "@/service/util";
import {IBookingUser, IServerSettings, IMultiDomain} from "@/service/types/api.types";
import {IApplication} from "@/service/types/api/application.types";
import {getQueryClient} from "@/service/query-client";
import {ICompletedReservation} from "@/service/types/api/invoices.types";
import {IEvent, IFreeTimeSlot, IShortEvent} from "@/service/pecalendar.types";
import {IAgeGroup, IAudience, Season} from "@/service/types/Building";
import {BrregOrganization, IOrganization} from "@/service/types/api/organization.types";
import {IServerMessage} from "@/service/types/api/server-messages.types";
import {ISearchDataOptimized, ISearchDataTown, ISearchOrganization} from "@/service/types/api/search.types";
import {IArticle} from "@/service/types/api/order-articles.types";



const FetchAuthOptions = (defaultOptions?: RequestInit) => {

    let options = {};

    if (typeof window === 'undefined') {
        const cookies = require("next/headers").cookies
        options =  {
            headers: {
                Cookie: cookies().toString(),
            },
            credentials: 'include',
            cache: 'no-store',
        }
    }
    return {...options, ...defaultOptions};
}


const BOOKING_MONTH_HORIZON = 2;


export async function fetchBuildingScheduleOLD(building_id: number, dates: string[], instance?: string) {
    const url = phpGWLink('bookingfrontend/', {
        menuaction: 'bookingfrontend.uibooking.building_schedule_pe',
        building_id,
        dates: dates,
    }, true, instance);

    const response = await fetch(url);
    const result = await response.json();
    return result?.ResultSet?.Result?.results;
}




/**
 *
 * @param building_id
 * @param dates
 * @param instance
 * @return {[First day of week str]: IEvents[] for that week}
 */
export async function fetchBuildingSchedule(building_id: number, dates: string[], instance?: string): Promise<Record<string, IEvent[]>> {


    const url = phpGWLink(['bookingfrontend', 'buildings', building_id, 'schedule'], {
        // menuaction: 'bookingfrontend.uibooking.building_schedule_pe',
        // building_id,
        dates: dates,
    }, true, instance);

    const response = await fetch(url, FetchAuthOptions());
    const result = await response.json();
    // console.log("fetchBuildingSchedule", result);
    return result;
}


export async function fetchBuildingSeasons(building_id: number, instance?: string): Promise<Season[]> {


    const url = phpGWLink(['bookingfrontend', 'buildings', building_id, 'seasons'], {
        // menuaction: 'bookingfrontend.uibooking.building_schedule_pe',
        // building_id,
        // dates: dates,
    }, true, instance);

    const response = await fetch(url);
    const result = await response.json();
    // console.log("fetchBuildingSchedule", result);
    return result;
}



export async function fetchFreeTimeSlotsForRange(building_id: number, start: DateTime, end: DateTime, instance?: string): Promise<Record<string, IFreeTimeSlot[]>> {
	const url = phpGWLink('bookingfrontend/', {
		menuaction: 'bookingfrontend.uibooking.get_freetime',
		building_id,
		start_date: start.toFormat('dd/LL-yyyy'),
		end_date: end.toFormat('dd/LL-yyyy'),
		detailed_overlap: true,
		stop_on_end_date: true
	}, true, instance);
	const response = await fetch(url);
	const result = await response.json();
	return result;
}


/**
 * Generate a UUID v4
 */
function generateUUID(): string {
	if (typeof crypto !== 'undefined' && crypto.randomUUID) {
		return crypto.randomUUID();
	}
	// Fallback for older browsers
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
		const r = Math.random() * 16 | 0;
		const v = c === 'x' ? r : (r & 0x3 | 0x8);
		return v.toString(16);
	});
}

/**
 * Fetch the authenticated user's session ID
 * @returns An object containing the sessionId
 */
export async function fetchSessionId(): Promise<{ sessionId: string }> {
	const clickHistory = generateUUID();
	const url = phpGWLink(['bookingfrontend', 'user', 'session'], {
		click_history: clickHistory
	});

	const response = await fetch(url, {
		credentials: 'include',
	});

	if (!response.ok) {
		throw new Error('Failed to fetch session ID');
	}

	return response.json();
}

export async function fetchServerSettings(): Promise<IServerSettings> {
    const url = phpGWLink(['api', 'server-settings'], {include_configs: true});
    const response = await fetch(url);
    const result = await response.json();
    return result;
}


export async function patchBookingUser(updateData: Partial<IBookingUser>): Promise<{
    message?: string,
    user?: IBookingUser
}> {
    const url = phpGWLink(['bookingfrontend', 'user']);
    const response = await fetch(url, {method: 'PATCH', body: JSON.stringify(updateData)});
    const result = await response.json();
    if(process.env.NODE_ENV === 'development') {
        console.log("PATCH result: ", result);
    }
    return result;
}


export async function fetchPartialApplications(): Promise<{ list: IApplication[], total_sum: number }> {
    const url = phpGWLink(['bookingfrontend', 'applications', 'partials']);
    const response = await fetch(url);
    const result = await response.json();
    return result;
}



export async function fetchMyOrganizations(): Promise<IOrganization[]> {
    const url = phpGWLink(['bookingfrontend', 'organizations', 'my']);
    const response = await fetch(url);
    const result = await response.json();
    return result?.results;
}

export async function searchOrganizations(query: string): Promise<IOrganization[]> {
    const url = phpGWLink(['bookingfrontend', 'organizations', 'list'], {query: query});
    const response = await fetch(url);
    const result = await response.json();
    return result?.results;
}

export async function validateOrgNum(org_num: string): Promise<BrregOrganization> {
    const url = phpGWLink(['bookingfrontend', 'organizations', 'lookup', org_num]);
    const response = await fetch(url);
    const result = await response.json();
    return result;
}


export async function fetchDeliveredApplications(): Promise<{ list: IApplication[], total_sum: number }> {
    const url = phpGWLink(['bookingfrontend', 'applications']);
    const response = await fetch(url);
    const result = await response.json();
    return result;
}


export async function fetchServerMessages(): Promise<IServerMessage[]> {
    const url = phpGWLink(['bookingfrontend', 'user', 'messages']);
    const response = await fetch(url, FetchAuthOptions());
    const result = await response.json();
    return result;
}


export async function fetchArticlesForResources(resource_ids: number[]): Promise<IArticle[]> {
    const url = phpGWLink(['bookingfrontend', 'applications', 'articles'], {resources: resource_ids});
    const response = await fetch(url);
    const result = await response.json();
    return result;
}

export async function fetchSearchDataClient(): Promise<ISearchDataOptimized> {
	const url = phpGWLink(['bookingfrontend', 'searchdataalloptimised']);
	const response = await fetch(url);
	const result = await response.json();
	console.log("SEARCH DATA FETCHED")
	return result;
}

/**
 * Fetches just the organizations from the dedicated endpoint
 * @returns Promise with an array of ISearchOrganization objects
 */
export async function fetchOrganizations(): Promise<ISearchOrganization[]> {
	const url = phpGWLink(['bookingfrontend', 'organizations']);
	const response = await fetch(url);
	const result = await response.json();
	return result;
}

/**
 * Fetches just the towns array from the API
 * @returns Promise with an array of ISearchDataTown objects
 */
export async function fetchTowns(): Promise<ISearchDataTown[]> {
	const url = phpGWLink(['bookingfrontend', 'towns']);
	const response = await fetch(url);
	const result = await response.json();
	return result;
}

export async function fetchInvoices(): Promise<ICompletedReservation[]> {
    const url = phpGWLink(['bookingfrontend', 'invoices']);
    const response = await fetch(url);
    const result = await response.json();
    return result;
}

export interface VersionSettings {
    success: boolean;
    version: 'original' | 'new';
    template_set: string;
}

export async function fetchVersionSettings(): Promise<VersionSettings> {
    const url = phpGWLink(['bookingfrontend', 'version']);
    const response = await fetch(url);
    const result = await response.json();
    return result;
}

export async function setVersionSettings(version: 'original' | 'new'): Promise<VersionSettings> {
    const url = phpGWLink(['bookingfrontend', 'version']);
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ version })
    });
    const result = await response.json();
    return result;
}
export async function fetchBuildingAgeGroups(building_id: number): Promise<IAgeGroup[]> {
    const url = phpGWLink(['bookingfrontend', 'buildings', building_id, 'agegroups']);
    const response = await fetch(url);
    const result = await response.json();
    return result;
}
export async function fetchBuildingAudience(building_id: number): Promise<IAudience[]> {
    const url = phpGWLink(['bookingfrontend', 'buildings', building_id, 'audience']);
    const response = await fetch(url);
    const result = await response.json();
    return result;
}

export async function deletePartialApplication(id: number): Promise<void> {
    const queryClient = getQueryClient();

    // Get current cart data before API call
    const currentData = queryClient.getQueryData<{ list: IApplication[], total_sum: number }>(['partialApplications']);

    // If we have current data, update it optimistically
    if (currentData) {
        queryClient.setQueryData(['partialApplications'], {
            ...currentData,
            list: currentData.list.filter(item => item.id !== id),
            total_sum: currentData.total_sum // Maintain current sum
        });
    }

    // Make the API call
    const url = phpGWLink(['bookingfrontend', 'applications', id]);

    try {
        const response = await fetch(url, {method: 'DELETE'});
        const result = await response.json();

        // Refetch to ensure data consistency after successful delete
        queryClient.refetchQueries({queryKey: ['partialApplications']});

        return result;
    } catch (error) {
        // If there was an error, roll back to original data
        if (currentData) {
            queryClient.setQueryData(['partialApplications'], currentData);
        }

        // Refetch to ensure data consistency
        queryClient.refetchQueries({queryKey: ['partialApplications']});

        throw error;
    }
}


/**
 * Parameters for the upcoming events endpoint
 */
export interface UpcomingEventsParams {
	/** Filter events from this date (format: YYYY-MM-DD) */
	fromDate?: string;
	/** Filter events up to this date (format: YYYY-MM-DD) */
	toDate?: string;
	/** Filter events by building ID */
	buildingId?: number;
	/** Filter events by facility type ID */
	facilityTypeId?: number;
	/** When true, shows only events for the logged-in organization */
	loggedInOnly?: boolean;
	/** Pagination start */
	start?: number;
	/** Pagination limit */
	limit?: number;
}

/**
 * Fetches upcoming events from the API
 * @param params Optional parameters to filter the results
 * @returns Promise with an array of IShortEvent objects
 */
export async function fetchUpcomingEvents(params?: UpcomingEventsParams): Promise<IShortEvent[]> {
	// Build query parameters
	const queryParams = new URLSearchParams();
	console.log("FETCHING UPCOMMING EVENTS")
	if (params?.fromDate) queryParams.append('fromDate', params.fromDate);
	if (params?.toDate) queryParams.append('toDate', params.toDate);
	if (params?.buildingId) queryParams.append('buildingId', params.buildingId.toString());
	if (params?.facilityTypeId) queryParams.append('facilityTypeId', params.facilityTypeId.toString());
	if (params?.loggedInOnly !== undefined) queryParams.append('loggedInOnly', params.loggedInOnly.toString());
	if (params?.start !== undefined) queryParams.append('start', params.start.toString());
	if (params?.limit !== undefined) queryParams.append('limit', params.limit.toString());


	try {
		const url =  phpGWLink(['bookingfrontend', 'events', 'upcoming'], Object.fromEntries( queryParams.entries() ))
		const response = await fetch(url);

		if (!response.ok) {
			throw new Error(`Failed to fetch upcoming events: ${response.status}`);
		}

		return await response.json() as IShortEvent[];
	} catch (error) {
		console.error('Error fetching upcoming events:', error);
		throw error;
	}
}

/**
 * Fetches multi-domains from the API
 * @returns Promise with an array of IMultiDomain objects
 */
export async function fetchMultiDomains(): Promise<IMultiDomain[]> {
	const url = phpGWLink(['bookingfrontend', 'multi-domains']);
	const response = await fetch(url);
	
	if (!response.ok) {
		throw new Error(`Failed to fetch multi-domains: ${response.status}`);
	}
	
	const result = await response.json();
	return result.results || [];
}
