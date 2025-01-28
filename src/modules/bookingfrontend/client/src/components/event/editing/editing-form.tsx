'use client'
import { FC } from "react";
import { Field, Label, Input, Textfield } from "@digdir/designsystemet-react";
import { ActivityData } from "@/service/api/event-info";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import styles from '../event.module.scss';
import { DateTime } from "luxon";
import MaxParticipantInput from "./max-participant-input";
import ResourcesGroup from "./resources-group";
import CalendarDatePicker from "@/components/date-time-picker/calendar-date-picker";
interface FormProps {
    event: ActivityData;
    updateField: (key: keyof ActivityData, value: any) => void;
}

const EventEditingForm: FC<FormProps> = ({ event, updateField }: FormProps) => {
    const t = useTrans();
    
    return (
        <div className={styles.editForm}>
            <Textfield
                label={t('bookingfrontend.title')}
                value={event.name}
                onChange={(e) => updateField('name', e.target.value)}
            />
            <Field>
                <span className={styles.inputLabel}>From</span>
                <CalendarDatePicker 
                    showTimeSelect
                    timeIntervals={5}
                    onDateChange={(date) => updateField('from_', date)}
                    currentDate={event.from_}
                    view="timeGridDay"
                />
            </Field>
            <Field>
                <span className={styles.inputLabel}>To</span>
                <CalendarDatePicker 
                    showTimeSelect
                    timeIntervals={5}
                    onDateChange={(date) => updateField('to_', date)}
                    currentDate={event.to_}
                    view="timeGridDay"
                />
            </Field>
            <Field>
                <span className={styles.inputLabel}>{t('bookingfrontend.place')}</span>
                <Input
                    value={event.building_name}
                    readOnly={true}
                />
            </Field>
            <Field style={{gap: '0'}}>
                <span className={styles.inputLabel}>{t('bookingfrontend.resource')}</span>
                <ResourcesGroup 
                    updateField={(data: any) => updateField('resources', data)}
                    buildingResources={event.buildingResources}
                    selectedResources={event.resources}
                />
            </Field>
            <Textfield 
                    label={t('bookingfrontend.organizer')}
                    onChange={(e) => updateField('organizer', e.target.value)}
                    value={event.organizer}
                />
            <Field>
                <Label>{t('bookingfrontend.max_participants_info')}</Label>
                <MaxParticipantInput 
                    updateField={updateField} 
                    fieldValue={event.participant_limit as number}
                />
            </Field>
        </div>
    )
}

export default EventEditingForm
