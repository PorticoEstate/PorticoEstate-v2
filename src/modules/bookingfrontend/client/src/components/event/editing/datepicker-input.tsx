'use client'
import {FC, forwardRef} from "react";
import DatePicker from "react-datepicker";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faCalendarAlt } from "@fortawesome/free-solid-svg-icons";
import { Field, Input } from "@digdir/designsystemet-react";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { DateTime } from 'luxon';
import 'react-datepicker/dist/react-datepicker.css'

interface DataPickerProps {
    date: Date;
    updateDate: (date: Date) => void;
}

const DatePickerInput: FC<DataPickerProps> = ({ date, updateDate }: DataPickerProps) => {
    const t = useTrans();
    // eslint-disable-next-line react/display-name
    const CustomInput = forwardRef<HTMLInputElement>(({ onClick }: any, ref) => (
        <Field style={{marginBottom: 0}} onClick={onClick}>
            <Field.Description style={{marginBottom: 0}}>{t('bookingfrontend.date')}</Field.Description>
                <Field.Affixes style={{width: '21rem'}}>
                    <Field.Affix><FontAwesomeIcon icon={faCalendarAlt}/></Field.Affix>
                     <Input 
                        ref={ref}
                        value={DateTime.fromJSDate(date).toFormat('dd.MM.yyyy')}
                    />
                </Field.Affixes>
        </Field>
    ));

    return (
        <div>
            <DatePicker 
                selected={date} 
                onChange={(date: Date | null) => updateDate(date as Date)}
                customInput={<CustomInput/>}
                withPortal
            />
        </div>
    )
}

export default DatePickerInput
