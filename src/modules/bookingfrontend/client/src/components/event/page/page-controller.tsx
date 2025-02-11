"use client"
import EventEditing from "@/components/event/editing/editing-controller";
import { ActivityData, editEvent, useEventData } from "@/service/api/event-info";
import { FC, useState, useEffect } from "react";
import EventView from "./page-view";
import { EditingEvent } from "../editing/eventFormSchema";

interface EventPageProps {
    eventId: number;
    privateAccess: boolean
}

const EventPageController: FC<EventPageProps> = ({ eventId, privateAccess }: EventPageProps) => {
    const {data: eventInfo} = useEventData(eventId);
    const [isEditing, setEditingMode] = useState(false);
    const [eventState, setEventState] = useState(eventInfo);
    const cancelEditing = () => setEditingMode(false);
    const openEditing = () => setEditingMode(true);

    useEffect(() => {
        setEventState(eventInfo);
    }, [eventInfo]);

    const saveChanges = (editedEvent: EditingEvent) => {
        setEventState({ ...eventState, ...editedEvent });
        editEvent(eventInfo.id, editedEvent);
        cancelEditing();
    }
    if (!privateAccess) {
        <EventView 
            event={eventState}
            privateAccess={privateAccess}
        />;
    }

    return isEditing ? 
        <EventEditing 
            saveChanges={saveChanges}
            cancelEditing={cancelEditing}
            event={eventState}
        /> : 
        <EventView 
            event={eventState} 
            openEditing={openEditing}
            privateAccess={privateAccess}
        />;
}

export default EventPageController
