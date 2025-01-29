"use client"
import EventEditing from "@/components/event/editing/editing-controller";
import { ActivityData, editEvent, useEventData } from "@/service/api/event-info";
import { FC, useState, useEffect } from "react";
import EventView from "./page-view";

interface EventPageProps {
    event: ActivityData;
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

    const saveChanges = (newEventObject: ActivityData) => {
        const updatedData: any = {};
        for (const field in newEventObject) {
            const f: keyof ActivityData = field as any;
            if (f === 'resources') {
                const new_ids = newEventObject[f].keys();
                updatedData.resource_ids = [...(new Set([...new_ids]))];
                continue;
            }
            if (newEventObject[f] !== eventInfo[f]) {
                updatedData[f] = newEventObject[f];
            }
        }
        setEventState(newEventObject);
        editEvent(eventInfo.id, updatedData);
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
