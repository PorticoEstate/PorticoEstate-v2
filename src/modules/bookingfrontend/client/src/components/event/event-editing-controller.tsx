'use client'
import {FC} from "react";
import {Button} from "@digdir/designsystemet-react";
import { FilteredEventInfo } from "@/service/api/event-info";
import styles from './event-editing.module.scss';
import EventEditingForm from "./event-editing-form";

interface EventEditingProps {
    eventBase: FilteredEventInfo
    saveChanges: (newEventObject: FilteredEventInfo) => void;
    cancelEditing: () => void;
}

const EventEditing: FC<EventEditingProps> = ({ eventBase, saveChanges, cancelEditing }: EventEditingProps) => {
    return (
        <main>
            <EventEditingForm eventBase={eventBase} />
            <div className={styles.controllButtonsContainer}>
                <Button onClick={cancelEditing}>Cancel</Button>
                <Button onClick={saveChanges}>Save</Button>
            </div>
        </main>
    )
}

export default EventEditing
