import {IEvent} from "@/service/pecalendar.types";
import {DateTime} from "luxon";
import styles from "@/components/building-calendar/building-calender.module.scss";
import {FCallEvent} from "@/components/building-calendar/building-calendar.types";

export function FCallEventConverter(event: IEvent, enabledResources: Set<string>): FCallEvent | null {
    const is_public = 'is_public' in event ? event.is_public : 1;
    const resourceColours = event.resources
        .filter(resource => enabledResources.has(resource.id.toString()))

    // If no enabled resources for this event, return null
    if (resourceColours.length === 0) return null;

    let startDateTime: DateTime;
    let endDateTime: DateTime;

    // if (event.dates && event.dates.length > 0) {
    //     // If dates array is present, use the first date range
    //     startDateTime = DateTime.fromSQL(event.dates[0].from_);
    //     endDateTime = DateTime.fromSQL(event.dates[0].to_);
    // } else {
        // If no dates array, use the top-level from/to/date
        startDateTime = DateTime.fromSQL(event._from);
        endDateTime = DateTime.fromSQL(event._to);
    // }

    // Calculate the duration of the event in minutes
    const durationMinutes = endDateTime.diff(startDateTime, 'minutes').minutes;

    // If the duration is less than 30 minutes, extend the end time for display purposes
    const displayEndDateTime = durationMinutes < 30
        ? startDateTime.plus({ minutes: 30 })
        : endDateTime;

    const ret: FCallEvent = {
        id: event.id,
        title: (is_public === 1 ? event.name : 'Private Event') + ` \n${event.type}`,
        start: startDateTime.toJSDate(),
        end: displayEndDateTime.toJSDate(),
        className: [`${styles[`event-${event.type}`]} ${styles.event}`],
        extendedProps: {
            actualStart: startDateTime.toJSDate(),
            actualEnd: endDateTime.toJSDate(),
            isExtended: durationMinutes < 30,
            source: event,
            type: event.type
        },
    };
    // console.log(event, ret)
    return ret;
}