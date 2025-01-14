// CalendarDatePicker.tsx
import { FC } from 'react';
import DatePicker from "react-datepicker";
import { ChevronLeftIcon, ChevronRightIcon } from "@navikt/aksel-icons";
import {Button, Select} from "@digdir/designsystemet-react";
import styles from './calendar-date-picker.module.scss';
import { DateTime } from "luxon";

interface CalendarDatePickerProps {
    currentDate: Date;
    view: string;
    onDateChange: (date: Date | null) => void;
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
            style={{ borderRadius: "50%" }}
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
                onChange={({ target: { value } }) => changeMonth(parseInt(value, 10))}
            >
                {Array.from({ length: 12 }, (_, i) => i).map((month) => (
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
                onChange={({ target: { value } }) => changeYear(parseInt(value, 10))}
            >
                {Array.from(
                    { length: 12 },
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
            style={{ borderRadius: "50%" }}
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
                                                             onDateChange
                                                         }) => {
    const formatSelectedDate = (showYear?: boolean) => {
        const luxonDate = DateTime.fromJSDate(currentDate).setLocale('nb');
        switch (view) {
            case 'timeGridDay':
                return luxonDate.toFormat(`d'.' MMMM${showYear ? ' yyyy' : ''}`);
            case 'timeGridWeek':
            case 'listWeek':
                const weekStart = luxonDate.startOf('week');
                const weekEnd = luxonDate.endOf('week');
                return `${weekStart.toFormat('d')} - ${weekEnd.toFormat('d')} ${weekEnd.toFormat(`MMMM ${showYear ? ' yyyy' : ''}`)}`;
            case 'dayGridMonth':
                return luxonDate.toFormat(`MMMM yyyy`);
            default:
                return luxonDate.toFormat(`d'.' MMMM ${showYear ? ' yyyy' : ''}`);
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
                showFourColumnMonthYearPicker={false}
                shouldCloseOnSelect={true}
                customInput={(
                    <div className={styles.datePicker}>
                        <Button
                            variant="tertiary"
                            data-size="sm"
                            className={styles.datePickerButton}
                        >
                            {formatSelectedDate()}
                        </Button>
                    </div>
                )}
            />
        </div>
    );
};

export default CalendarDatePicker;