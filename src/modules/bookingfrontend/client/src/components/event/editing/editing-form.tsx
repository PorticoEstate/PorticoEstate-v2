'use client'
import { FC } from "react";
import { Field, Label, Input, Textfield } from "@digdir/designsystemet-react";
import { ActivityData } from "@/service/api/event-info";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import styles from '../event.module.scss';
import { DateTime } from "luxon";
import MaxParticipantInput from "./max-participant-input";
import DatePickerInput from "../../date-picker/datepicker-input";
import ResourcesGroup from "./resources-group";
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
                value={event.activity_name}
                onChange={(e) => updateField('activity_name', e.target.value)}
            />
            <DatePickerInput 
                date={event.info_when}
                updateDate={(date: Date) => updateField('info_when', date)}
            />
            <Field>
                <span className={styles.inputLabel}>Time</span>
                <div className={styles.editTimeBlock}>
                    {/* TODO: Optimize parsing date from input.time */}
                    <Textfield
                        prefix={t('bookingfrontend.from')} 
                        label="" 
                        type="time"
                        value={DateTime.fromJSDate(event.from_).toFormat('HH:mm')}
                        onChange={(e) => updateField('from_', DateTime.fromFormat(e.target.value, 'HH:mm').toJSDate())}
                    />
                    <Textfield 
                        prefix={t('bookingfrontend.to')} 
                        label="" 
                        type="time"
                        value={DateTime.fromJSDate(event.to_).toFormat('HH:mm')}
                        onChange={(e) => updateField('to_', DateTime.fromFormat(e.target.value, 'HH:mm').toJSDate())}
                    />
                </div>
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
                    updateField={(data: any) => updateField('info_resource_info', data)}
                    allResources={event.info_resources_allResources}
                    selectedResources={event.info_resource_info}
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
                    fieldValue={event.info_participant_limit}
                />
            </Field>
        </div>
    )
}

export default EventEditingForm
