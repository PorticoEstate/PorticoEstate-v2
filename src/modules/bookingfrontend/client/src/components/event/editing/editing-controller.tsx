'use client'
import {FC} from "react";
import {Button} from "@digdir/designsystemet-react";
import { ActivityData } from "@/service/api/event-info";
import styles from '../event.module.scss';
import EventEditingForm from "./editing-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { EditingEvent, eventFormSchema } from "./eventFormSchema";

interface EventEditingProps {
    event: ActivityData;
    saveChanges: (newEventObject: EditingEvent) => void;
    cancelEditing: () => void;
}

const EventEditing: FC<EventEditingProps> = ({ event, saveChanges, cancelEditing }: EventEditingProps) => {
    const t = useTrans();
    const {
        control,
        handleSubmit,
        formState: {isDirty, errors, isValid},
    } = useForm({
        mode: 'onChange',
        resolver: zodResolver(eventFormSchema),
        defaultValues: {
            name: event.name,
            from_: event.from_,
            to_: event.to_,
            participant_limit: event.participant_limit || 0,
            organizer: event.organizer,
            resources: event.resources,
            building_name: event.building_name,
        }
    });


    // const updateField = (key: keyof ActivityData, value: string | number) => {
    //     const copy = {...draft, [key]: value};
    //     if (JSON.stringify(copy) !== JSON.stringify(event)) setStatus(true);
    //     else setStatus(false);
    //     if (key === 'resources' && !readyToUpdate) {
    //         const newResources = copy['resources'];
    //         const oldResources = event['resources'];
    //         if (newResources.size !== oldResources.size) {
    //             setStatus(true);
    //         } else {
    //             let flag = false;
    //             for (const key of newResources.keys()) {
    //                 if (!oldResources.has(key)) {
    //                     flag = true;
    //                     break;
    //                 }
    //             }
    //             setStatus(flag);
    //         }
    //     }
    //     setDraft(copy);
    // }
    return (
        <main>
            <EventEditingForm control={control} errors={errors} event={event} />
            <div className={styles.controllButtonsContainer}>
                <Button 
                    variant="secondary" 
                    onClick={cancelEditing}
                    style={{ marginRight: '0.5rem' }}
                >{t('bookingfrontend.cancel')}</Button>
                <Button 
                    disabled={!isDirty || !isValid}
                    onClick={handleSubmit(saveChanges)}
                >
                    {t('bookingfrontend.save')}
                </Button>
            </div>
        </main>
    )
}

export default EventEditing
