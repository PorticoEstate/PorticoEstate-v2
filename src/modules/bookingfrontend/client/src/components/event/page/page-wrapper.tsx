'use client'
import { FC } from "react";
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
    }
    return <h1>Loading...</h1>
}

export default EventPageWrapper;