'use client'
import {FC} from "react";
import {Field, Textfield, Chip} from "@digdir/designsystemet-react";
import { FilteredEventInfo } from "@/service/api/event-info";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import styles from '../event.module.scss';
import MaxParticipantInput from "./max-participant-input";
import DatePickerInput from "./datepicker-input";

interface FormProps {
    event: FilteredEventInfo
    updateField: (key: keyof FilteredEventInfo, value: any) => void
}

const EventEditingForm: FC<FormProps> = ({ event, updateField }: FormProps) => {
    const t = useTrans();

    return (
        <div className={styles.editForm}>
            <Field>
                <Field.Description>{t('Title_')}</Field.Description>
                <Textfield
                    label=""
                    value={event.activity_name}
                    onChange={(e) => updateField('activity_name', e.target.value)}
                />
            </Field>
            <DatePickerInput 
                date={event.info_when}
                updateDate={(date: Date) => updateField('info_when', date)}
            />
            <Field>
                <Field.Description>{t('Time')}</Field.Description>
                <div className={styles.editTimeBlock}>
                    <Textfield
                        prefix={t('From')} 
                        label="" 
                        type="time"
                        onChange={(e) => updateField('from_', e.target.value)}
                    />
                    <Textfield 
                        prefix={t('To')} 
                        label="" 
                        type="time"
                        onChange={(e) => updateField('to_', e.target.value)}
                    />
                </div>
            </Field>
            <Field>
                <Field.Description>{t('Place_')}</Field.Description>
                <Textfield label=""/>
            </Field>
            <Field>
                <Field.Description>{t('Resource_')}</Field.Description>
                <div>
                    { event.info_resource_info.split(', ').map((res) => (
                        <Chip.Checkbox key={res}>{res}</Chip.Checkbox>
                    ))} 
                </div>
            </Field>
            <Field>
                <Field.Description>{t('Organizer_')}</Field.Description>
                <Textfield label=""/>
            </Field>
            <Field>
                <Field.Description>{t('Max participants')}</Field.Description>
                <MaxParticipantInput updateField={updateField} fieldValue={event.info_participant_limit}/>
            </Field>
        </div>
    )
}

export default EventEditingForm
