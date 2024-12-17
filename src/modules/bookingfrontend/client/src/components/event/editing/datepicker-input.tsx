'use client'
import {FC, forwardRef} from "react";
import DatePicker from "react-datepicker";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faCalendarAlt } from "@fortawesome/free-solid-svg-icons";
import { Field, Input } from "@digdir/designsystemet-react";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";


interface DataPickerProps {
    date: any;
    updateDate: (date: Date) => void;
}

const DatePickerInput: FC<DataPickerProps> = ({ date, updateDate }: DataPickerProps) => {
    const t = useTrans();
    
    const CustomInput = forwardRef(({ value, onClick }, ref) => (
        <Field ref={ref} onClick={onClick}>
            <Field.Description>{t('Date_')}</Field.Description>
                <Field.Affixes>
                    <Field.Affix><FontAwesomeIcon icon={faCalendarAlt}/></Field.Affix>
                     <Input 
                        value={value}
                    />
                </Field.Affixes>
        </Field>
    ));

    return (
        <DatePicker 
            selected={date} 
            onChange={(date: Date) => updateDate(date)}
            customInput={<CustomInput />}
        />
    )
}

export default DatePickerInput
