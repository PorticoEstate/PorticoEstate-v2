import {DateTime} from "luxon";
import {phpGWLink} from "@/service/util";
import {IBookingUser, IServerSettings} from "@/service/types/api.types";
import {IApplication} from "@/service/types/api/application.types";
import {getQueryClient} from "@/service/query-client";
import {ICompletedReservation} from "@/service/types/api/invoices.types";
import {IEvent} from "@/service/pecalendar.types";
import {cookies} from "next/headers";
import {IAgeGroup, IAudience} from "@/service/types/Building";



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
    console.log("fetchBuildingSchedule", result);
    return result;
}



export async function fetchFreeTimeSlots(building_id: number, instance?: string) {
    const currDate = DateTime.fromJSDate(new Date());
    const maxEndDate = currDate.plus({months: BOOKING_MONTH_HORIZON}).endOf('month');

    const url = phpGWLink('bookingfrontend/', {
        menuaction: 'bookingfrontend.uibooking.get_freetime',
        building_id,
        start_date: currDate.toFormat('dd/LL-yyyy'),
        end_date: maxEndDate.toFormat('dd/LL-yyyy'),
    }, true, instance);
    const response = await fetch(url);
    const result = await response.json();
    return result;
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


export async function fetchDeliveredApplications(): Promise<{ list: IApplication[], total_sum: number }> {
    const url = phpGWLink(['bookingfrontend', 'applications']);
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
    queryClient.resetQueries({queryKey: ['partialApplications']})
    const url = phpGWLink(['bookingfrontend', 'applications', id]);
    const response = await fetch(url, {method: 'DELETE'});
    const result = await response.json();
    queryClient.refetchQueries({queryKey: ['partialApplications']})

    return result;
}


