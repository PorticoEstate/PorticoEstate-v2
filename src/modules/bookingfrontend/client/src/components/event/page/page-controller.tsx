"use client"
import EventEditing from "@/components/event/editing/editing-controller";
import { ActivityData, editEvent } from "@/service/api/event-info";
import { FC, useState } from "react";
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
        const updatedData: any = {};
        for (const field in newEventObject) {
            const f: keyof ActivityData = field as any;
            if (f === 'resources') {
                const new_ids = newEventObject[f].keys();
                updatedData.resource_ids = [...(new Set([...new_ids]))];
                continue;
            }
            if (newEventObject[f] !== event[f]) {
                updatedData[f] = newEventObject[f];
            }
        }
        setEventState(newEventObject);
        editEvent(event.id, updatedData);
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
