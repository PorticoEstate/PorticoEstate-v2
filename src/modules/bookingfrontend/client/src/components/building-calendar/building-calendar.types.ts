import {IEvent, IShortResource} from "@/service/pecalendar.types";
import {EventClickArg, EventContentArg} from "@fullcalendar/core";
import {EventImpl} from "@fullcalendar/core/internal";


export type ValidCalendarType = IEvent['type'] | 'background'

export interface FCEventContentArg<T = EventImpl> extends Omit<EventContentArg, 'event'> {
    event: T
}

export interface FCEventClickArg<T = EventImpl> extends Omit<EventClickArg, 'event'> {
    event: T
}


export type FCallBaseEvent = FCallEvent | FCallTempEvent | FCallBackgroundEvent

export interface FCallEvent {
    id: number;
    title: string;
    start: Date;
    end: Date;
    allDay?: boolean;
    className: string[] | string;
    extendedProps: {
        actualStart: Date;
        actualEnd: Date;
        isExtended: boolean;
        source: IEvent;
        type: Exclude<IEvent['type'], 'temporary'>
    };
}

export interface FCallTempEvent {
    id: string;
    title: string;
    start: Date;
    end: Date;
    allDay: boolean
    editable: boolean,
    extendedProps: {
        type: 'temporary',
        resources: IShortResource[]
    };
}

export interface FCallBackgroundEvent {
    start: Date;
    end: Date;
    display: 'background'
    allDay?: boolean;
    classNames: string[] | string;
    extendedProps: {
        closed?: boolean;
        type: 'background'
    }
}
