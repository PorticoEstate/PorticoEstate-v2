import { IEvent } from "@/service/pecalendar.types";
import { DateTime } from "luxon";
import styles from "@/components/building-calendar/building-calender.module.scss";
import { FCallEvent, FCallBackgroundEvent } from "@/components/building-calendar/building-calendar.types";
import {useMemo} from "react";
import {IBookingUser} from "@/service/types/api.types";


export const isOrgAdmin = (user: IBookingUser | undefined, eventData: IEvent) => {
	if (!user) {
		return false;
	}
	let eventOrgId: number | undefined;
	let eventOrgNumber: string | undefined;

	switch (eventData.type) {
		case 'event':
			if(eventData.customer_identifier_type === 'organization_number') {
				eventOrgNumber = eventData.customer_organization_number;
			}
			break;
		case 'allocation':
			eventOrgId = eventData.organization_id;
			break;
		case 'booking':
			break;
		default:
			return false;
			break;
	}

	if (eventOrgId !== undefined) {
		return !!user.delegates?.some(delegate => delegate.active && delegate.org_id === eventOrgId)
	}

	if (eventOrgNumber !== undefined) {
		return !!user.delegates?.some(org => org.active && org.organization_number === eventOrgNumber);
	}
}

export function FCallEventConverter(event: IEvent, enabledResources: Set<string>, user: IBookingUser | undefined): { mainEvent: FCallEvent | null, backgroundEvent: FCallBackgroundEvent | null } {
    const is_public = 'is_public' in event ? event.is_public : 1;
    const resourceColours = event.resources
        .filter(resource => enabledResources.has(resource.id.toString()));
	const isAdmin = isOrgAdmin(user, event)
    // If no enabled resources for this event, return null
    if (resourceColours.length === 0) return { mainEvent: null, backgroundEvent: null };

    let startDateTime: DateTime;
    let endDateTime: DateTime;

    startDateTime = DateTime.fromISO(event.from_);
    endDateTime = DateTime.fromISO(event.to_);

    // Calculate the duration of the event in minutes
    const durationMinutes = endDateTime.diff(startDateTime, 'minutes').minutes;

    // If the duration is less than 30 minutes, extend the end time for display purposes
    const displayEndDateTime = durationMinutes < 30
        ? startDateTime.plus({ minutes: 30 })
        : endDateTime;

    const allDay = !startDateTime.hasSame(endDateTime, 'day');

    let name: string | undefined = undefined;
    switch (event.type) {
        case "event":
            name = event.name;
            break;
        case 'booking':
            name = event.group_name;
            break;
        case "allocation":
            name = event.organization_name;
            break;
    }
    // Check if this is a partial application event (from shopping cart)
    const isPartialApplication = '_isPartialApplication' in event ? (event as any)._isPartialApplication : false;
    const isRecurringInstance = '_isRecurringInstance' in event ? (event as any)._isRecurringInstance : false;



    const mainEvent: FCallEvent = {
        id: event.id,
        title: name + ` \n`,
        start: startDateTime.toJSDate(),
        // in all day, END is EXCLUSIVE
        end: allDay ? displayEndDateTime.plus({days: 1}).toJSDate() : displayEndDateTime.toJSDate(),
        allDay: allDay,
        className: [`${styles[`event-${event.type}`]} ${styles.event} ${allDay ? styles.eventAllDay : ''} ${isRecurringInstance ? styles.recurringInstance : ''} ${isAdmin ? styles.eventAdmin : ''} `],
        extendedProps: {
            actualStart: startDateTime.toJSDate(),
            actualEnd: endDateTime.toJSDate(),
            isExtended: durationMinutes < 30,
            source: event,
            type: event.type,
            // Pass through partial application flags
            isPartialApplication,
            isRecurringInstance
        },
    };

    // Create background event only for all-day events
    const backgroundEvent: FCallBackgroundEvent | null = allDay ? {
        start: startDateTime.toJSDate(),
        end: displayEndDateTime.toJSDate(),
        display: 'background',
        classNames: `${styles.allDayEventBackground} ${styles.eventAllDay} ${styles[`event-${event.type}-background`]}`,
        extendedProps: {
            type: 'background',
			source: 'EventConv'
        }
    } : null;

    return { mainEvent, backgroundEvent };
}