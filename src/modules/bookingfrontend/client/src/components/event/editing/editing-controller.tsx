'use client'
import {FC, useState} from "react";
import {Button} from "@digdir/designsystemet-react";
import { ActivityData } from "@/service/api/event-info";
import styles from '../event.module.scss';
import EventEditingForm from "./editing-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";

interface EventEditingProps {
    event: ActivityData;
    saveChanges: (newEventObject: ActivityData) => void;
    cancelEditing: () => void;
}

const EventEditing: FC<EventEditingProps> = ({ event, saveChanges, cancelEditing }: EventEditingProps) => {
    const [draft, setDraft] = useState(event);
    const [readyToUpdate, setStatus] = useState(false);
    const t = useTrans();

    const updateField = (key: keyof ActivityData, value: string | number) => {
        const copy = {...draft, [key]: value};
        if (JSON.stringify(copy) !== JSON.stringify(event)) setStatus(true);
        else setStatus(false);
        if (key === 'resources' && !readyToUpdate) {
            const newResources = copy['resources'];
            const oldResources = event['resources'];
            if (newResources.size !== oldResources.size) {
                setStatus(true);
            } else {
                let flag = false;
                for (const key of newResources.keys()) {
                    if (!oldResources.has(key)) {
                        flag = true;
                        break;
                    }
                }
                setStatus(flag);
            }
        }
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
                <Button disabled={!readyToUpdate} onClick={() => saveChanges(draft)}>{t('bookingfrontend.save')}</Button>
            </div>
        </main>
    )
}

export default EventEditing
