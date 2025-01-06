'use client'
import { FC } from "react";
import {Field, Textfield, Label} from "@digdir/designsystemet-react";
import { ActivityData } from "@/service/api/event-info";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import styles from '../event.module.scss';
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
                <Label>Time</Label>
                <div className={styles.editTimeBlock}>
                    <Textfield
                        prefix={t('bookingfrontend.from')} 
                        label="" 
                        type="time"
                        onChange={(e) => updateField('from_', e.target.value)}
                    />
                    <Textfield 
                        prefix={t('bookingfrontend.to')} 
                        label="" 
                        type="time"
                        onChange={(e) => updateField('to_', e.target.value)}
                    />
                </div>
            </Field>
            <Textfield 
                    label={t('bookingfrontend.place')}
                    value={event.building_name}
                    readOnly={true}
                />
            <Field>
                <Label>{t('bookingfrontend.resource')}</Label>
                <ResourcesGroup 
                    updateField={(data: any) => updateField('info_resource_info', data)}
                    allResources={event.info_resources_allResources}
                    selectedResources={event.info_resource_info}
                />
            </Field>
            <Textfield 
                    label={t('bookingfrontend.organizer')}
                    onChange={(e) => updateField('organizer', e.target.value)}
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
