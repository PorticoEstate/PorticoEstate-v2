'use client'
import {FC, useState} from "react";
import {Button} from "@digdir/designsystemet-react";
import { FilteredEventInfo } from "@/service/api/event-info";
import styles from '../event.module.scss';
import EventEditingForm from "./editing-form";

interface EventEditingProps {
    event: FilteredEventInfo
    saveChanges: (newEventObject: FilteredEventInfo) => void;
    cancelEditing: () => void;
}

const EventEditing: FC<EventEditingProps> = ({ event, saveChanges, cancelEditing }: EventEditingProps) => {
    const [draft, setDraft] = useState(event);

    const updateField = (key: keyof FilteredEventInfo, value: string | number) => {
        const copy = {...draft, [key]: value}
        setDraft(copy);
    }

    return (
        <main>
            <EventEditingForm event={draft} updateField={updateField} />
            <div className={styles.controllButtonsContainer}>
                <Button 
                    variant="secondary" 
                    onClick={cancelEditing}
                    style={{ marginRight: '0.5rem' }}
                >Cancel</Button>
                <Button onClick={() => saveChanges(draft)}>Save</Button>
            </div>
        </main>
    )
}

export default EventEditing
