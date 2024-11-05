import {DateTime} from "luxon";
import {EventImpl} from "@fullcalendar/core/internal";
import {FCallEvent, FCallTempEvent} from "@/components/building-calendar/building-calendar.types";

export const strBaseURL = `${typeof window === 'undefined' ? process.env.NEXT_INTERNAL_API_URL : (process.env.NEXT_PUBLIC_API_URL || window.location.origin)}/?click_history=165dde2af0dd4b589e3a3c8e26f0da86`;
export function phpGWLink(
    strURL: string | (string | number)[],
    oArgs: Record<string, string | number | boolean | (string | number)[]> | null = {},
    bAsJSON: boolean = true,
    baseURL?: string
): string {
    const useOldStructure = oArgs && 'menuaction' in oArgs;

    if (baseURL) {
        const baseURLParts = baseURL.split('/').filter((a) => a !== '' && !a.includes('http'));
        baseURL = '//' + baseURLParts.slice(0, baseURLParts.length - 1).join('/') + '/';
    }

    const urlParts = (baseURL || strBaseURL).split('?');
    let newURL = urlParts[0];

    // Helper function to safely join URL parts without double slashes
    function safeJoinURL(base: string, path: string): string {
        return base.replace(/\/+$/, '') + '/' + path.replace(/^\/+/, '');
    }

    if (Array.isArray(strURL)) {
        const path = strURL.map(s => s.toString().replace(/^\/+|\/+$/g, '')).join('/');
        newURL = safeJoinURL(newURL, path);
    } else {
        newURL = safeJoinURL(newURL, strURL.toString());
    }

    if (useOldStructure) {
        newURL += '?';

        for (const key in oArgs) {
            if (Array.isArray(oArgs[key])) {
                // Handle array parameters by adding [] to the key and encoding each value
                (oArgs[key] as (string | number)[]).forEach((value) => {
                    newURL += `${encodeURIComponent(key)}[]=${encodeURIComponent(value)}&`;
                });
            } else {
                newURL += `${encodeURIComponent(key)}=${encodeURIComponent(oArgs[key] as string | number)}&`;
            }
        }

        if (newURL.endsWith('&')) {
            newURL = newURL.substring(0, newURL.length - 1);
        }
        if (bAsJSON) {
            newURL += '&phpgw_return_as=json';
        }
    } else {
        if (oArgs && Object.keys(oArgs).length > 0) {
            const params = new URLSearchParams();
            for (const [key, value] of Object.entries(oArgs)) {
                if (Array.isArray(value)) {
                    value.forEach(v => params.append(`${key}[]`, v.toString()));
                } else {
                    params.append(key, value.toString());
                }
            }
            newURL += '?' + params.toString();
        }
    }

    return newURL;
}



export function LuxDate(d: Date) {
    return DateTime.fromJSDate(d)
}


export function formatEventTime(event: EventImpl | FCallEvent | FCallTempEvent) {

    const actualStart = 'actualStart' in event.extendedProps ? event.extendedProps.actualStart : event.start;
    const actualEnd = 'actualEnd' in event.extendedProps ? event.extendedProps.actualEnd : event.end;
    const formatTime = (date: Date) => LuxDate(date).toFormat('HH:mm');
    const actualTimeText = `${formatTime(actualStart)} - ${formatTime(actualEnd)}`;
    return actualTimeText
}


