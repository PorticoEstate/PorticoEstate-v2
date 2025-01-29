import React, {FC, useEffect, useRef} from 'react';
import DatePicker from "react-datepicker";
import {CalendarIcon, ChevronLeftIcon, ChevronRightIcon} from "@navikt/aksel-icons";
import {Button, Field, Input, Label, Select} from "@digdir/designsystemet-react";
import styles from './calendar-date-picker.module.scss';
import {DateTime} from "luxon";

interface CalendarDatePickerProps {
    currentDate: Date;
    view: string;
    onDateChange: (date: Date | null) => void;
    showTimeSelect?: boolean;
    timeIntervals?: number;
    dateFormat?: string;
}


interface CustomHeaderProps {
    date: Date;
    changeYear: (year: number) => void;
    changeMonth: (month: number) => void;
    decreaseMonth: () => void;
    increaseMonth: () => void;
    prevMonthButtonDisabled: boolean;
    nextMonthButtonDisabled: boolean;
    decreaseYear?: () => void;
    increaseYear?: () => void;
    prevYearButtonDisabled?: boolean;
    nextYearButtonDisabled?: boolean;
}

interface TimePickerProps {
    date: Date;
    onChangeDate: (date: Date) => void;
    intervals?: number;
}

const TimePicker: FC<TimePickerProps> = ({ date, onChangeDate, intervals = 30 }) => {
    const hours = Array.from({ length: 24 }, (_, i) => i);
    const minutes = Array.from({ length: 60 / intervals }, (_, i) => i * intervals);

    const hoursListRef = useRef<HTMLDivElement>(null);
    const minutesListRef = useRef<HTMLDivElement>(null);

    const selectedHour = date.getHours();
    const selectedMinute = date.getMinutes();

    useEffect(() => {
        // Scroll to selected hour
        if (hoursListRef.current) {
            const hourElement = hoursListRef.current.querySelector(`[data-hour="${selectedHour}"]`);
            if (hourElement) {
                hourElement.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        }

        // Scroll to selected minute
        if (minutesListRef.current) {
            const minuteElement = minutesListRef.current.querySelector(`[data-minute="${selectedMinute}"]`);
            if (minuteElement) {
                minuteElement.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        }
    }, [selectedHour, selectedMinute]);

    const handleHourClick = (hour: number) => {
        const newDate = new Date(date);
        newDate.setHours(hour);
        onChangeDate(newDate);
    };

    const handleMinuteClick = (minute: number) => {
        const newDate = new Date(date);
        newDate.setMinutes(minute);
        onChangeDate(newDate);
    };

    return (
        <div className={styles.timeInput}>
            <div className={styles.timeColumns}>
                <div className={styles.timeColumn}>
                    <div className={styles.timeColumnHeader}>Timer</div>
                    <div className={styles.timeColumnList} ref={hoursListRef}>
                        {hours.map(hour => (
                            <div
                                key={hour}
                                data-hour={hour}
                                className={`${styles.timeColumnListItem} ${
                                    hour === selectedHour ? styles.timeColumnListItemSelected : ''
                                }`}
                                onClick={() => handleHourClick(hour)}
                            >
                                {hour.toString().padStart(2, '0')}
                            </div>
                        ))}
                    </div>
                </div>
                <div className={styles.timeColumn}>
                    <div className={styles.timeColumnHeader}>Minutter</div>
                    <div className={styles.timeColumnList} ref={minutesListRef}>
                        {minutes.map(minute => (
                            <div
                                key={minute}
                                data-minute={minute}
                                className={`${styles.timeColumnListItem} ${
                                    minute === selectedMinute ? styles.timeColumnListItemSelected : ''
                                }`}
                                onClick={() => handleMinuteClick(minute)}
                            >
                                {minute.toString().padStart(2, '0')}
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
};


const CustomHeader: FC<CustomHeaderProps> = ({
                                                 date,
                                                 changeYear,
                                                 changeMonth,
                                                 decreaseMonth,
                                                 increaseMonth,
                                                 prevMonthButtonDisabled,
                                                 nextMonthButtonDisabled
                                             }) => (
    <div className={styles.header}>
        <Button
            onClick={decreaseMonth}
            disabled={prevMonthButtonDisabled}
            className={styles.navButton}
            data-size="sm"
            variant="tertiary"
            icon={true}
            style={{borderRadius: "50%"}}
        >
            <ChevronLeftIcon style={{
                height: '100%',
                width: '100%'
            }}/>
        </Button>

        <div className={styles.selects}>
            <Select
                className={styles.select}
                value={date.getMonth()}
                onChange={({target: {value}}) => changeMonth(parseInt(value, 10))}
            >
                {Array.from({length: 12}, (_, i) => i).map((month) => (
                    <Select.Option key={month} value={month}>
                        {new Date(date.getFullYear(), month).toLocaleString('nb', {
                            month: 'short',
                        })}
                    </Select.Option>
                ))}
            </Select>
            <Select
                className={styles.select}
                value={date.getFullYear()}
                onChange={({target: {value}}) => changeYear(parseInt(value, 10))}
            >
                {Array.from(
                    {length: 12},
                    (_, i) => date.getFullYear() - 5 + i
                ).map((year) => (
                    <Select.Option key={year} value={year}>
                        {year}
                    </Select.Option>
                ))}
            </Select>


        </div>

        <Button
            onClick={increaseMonth}
            disabled={nextMonthButtonDisabled}
            className={styles.navButton}
            data-size="sm"
            variant="tertiary"
            icon={true}
            style={{borderRadius: "50%"}}
        >
            <ChevronRightIcon style={{
                height: '100%',
                width: '100%'
            }}/>
        </Button>
    </div>
);


const CalendarDatePicker: FC<CalendarDatePickerProps> = ({
                                                             currentDate,
                                                             view,
                                                             onDateChange,
                                                             showTimeSelect = false,
                                                             timeIntervals = 30,
                                                             dateFormat = 'dd.MM.yyyy HH:mm'
                                                         }) => {
    const formatSelectedDate = (showYear?: boolean) => {
        const luxonDate = DateTime.fromJSDate(currentDate).setLocale('nb');
        const timeStr = showTimeSelect ? luxonDate.toFormat(' HH:mm') : '';

        switch (view) {
            case 'timeGridDay':
                return luxonDate.toFormat(`d'.' MMMM${showYear ? ' yyyy' : ''}`) + timeStr;
            case 'timeGridWeek':
            case 'listWeek':
                const weekStart = luxonDate.startOf('week');
                const weekEnd = luxonDate.endOf('week');
                return `${weekStart.toFormat('d')} - ${weekEnd.toFormat('d')} ${weekEnd.toFormat(`MMMM ${showYear ? ' yyyy' : ''}`)}${timeStr}`;
            case 'dayGridMonth':
                return luxonDate.toFormat(`MMMM yyyy`) + timeStr;
            default:
                return luxonDate.toFormat(`d'.' MMMM ${showYear ? ' yyyy' : ''}`) + timeStr;
        }
    };


    return (
        <div className={styles.datePicker}>
            <DatePicker
                selected={currentDate}
                onChange={onDateChange}
                showMonthYearPicker={view === 'dayGridMonth'}
                showWeekNumbers={true}
                showWeekPicker={view === 'timeGridWeek' || view === 'listWeek'}
                renderCustomHeader={(props) => <CustomHeader {...props} />}
                calendarClassName={styles.calendar}
                wrapperClassName={styles.wrapper}
                popperClassName={styles.popper}
                monthsShown={1}
                shouldCloseOnSelect={false}
                dateFormat={dateFormat}
                showTimeInput={showTimeSelect}
                // showTimeSelectOnly
                // timeIntervals={timeIntervals}
                customTimeInput={
                        <TimePicker onChangeDate={(e) => {
                            onDateChange(e)
                        }} date={currentDate} intervals={timeIntervals} />
                }
                customInput={(
                    <Field>
                        <Field.Affixes>
                            <Field.Affix><CalendarIcon title="a11y-title" fontSize="1.5rem" /></Field.Affix>
                            <Input className={"dateView"} onChange={() => {}} value={formatSelectedDate()}/>
                        </Field.Affixes>
                    </Field>
                    // <div className={styles.datePicker}>
                    //     <Button
                    //         variant="tertiary"
                    //         data-size="sm"
                    //         className={styles.datePickerButton}
                    //     >
                    //         {formatSelectedDate()}
                    //     </Button>
                    // </div>
                )}
            />
        </div>
    );
};

export default CalendarDatePicker;