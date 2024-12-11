'use client'
import {FC} from "react";
import {Field, Textfield} from "@digdir/designsystemet-react";
import { FilteredEventInfo } from "@/service/api/event-info";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";

interface FormProps {
    eventBase: FilteredEventInfo
    setEventObject: (newData: FilteredEventInfo) => void
}

const EventEditingForm: FC<FormProps> = ({ eventBase, setEventObject }: FormProps) => {
    const updateField = (key: string, value: string) => {
        const copy = {...eventBase}
        copy[key] = value;
        setEventObject(copy);
    }
    const t = useTrans();
    return (
        <div>
            <Field>
                <Textfield 
                    label={t('Title_')}
                    value={eventBase.activity_name}
                    onChange={(e) => updateField('activity_name', e.target.value)}
                />
            </Field>
            <Field>
                <Textfield label={t('Date_')}/>
            </Field>
            <Field>
                <Textfield label={t('Time_')}/>
            </Field>
            <Field>
                <Textfield label={t('Place_')}/>
            </Field>
            <Field>
                <Textfield label={t('Resource')}/>
            </Field>
            <Field>
                <Textfield label={t('Organizer_')}/>
            </Field>
            <Field>
                <Textfield label={t('Max participants_')}/>
            </Field>
        </div>
    )
}

export default EventEditingForm
