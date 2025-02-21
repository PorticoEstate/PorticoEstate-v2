'use client'
import { FC, forwardRef, useRef } from "react";
import DatePicker from "react-datepicker";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faCalendarAlt, faChevronLeft, faChevronRight, faPen } from "@fortawesome/free-solid-svg-icons";
import { Field, Input, Label } from "@digdir/designsystemet-react";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { DateTime } from 'luxon';
import styles from './datepicker.module.scss';
import './datepicker-children.scss'
import 'react-datepicker/dist/react-datepicker.css'
import YearDropdown from "./year-dropdown";

interface CustomHeaderProps {
    date: Date,
    changeYear: (year: number) => void;
    decreaseMonth: () => void;
    increaseMonth: () => void;
}
interface DataPickerProps {
    date: Date;
    updateDate: (date: Date) => void;
}

const DatePickerInput: FC<DataPickerProps> = ({ date, updateDate }: DataPickerProps) => {
    const ref = useRef<DatePicker>(null);
    const t = useTrans();

    // eslint-disable-next-line react/display-name
    const CustomInput = forwardRef<HTMLInputElement>(({ onClick }: any, ref) => (
        <Field style={{marginBottom: 0}} onClick={() => onClick()}>
            <Label style={{marginBottom: 0}}>{t('bookingfrontend.date')}</Label>
            <Field.Affixes>
                <Field.Affix><FontAwesomeIcon icon={faCalendarAlt}/></Field.Affix>
                <Input 
                    ref={ref}
                    value={DateTime.fromJSDate(date).toFormat('dd.MM.yyyy')}
                />
            </Field.Affixes>
        </Field>
    ));

    const CustomHeader = ({
        date,
        changeYear,
        decreaseMonth,
        increaseMonth,
    }: CustomHeaderProps) => {
        const dateTime = DateTime.fromJSDate(date);
        return (
            <div className={styles.datePickerBlock}>
                <h4>Select date</h4>
                <div className={styles.dateTextBlock}>
                    <h2 style={{marginBottom: '1 rem'}}>{dateTime.toFormat('cccc, dd MMM')}</h2>
                    <FontAwesomeIcon icon={faPen} /> 
                </div>
                <hr />
                <div className={styles.dateTextBlock}>
                    <div className={styles.yearDropdownContainer}>
                        <h4>{dateTime.toFormat('LLLL y')}</h4>
                        <YearDropdown onChange={(year: number) => changeYear(year)}/> 
                    </div>
                    <div className={styles.dateTextBlock}>
                        <FontAwesomeIcon 
                            icon={faChevronLeft}
                            style={{marginRight: '2rem'}}
                            onClick={decreaseMonth}
                        />  
                        <FontAwesomeIcon 
                            icon={faChevronRight} 
                            onClick={increaseMonth}
                        /> 
                    </div>
                </div>
            </div>
        )
    }

    return (
        <div>
            <DatePicker 
                selected={date} 
                onChange={(date: Date | null) => updateDate(date as Date)}
                customInput={<CustomInput/>}
                renderCustomHeader={CustomHeader}
                withPortal
                dayClassName={() => styles.datepickerCustomDay}
                ref={ref}
            >
                <h4 
                    className={styles.datepickerBottom} 
                    onClick={() => ref.current?.setOpen(false)}
                >Cancel
                </h4>
            </DatePicker>
        </div>
    )
}

export default DatePickerInput
