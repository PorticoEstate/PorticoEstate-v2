'use client'
import {FC, useState} from "react";
import {Button} from "@digdir/designsystemet-react";
import { FilteredEventInfo } from "@/service/api/event-info";
import styles from '../event.module.scss';
import EventEditingForm from "./editing-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";

interface EventEditingProps {
    event: FilteredEventInfo
    saveChanges: (newEventObject: FilteredEventInfo) => void;
    cancelEditing: () => void;
}

const EventEditing: FC<EventEditingProps> = ({ event, saveChanges, cancelEditing }: EventEditingProps) => {
    const [draft, setDraft] = useState(event);
    const t = useTrans();

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
                >{t('bookingfrontend.cancel')}</Button>
                <Button onClick={() => saveChanges(draft)}>{t('bookingfrontend.save')}</Button>
            </div>
        </main>
    )
}

export default EventEditing
