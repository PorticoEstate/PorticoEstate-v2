import { IEvent } from "@/service/pecalendar.types";
import { DateTime } from "luxon";
import styles from "@/components/building-calendar/building-calender.module.scss";
import { FCallEvent, FCallBackgroundEvent } from "@/components/building-calendar/building-calendar.types";

export function FCallEventConverter(event: IEvent, enabledResources: Set<string>): { mainEvent: FCallEvent | null, backgroundEvent: FCallBackgroundEvent | null } {
    const is_public = 'is_public' in event ? event.is_public : 1;
    const resourceColours = event.resources
        .filter(resource => enabledResources.has(resource.id.toString()));

    // If no enabled resources for this event, return null
    if (resourceColours.length === 0) return { mainEvent: null, backgroundEvent: null };

    let startDateTime: DateTime;
    let endDateTime: DateTime;

    startDateTime = DateTime.fromSQL(event.from_);
    endDateTime = DateTime.fromSQL(event.to_);

    // Calculate the duration of the event in minutes
    const durationMinutes = endDateTime.diff(startDateTime, 'minutes').minutes;

    // If the duration is less than 30 minutes, extend the end time for display purposes
    const displayEndDateTime = durationMinutes < 30
        ? startDateTime.plus({ minutes: 30 })
        : endDateTime;

    const allDay = !startDateTime.hasSame(endDateTime, 'day');

    const mainEvent: FCallEvent = {
        id: event.id,
        title: (is_public === 1 ? event.name : 'Private Event') + ` \n`,
        start: startDateTime.toJSDate(),
        // in all day, END is EXCLUSIVE
        end: allDay ? displayEndDateTime.plus({days: 1}).toJSDate() : displayEndDateTime.toJSDate(),
        allDay: allDay,
        className: [`${styles[`event-${event.type}`]} ${styles.event} ${allDay ? styles.eventAllDay : ''}`],
        extendedProps: {
            actualStart: startDateTime.toJSDate(),
            actualEnd: endDateTime.toJSDate(),
            isExtended: durationMinutes < 30,
            source: event,
            type: event.type
        },
    };

    // Create background event only for all-day events
    const backgroundEvent: FCallBackgroundEvent | null = allDay ? {
        start: startDateTime.toJSDate(),
        end: displayEndDateTime.toJSDate(),
        display: 'background',
        classNames: `${styles.allDayEventBackground} ${styles.eventAllDay} ${styles[`event-${event.type}-background`]}`,
        extendedProps: {
            type: 'background'
        }
    } : null;

    return { mainEvent, backgroundEvent };
}