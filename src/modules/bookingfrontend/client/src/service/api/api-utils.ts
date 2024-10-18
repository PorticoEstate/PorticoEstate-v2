import {DateTime} from "luxon";
import {phpGWLink} from "@/service/util";
import {IBookingUser, IDocument, IServerSettings} from "@/service/types/api.types";
import {IApplication} from "@/service/types/api/application.types";
import {getQueryClient} from "@/service/query-client";

const BOOKING_MONTH_HORIZON = 2;


export async function fetchBuildingSchedule(building_id: number, dates: string[], instance?: string) {
    const url = phpGWLink('bookingfrontend/', {
        menuaction: 'bookingfrontend.uibooking.building_schedule_pe',
        building_id,
        dates: dates,
    }, true, instance);

    const response = await fetch(url);
    const result = await response.json();
    return result?.ResultSet?.Result?.results;
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

export async function fetchBookingUser(): Promise<IBookingUser> {
    const url = phpGWLink(['bookingfrontend', 'user']);
    const response = await fetch(url);
    const result = await response.json();
    return result;
}


export async function fetchPartialApplications(): Promise<{list: IApplication[], total_sum: number}> {
    const url = phpGWLink(['bookingfrontend', 'applications', 'partials']);
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



export function getDocumentLink(doc: IDocument): string {
    const url = phpGWLink(['bookingfrontend', 'buildings', 'documents', doc.id, 'download']);
    return url
}