import {FC, useMemo} from 'react';
import DatePicker from "react-datepicker";
import {DateTime} from "luxon";
import {Button} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import styles from './calendar-date-picker.module.scss'

interface CalendarDatePickerProps {
    currentDate: Date;
    view: string;
    onDateChange: (date: Date | null) => void;
    // onViewChange: (view: string) => void;
}


const CalendarDatePicker: FC<CalendarDatePickerProps> = (props) => {
    const trans = useTrans();
    const {currentDate, view, onDateChange} = props;

    // const dateFormat = useMemo(() => {
    //     switch (view) {
    //         case 'timeGridDay':
    //             return "d. MMMM yyyy";
    //         case 'timeGridWeek':
    //         case 'listWeek':
    //             const weekStart = DateTime.fromJSDate(currentDate).startOf('week');
    //             const weekEnd = DateTime.fromJSDate(currentDate).endOf('week');
    //             // console.log(weekStart.toFormat('d'), weekEnd.toFormat('d'))
    //             // return `${weekStart.toFormat('d')} - ${weekEnd.toFormat('d')} ${weekEnd.toFormat('MMMM yyyy')}`;
    //         case 'dayGridMonth':
    //             return "MMMM yyyy";
    //         default:
    //             return undefined;
    //     }
    // }, [view])
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
    // console.log({showWeekPicker: view === 'timeGridWeek' || 'listWeek', showMonthYearPicker: view === 'dayGridMonth'})
    return (
        <DatePicker
            selected={currentDate}
            onChange={onDateChange}
            // dateFormat={dateFormat}
            customInput={(
                <div className={styles.datePicker}>
                    <Button variant="tertiary" data-size="sm" className={styles.datePickerButton}>
                        {formatSelectedDate()}
                    </Button>
                </div>

            )}
            todayButton={trans('common.today')}
            showMonthYearPicker={view === 'dayGridMonth'}
            showWeekNumbers={true}
            showWeekPicker={view === 'timeGridWeek' || view === 'listWeek'}
        />
    );
}

export default CalendarDatePicker


