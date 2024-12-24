"use client"
import EventEditing from "@/components/event/editing/editing-controller";
import { ActivityData } from "@/service/api/event-info";
import { FC, useState } from "react";
import { DateTime } from 'luxon';
import EventView from "./page-view";

interface EventPageProps {
    event: ActivityData;
}

const EventPageController: FC<EventPageProps> = ({ event }: EventPageProps) => {
    const [isEditing, setEditingMode] = useState(false);
    const [eventState, setEventState] = useState(event);
    const cancelEditing = () => setEditingMode(false);
    const openEditing = () => setEditingMode(true);
    const saveChanges = (newEventObject: ActivityData) => {
        //TODO: Send update request
        setEventState(newEventObject);
        cancelEditing();
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
        />;
}

export default EventPageController
