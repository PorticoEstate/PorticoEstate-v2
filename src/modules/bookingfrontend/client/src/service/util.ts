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
        // Check if baseURL already has a protocol
        if (baseURL.startsWith('http://') || baseURL.startsWith('https://')) {
            // Keep the protocol and just ensure it ends with /
            baseURL = baseURL.replace(/\/+$/, '') + '/';
        } else {
            // No protocol, use https and process as before
            const baseURLParts = baseURL.split('/').filter((a) => a !== '');
            // For external domains, we want to keep the full host, not remove the last part
            if (baseURLParts.length === 1) {
                // Single domain like "stavanger.aktiv-kommune.no"
                baseURL = 'https://' + baseURLParts[0] + '/';
            } else {
                // Multi-part path, remove last segment as before
                baseURL = 'https://' + baseURLParts.slice(0, baseURLParts.length - 1).join('/') + '/';
            }
        }
    }


    const urlParts = (baseURL || strBaseURL).split('?');
    let newURL = urlParts[0];
    // Helper function to safely join URL parts without double slashes
	function safeJoinURL(base: string, path: string): string {
		return base.replace(/\/+$/, '') + '/' + path.replace(/^\/+/, '');
	}

    if (Array.isArray(strURL)) {
		const path = strURL.map(s => s.toString().replace(/^\/+/g, '')).join('/');
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

export function formatTimeStamp(timestamp: DateTime | Date, withDate?: boolean) {
    const dt = DateTime.isDateTime(timestamp) ? timestamp : LuxDate(timestamp);
    let format = "HH:mm";
    if (withDate) {
        format = "dd.LLL HH:mm";
    }

    return  dt.toFormat(format);
}


export function formatDateStamp(timestamp: DateTime | Date) {
    const dt = DateTime.isDateTime(timestamp) ? timestamp : LuxDate(timestamp);
    const format = "dd.LLL";

    return  dt.toFormat(format);
}


export function formatDateRange(from: DateTime | Date , to: DateTime | Date, withTime?: boolean) {
    const fromDT = DateTime.isDateTime(from) ? from : LuxDate(from);
    const toDT = DateTime.isDateTime(to) ? to : LuxDate(to);
    const sameMonth = fromDT.month === toDT.month;
    let fromFormat = "dd.";
    let toFormat = "dd.LLL";

    if(!sameMonth) {
        fromFormat = "dd.LLL";
    }
    if(withTime) {
        toFormat = "dd.LLL HH:mm";
        fromFormat = fromFormat + " HH:mm";
    }

    return  `${fromDT.toFormat(fromFormat)} - ${toDT.toFormat(toFormat)}`;
}



export function isFutureDate(date: DateTime): boolean {
	const now = DateTime.utc(); // Current time in UTC
	return date.isValid && date > now;
}

export function isDevMode (): boolean {
	return process.env.NODE_ENV === 'development';
}