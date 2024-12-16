'use client'
import {FC} from "react";
import {Field, Input, Textfield} from "@digdir/designsystemet-react";
import { FilteredEventInfo } from "@/service/api/event-info";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import styles from '../event.module.scss';
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faCalendarAlt } from "@fortawesome/free-solid-svg-icons";
import MaxParticipantInput from "./max-participant-input";

interface FormProps {
    event: FilteredEventInfo
    updateField: (key: keyof FilteredEventInfo, value: number | string) => void
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
            <Field>
                 <Field.Description>{t('Date_')}</Field.Description>
                <Field.Affixes>
                    <Field.Affix><FontAwesomeIcon icon={faCalendarAlt}/></Field.Affix>
                    <Input 
                        onChange={(e) => updateField('info_when', e.target.value)} 
                        type="date"
                    />
                </Field.Affixes>
               
            </Field>
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
                <Textfield label=""/>
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
