import {DateTime} from "luxon";
import {phpGWLink} from "@/service/util";
import {
	IBookingUser,
	IServerSettings,
	IMultiDomain,
	IDocumentCategoryQuery,
	IDocument
} from "@/service/types/api.types";
import {IApplication, GetCommentsResponse, AddCommentRequest, AddCommentResponse, UpdateStatusRequest, UpdateStatusResponse} from "@/service/types/api/application.types";
import {getQueryClient} from "@/service/query-client";
import {ICompletedReservation} from "@/service/types/api/invoices.types";
import {IEvent, IFreeTimeSlot, IShortEvent, IAPIEvent, IAPIBooking, IAPIAllocation} from "@/service/pecalendar.types";
import {IAgeGroup, IAudience, Season, IBuilding} from "@/service/types/Building";
import {BrregOrganization, IOrganization, IShortOrganization, IShortOrganizationGroup, IShortOrganizationDelegate} from "@/service/types/api/organization.types";
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

export async function fetchOrganizationSchedule(organization_id: number, dates: string[], instance?: string): Promise<Record<string, IEvent[]>> {
    const url = phpGWLink(['bookingfrontend', 'organizations', organization_id, 'schedule'], {
        dates: dates,
    }, true, instance);

    const response = await fetch(url, FetchAuthOptions());
    const result = await response.json();
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
	console.log("FETCHING FREE TIME SLOTS FOR RANGE", url);
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



export async function fetchMyOrganizations(): Promise<IShortOrganization[]> {
    const url = phpGWLink(['bookingfrontend', 'organizations', 'my']);
    const response = await fetch(url);
    const result = await response.json();
    return result?.results;
}

export async function fetchOrganization(id: string | number, bustCache: boolean = false): Promise<IOrganization> {
    const params = bustCache ? { _t: Date.now().toString() } : undefined;
    const url = phpGWLink(['bookingfrontend', 'organizations', id], params);
    const fetchOptions = bustCache ? { cache: 'no-store' as RequestCache } : {};
    const response = await fetch(url, fetchOptions);
    const result = await response.json();
    return result;
}

export async function fetchOrganizationGroups(id: string | number): Promise<IShortOrganizationGroup[]> {
    const url = phpGWLink(['bookingfrontend', 'organizations', id, 'groups']);
    const response = await fetch(url);
    const result = await response.json();
    return result;
}

export async function fetchOrganizationBuildings(id: string | number): Promise<IBuilding[]> {
    const url = phpGWLink(['bookingfrontend', 'organizations', id, 'buildings']);
    const response = await fetch(url);
    const result = await response.json();
    return result;
}

export async function fetchOrganizationDelegates(id: string | number): Promise<IShortOrganizationDelegate[] | undefined> {
    const url = phpGWLink(['bookingfrontend', 'organizations', id, 'delegates']);
    const response = await fetch(url);
    
    if (response.status === 401) {
        return undefined;
    }
    
    if (!response.ok) {
        throw new Error(`Failed to fetch organization delegates: ${response.status}`);
    }
    
    const result = await response.json();
    return result;
}

export async function addOrganizationDelegate(id: string | number, data: {ssn: string, name?: string, email?: string, phone?: string, active?: boolean}): Promise<{message: string}> {
    const url = phpGWLink(['bookingfrontend', 'organizations', id, 'delegates']);
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
    });
    
    if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to add delegate');
    }
    
    // 204 No Content response doesn't have a body
    if (response.status === 204) {
        return {message: 'Delegate added successfully'};
    }
    
    const result = await response.json();
    return result;
}

export async function updateOrganizationDelegate(organizationId: string | number, delegateId: number, data: {name?: string, email?: string, phone?: string, active?: boolean}): Promise<{message: string}> {
    const url = phpGWLink(['bookingfrontend', 'organizations', organizationId, 'delegates', delegateId]);
    const response = await fetch(url, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
    });
    
    if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to update delegate');
    }
    
    const result = await response.json();
    return result;
}

export async function deleteOrganizationDelegate(organizationId: string | number, delegateId: number): Promise<void> {
    const url = phpGWLink(['bookingfrontend', 'organizations', organizationId, 'delegates', delegateId]);
    const response = await fetch(url, {
        method: 'DELETE',
    });
    
    if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to delete delegate');
    }
    
    // 204 No Content response doesn't have a body
    return;
}

// Organization Group API Functions

export async function createOrganizationGroup(organizationId: string | number, data: {
    name: string;
    shortname?: string;
    description?: string;
    parent_id?: number;
    activity_id?: number;
    show_in_portal?: boolean;
    contacts?: Array<{
        name: string;
        email?: string;
        phone?: string;
    }>;
}): Promise<{id: number; message: string}> {
    const url = phpGWLink(['bookingfrontend', 'organizations', organizationId, 'groups']);
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
    });
    
    if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to create group');
    }
    
    const result = await response.json();
    return result;
}

export async function updateOrganizationGroup(organizationId: string | number, groupId: number, data: {
    name?: string;
    shortname?: string;
    description?: string;
    parent_id?: number;
    activity_id?: number;
    show_in_portal?: boolean;
    active?: boolean;
    contacts?: Array<{
        id?: number;
        name: string;
        email?: string;
        phone?: string;
    }>;
}): Promise<{message: string}> {
    const url = phpGWLink(['bookingfrontend', 'organizations', organizationId, 'groups', groupId]);
    const response = await fetch(url, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
    });
    
    if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to update group');
    }
    
    const result = await response.json();
    return result;
}

export async function toggleOrganizationGroupActive(organizationId: string | number, groupId: number, active: boolean): Promise<{message: string}> {
    const url = phpGWLink(['bookingfrontend', 'organizations', organizationId, 'groups', groupId]);
    const response = await fetch(url, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ active }),
    });
    
    if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to toggle group status');
    }
    
    const result = await response.json();
    return result;
}

export async function updateOrganization(id: string | number, data: Partial<Pick<IOrganization, 'name' | 'shortname' | 'phone' | 'email' | 'homepage' | 'activity_id' | 'show_in_portal' | 'street' | 'zip_code' | 'city' | 'description_json'>>): Promise<{message: string}> {
	const url = phpGWLink(['bookingfrontend', 'organizations', id]);
    const response = await fetch(url, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
    });

    if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to update organization');
    }

    const result = await response.json();
    return result;
}

export async function searchOrganizations(query: string): Promise<IShortOrganization[]> {
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


export async function fetchDeliveredApplications(includeOrganizations: boolean = false): Promise<{ list: IApplication[], total_sum: number }> {
    const params: Record<string, any> = {}

    // Add session cookie if we're on the server
    if (typeof window === 'undefined') {
        const cookies = require("next/headers").cookies()
        const sessionCookie = cookies.get('bookingfrontendsession')?.value;
        if (sessionCookie) {
            params.bookingfrontendsession = sessionCookie;
        }
    }

    // Add include_organizations parameter if requested
    if (includeOrganizations) {
        params.include_organizations = 'true';
    }

    const url = phpGWLink(['bookingfrontend', 'applications'], params);
    const response = await fetch(url, FetchAuthOptions());

    if (!response.ok) {
        throw new Error(`Failed to fetch applications: ${response.status}`);
    }

    const result = await response.json();
    return result;
}

/**
 * Fetch a single application by ID
 * @param id The application ID to fetch
 * @returns The application object
 */
export async function fetchApplication(id: number): Promise<IApplication> {
	const params: Record<string, any> = {}
	if(typeof window === 'undefined') {
		const cookies = require("next/headers").cookies()
		const sessionCookie = cookies.get('bookingfrontendsession')?.value;
		if(sessionCookie) {
			params.bookingfrontendsession = sessionCookie;
		}
	}
	let url = phpGWLink(['bookingfrontend', 'applications', id.toString()], params);

    const response = await fetch(url);

    if (!response.ok) {
        throw new Error(`Failed to fetch application with ID ${id}`);
    }

    return response.json();
}

export async function fetchApplicationDocuments(applicationId: number | string, type_filter?: IDocumentCategoryQuery | IDocumentCategoryQuery[]): Promise<IDocument[]> {
	const url = phpGWLink(["bookingfrontend", 'applications', applicationId, 'documents'],
		type_filter && {type: Array.isArray(type_filter) ? type_filter.join(',') : type_filter});

	const response = await fetch(url);
	const result = await response.json();
	return result;
}

/**
 * Fetch comments for an application
 * @param id The application ID
 * @param types Optional comma-separated list of comment types to filter by
 * @param secret Optional secret for external access
 * @returns Comments and statistics
 */
export async function fetchApplicationComments(
    id: number,
    types?: string,
    secret?: string
): Promise<GetCommentsResponse> {
    const params: Record<string, any> = {};

    if (types) {
        params.types = types;
    }
    if (secret) {
        params.secret = secret;
    }

    if (typeof window === 'undefined') {
        const cookies = require("next/headers").cookies()
        const sessionCookie = cookies.get('bookingfrontendsession')?.value;
        if (sessionCookie) {
            params.bookingfrontendsession = sessionCookie;
        }
    }

    const url = phpGWLink(['bookingfrontend', 'applications', id.toString(), 'comments'], params);

    const response = await fetch(url, FetchAuthOptions());

    if (!response.ok) {
        throw new Error(`Failed to fetch comments for application ${id}`);
    }

    return response.json();
}

/**
 * Add a comment to an application
 * @param id The application ID
 * @param commentData The comment data to add
 * @param secret Optional secret for external access
 * @returns The created comment
 */
export async function addApplicationComment(
    id: number,
    commentData: AddCommentRequest,
    secret?: string
): Promise<AddCommentResponse> {
    const params: Record<string, any> = {};

    if (secret) {
        params.secret = secret;
    }

    if (typeof window === 'undefined') {
        const cookies = require("next/headers").cookies()
        const sessionCookie = cookies.get('bookingfrontendsession')?.value;
        if (sessionCookie) {
            params.bookingfrontendsession = sessionCookie;
        }
    }

    const url = phpGWLink(['bookingfrontend', 'applications', id.toString(), 'comments'], params);

    const response = await fetch(url, FetchAuthOptions({
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(commentData),
    }));

    if (!response.ok) {
        throw new Error(`Failed to add comment to application ${id}`);
    }

    return response.json();
}

/**
 * Fetch events, allocations and bookings related to an application
 * @param id The application ID
 * @param secret Optional secret for external access
 * @returns Events, allocations and bookings related to the application
 */
export async function fetchApplicationScheduleEntities(
    id: number,
    secret?: string
): Promise<{events: IAPIEvent[], allocations: IAPIAllocation[], bookings: IAPIBooking[]}> {
    const params: Record<string, any> = {};

    if (secret) {
        params.secret = secret;
    }

    if (typeof window === 'undefined') {
        const cookies = require("next/headers").cookies()
        const sessionCookie = cookies.get('bookingfrontendsession')?.value;
        if (sessionCookie) {
            params.bookingfrontendsession = sessionCookie;
        }
    }

    const url = phpGWLink(['bookingfrontend', 'applications', id.toString(), 'schedule'], params);

    const response = await fetch(url, FetchAuthOptions());

    if (!response.ok) {
        throw new Error(`Failed to fetch events/allocations/bookings for application ${id}`);
    }

    return response.json();
}

/**
 * Update the status of an application
 * @param id The application ID
 * @param statusData The status update data
 * @param secret Optional secret for external access
 * @returns The status update response
 */
export async function updateApplicationStatus(
    id: number,
    statusData: UpdateStatusRequest,
    secret?: string
): Promise<UpdateStatusResponse> {
    const params: Record<string, any> = {};

    if (secret) {
        params.secret = secret;
    }

    if (typeof window === 'undefined') {
        const cookies = require("next/headers").cookies()
        const sessionCookie = cookies.get('bookingfrontendsession')?.value;
        if (sessionCookie) {
            params.bookingfrontendsession = sessionCookie;
        }
    }

    const url = phpGWLink(['bookingfrontend', 'applications', id.toString(), 'status'], params);

    const response = await fetch(url, FetchAuthOptions({
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(statusData),
    }));

    if (!response.ok) {
        throw new Error(`Failed to update status for application ${id}`);
    }

    return response.json();
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
    const params: Record<string, any> = {}

    // Add session cookie if we're on the server
    if (typeof window === 'undefined') {
        const cookies = require("next/headers").cookies()
        const sessionCookie = cookies.get('bookingfrontendsession')?.value;
        if (sessionCookie) {
            params.bookingfrontendsession = sessionCookie;
        }
    }

    const url = phpGWLink(['bookingfrontend', 'invoices'], params);
    const response = await fetch(url, FetchAuthOptions());

    if (!response.ok) {
        throw new Error(`Failed to fetch invoices: ${response.status}`);
    }

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

export interface VippsPaymentData {
	organizerName: string;
	customerType: 'ssn' | 'organization_number';
	organizationNumber?: string;
	organizationName?: string;
	contactName: string;
	contactEmail: string;
	contactPhone: string;
	street: string;
	zipCode: string;
	city: string;
	documentsRead: boolean;
}

export interface VippsPaymentResponse {
	success: boolean;
	redirect_url?: string;
	error?: string;
}

export async function initiateVippsPayment(paymentData: VippsPaymentData): Promise<VippsPaymentResponse> {
	const url = phpGWLink(['bookingfrontend', 'applications', 'partials', 'vipps-payment']);
	console.log('=== VIPPS API FUNCTION ===');
	console.log('URL:', url);
	console.log('Payment data:', paymentData);

	const response = await fetch(url, {
		method: 'POST',
		body: JSON.stringify(paymentData),
		headers: {
			'Content-Type': 'application/json',
		},
	});

	console.log('Response status:', response.status);
	console.log('Response headers:', Object.fromEntries(response.headers.entries()));

	const responseText = await response.text();
	console.log('Raw response:', responseText);

	let result;
	try {
		result = JSON.parse(responseText);
	} catch (e) {
		console.error('Failed to parse response as JSON:', e);
		throw new Error('Invalid JSON response from server');
	}

	console.log('Parsed result:', result);

	if (!response.ok) {
		throw new Error(result.error || 'Vipps payment initiation failed');
	}

	return result;
}

export interface ExternalPaymentEligibilityResponse {
	eligible: boolean;
	reason: string;
	total_amount: number;
	applications_count: number;
	payment_methods: Array<{
		method: string;
		logo: string;
	}>;
}

export async function fetchExternalPaymentEligibility(): Promise<ExternalPaymentEligibilityResponse> {
	const url = phpGWLink(['bookingfrontend', 'checkout', 'external-payment-eligibility']);

	const response = await fetch(url, {
		method: 'GET',
		headers: {
			'Content-Type': 'application/json',
		},
	});

	if (!response.ok) {
		throw new Error('Failed to check external payment eligibility');
	}

	return response.json();
}

export interface VippsPaymentStatusResponse {
	status: string;
	message: string;
	applications_approved?: boolean;
}

export interface VippsPaymentDetailsResponse {
	transactionInfo?: {
		status: string;
		amount: number;
		timeStamp: string;
	};
	transactionLogHistory?: Array<{
		operation: string;
		operationSuccess: boolean;
		timeStamp: string;
		amount: number;
	}>;
}

export interface VippsCancelPaymentResponse {
	success: boolean;
	message: string;
	vipps_response?: any;
}

export interface VippsRefundPaymentResponse {
	success: boolean;
	message: string;
	refunded_amount: number;
	vipps_response?: any;
}

/**
 * Check Vipps payment status and process payment
 * This should be called after user returns from Vipps or periodically to check status
 */
export async function checkVippsPaymentStatus(payment_order_id: string): Promise<VippsPaymentStatusResponse> {
	const url = phpGWLink(['bookingfrontend', 'checkout', 'vipps', 'check-payment-status']);

	const response = await fetch(url, {
		method: 'POST',
		body: JSON.stringify({ payment_order_id }),
		headers: {
			'Content-Type': 'application/json',
		},
	});

	if (!response.ok) {
		const errorData = await response.json();
		throw new Error(errorData.error || 'Failed to check Vipps payment status');
	}

	return response.json();
}

/**
 * Get detailed payment information from Vipps
 */
export async function getVippsPaymentDetails(payment_order_id: string): Promise<VippsPaymentDetailsResponse> {
	const url = phpGWLink(['bookingfrontend', 'checkout', 'vipps', 'payment-details', payment_order_id]);

	const response = await fetch(url, {
		method: 'GET',
		headers: {
			'Content-Type': 'application/json',
		},
	});

	if (!response.ok) {
		const errorData = await response.json();
		throw new Error(errorData.error || 'Failed to get Vipps payment details');
	}

	return response.json();
}

/**
 * Cancel a Vipps payment
 */
export async function cancelVippsPayment(payment_order_id: string): Promise<VippsCancelPaymentResponse> {
	const url = phpGWLink(['bookingfrontend', 'checkout', 'vipps', 'cancel-payment']);

	const response = await fetch(url, {
		method: 'POST',
		body: JSON.stringify({ payment_order_id }),
		headers: {
			'Content-Type': 'application/json',
		},
	});

	if (!response.ok) {
		const errorData = await response.json();
		throw new Error(errorData.error || 'Failed to cancel Vipps payment');
	}

	return response.json();
}

/**
 * Refund a Vipps payment
 */
export async function refundVippsPayment(payment_order_id: string, amount: number): Promise<VippsRefundPaymentResponse> {
	const url = phpGWLink(['bookingfrontend', 'checkout', 'vipps', 'refund-payment']);

	const response = await fetch(url, {
		method: 'POST',
		body: JSON.stringify({ payment_order_id, amount }),
		headers: {
			'Content-Type': 'application/json',
		},
	});

	if (!response.ok) {
		const errorData = await response.json();
		throw new Error(errorData.error || 'Failed to refund Vipps payment');
	}

	return response.json();
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

/**
 * Fetches available resources for a specific date from a specific domain
 * @param date - The date to check availability for (format: YYYY-MM-DD)
 * @param domain - Optional domain name for multi-domain requests
 * @returns Promise with an array of available resource IDs
 */
export async function fetchAvailableResources(date: string, domain?: string): Promise<number[]> {
	const url = phpGWLink(['bookingfrontend', 'availableresources'], { date }, true, domain);
	const response = await fetch(url);

	if (!response.ok) {
		throw new Error(`Failed to fetch available resources: ${response.status}`);
	}

	return await response.json().then(d => d.resources);
}

/**
 * Fetches available resources for a specific date across all domains
 * @param date - The date to check availability for (format: YYYY-MM-DD)
 * @param multiDomains - Array of domain configurations
 * @returns Promise with a map of domain names to available resource IDs
 */
export async function fetchAvailableResourcesMultiDomain(
	date: string,
	multiDomains: IMultiDomain[]
): Promise<Record<string, number[]>> {
	const results: Record<string, number[]> = {};

	// Fetch from local domain (no domain parameter)
	try {
		const localResources = await fetchAvailableResources(date);
		results['local'] = localResources;
	} catch (error) {
		console.error('Error fetching local available resources:', error);
		results['local'] = [];
	}

	// Fetch from all external domains in parallel
	const domainPromises = multiDomains.map(async (domain) => {
		try {
			const resources = await fetchAvailableResources(date, domain.name);
			return { domain: domain.name, resources };
		} catch (error) {
			console.error(`Error fetching available resources from ${domain.name}:`, error);
			return { domain: domain.name, resources: [] };
		}
	});

	const domainResults = await Promise.all(domainPromises);
	domainResults.forEach(({ domain, resources }) => {
		results[domain] = resources;
	});

	return results;
}
