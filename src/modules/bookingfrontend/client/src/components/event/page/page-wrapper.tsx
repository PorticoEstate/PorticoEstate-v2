'use client'
import { FC } from "react";
import {Spinner} from "@digdir/designsystemet-react";
import { useEventData } from "@/service/api/event-info";
import EventPageController from "./page-controller";

interface EventPageWrapper {
    eventId: number;
}

const EventPageWrapper: FC<EventPageWrapper> = ({ eventId }: EventPageWrapper) => {
    const {data: eventInfo, isLoading} = useEventData(eventId);
    if (!isLoading && eventInfo) {
        const access = eventInfo.name !== 'PRIVATE EVENT';
        return <EventPageController event={eventInfo} privateAccess={access}/>
    } else if (!isLoading && !eventInfo) {
        return null;
    }
    return <Spinner aria-label='Laster event info'/>
}

export default EventPageWrapper;