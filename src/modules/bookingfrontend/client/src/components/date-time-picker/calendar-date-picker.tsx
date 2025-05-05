import React, {FC, useEffect, useRef} from 'react';
import DatePicker from "react-datepicker";
import {CalendarIcon, ChevronLeftIcon, ChevronRightIcon} from "@navikt/aksel-icons";
import {Button, Field, Input, Label, Select} from "@digdir/designsystemet-react";
import styles from './calendar-date-picker.module.scss';
import {DateTime} from "luxon";
import {useClientTranslation, useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useIsMobile} from "@/service/hooks/is-mobile";

interface CalendarDatePickerProps {
	currentDate: Date;
	view: string;
	onDateChange: (date: Date | null) => void;
	showTimeSelect?: boolean;
	timeIntervals?: number;
	dateFormat?: string;
	minTime?: string;
	maxTime?: string;
	/** If true, dates in the past can be selected (defaults to false) */
	allowPastDates?: boolean;
	/** Specify a custom minimum date (takes precedence over allowPastDates) */
	minDate?: Date;
	/** If true, shows debug information (ISO string of the date) below the input */
	showDebug?: boolean;
	/** If true, always shows the year in the formatted date regardless of view (defaults to false) */
	showYear?: boolean;
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

const TimePicker: FC<TimePickerProps & { minTime?: string; maxTime?: string }> =
	({date, onChangeDate, intervals = 30, minTime, maxTime}) => {
		const t= useTrans();
		const getHourRange = () => {
			const minHour = minTime ? parseInt(minTime.split(':')[0]) : 0;
			const maxHour = maxTime ? parseInt(maxTime.split(':')[0]) : 23;
			return Array.from({length: maxHour - minHour + 1}, (_, i) => i + minHour);
		};

		const hours = getHourRange();
		const minutes = Array.from({length: 60 / intervals}, (_, i) => i * intervals);

		const hoursListRef = useRef<HTMLDivElement>(null);
		const minutesListRef = useRef<HTMLDivElement>(null);

		const selectedHour = date.getHours();
		const selectedMinute = date.getMinutes();

		useEffect(() => {
			// Scroll to selected hour
			if (hoursListRef.current) {
				const hourElement = hoursListRef.current.querySelector(`[data-hour="${selectedHour}"]`);
				if (hourElement) {
					hourElement.scrollIntoView({block: 'center', behavior: 'smooth'});
				}
			}

			// Scroll to selected minute
			if (minutesListRef.current) {
				const minuteElement = minutesListRef.current.querySelector(`[data-minute="${selectedMinute}"]`);
				if (minuteElement) {
					minuteElement.scrollIntoView({block: 'center', behavior: 'smooth'});
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
						<div className={styles.timeColumnHeader}>{t('bookingfrontend.hour')}</div>
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
						<div className={styles.timeColumnHeader}>{t('bookingfrontend.minute')}</div>
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
											 }) => {
	// Get current language from i18n via HTML attribute
	const { i18n } = useClientTranslation();
	const currentLang = i18n.language || 'no';

	return (
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
							{new Date(date.getFullYear(), month).toLocaleString(currentLang, {
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
};


const CalendarDatePicker: FC<CalendarDatePickerProps> = ({
															 currentDate,
															 view,
															 onDateChange,
															 showTimeSelect = false,
															 timeIntervals = 30,
															 dateFormat = 'dd.MM.yyyy HH:mm',
															 maxTime,
															 minTime,
															 allowPastDates = false,
															 minDate,
															 showDebug = false,
															 showYear = false
														 }) => {
	// Get current language from i18n
	const { i18n } = useClientTranslation();
	const currentLang = i18n.language || 'no';
	const isMobile = useIsMobile();

	const formatSelectedDate = (showYear?: boolean) => {
		const luxonDate = DateTime.fromJSDate(currentDate).setLocale(currentLang);
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

	const handleNativeDateChange = (e: React.ChangeEvent<HTMLInputElement>) => {
		if (!e.target.value) return;

		const newDate = new Date(e.target.value);

		// If using datetime-local, it already has time
		if (e.target.type === 'datetime-local') {
			// Ensure time is rounded to the nearest interval
			if (timeIntervals) {
				const minutes = newDate.getMinutes();
				const roundedMinutes = Math.round(minutes / timeIntervals) * timeIntervals;
				newDate.setMinutes(roundedMinutes);
				newDate.setSeconds(0);
			}
			onDateChange(newDate);
			return;
		}

		// If just date input, preserve current time
		if (e.target.type === 'date') {
			newDate.setHours(currentDate.getHours());
			newDate.setMinutes(currentDate.getMinutes());
			newDate.setSeconds(currentDate.getSeconds());
			onDateChange(newDate);
		}
	};

	// Determine the minimum date
	// If minDate is specifically set, use that
	// Otherwise, use today's date unless allowPastDates is true
	const effectiveMinDate = minDate ? minDate : allowPastDates ? undefined : new Date(new Date().setHours(0, 0, 0, 0));

	// Format the min date for HTML inputs
	const getMinDateString = () => {
		if (minDate) {
			return DateTime.fromJSDate(minDate).toFormat('yyyy-MM-dd');
		}

		if (!allowPastDates) {
			return DateTime.now().toFormat('yyyy-MM-dd');
		}

		// If past dates are allowed, don't set a min value
		return undefined;
	};

	const minDateString = getMinDateString();

	// Helper function to ensure time aligns with the intervals
	const alignTimeToInterval = (date: Date): Date => {
		if (!timeIntervals) return date;

		const result = new Date(date);
		const minutes = result.getMinutes();
		const roundedMinutes = Math.round(minutes / timeIntervals) * timeIntervals;
		result.setMinutes(roundedMinutes);
		result.setSeconds(0);
		return result;
	};

	// Use native date inputs on mobile
	if (isMobile) {
		// Ensure the current date's time aligns with the intervals
		const alignedDate = alignTimeToInterval(currentDate);
		const dateValue = DateTime.fromJSDate(alignedDate).toFormat('yyyy-MM-dd');
		const dateTimeValue = DateTime.fromJSDate(alignedDate).toFormat('yyyy-MM-dd\'T\'HH:mm');

		return (
			<div >
				<Field className={styles.datePicker}>
					<Field.Affixes>
						<Field.Affix><CalendarIcon title="a11y-title" fontSize="1.5rem"/></Field.Affix>
						{showTimeSelect ? (
							<Input
								type="datetime-local"
								value={dateTimeValue}
								onChange={handleNativeDateChange}
								min={minDateString ? minDateString + "T00:00" : undefined}
								step={timeIntervals * 60} // Convert minutes to seconds for step attribute
							/>
						) : (
							<Input
								type="date"
								value={dateValue}
								onChange={handleNativeDateChange}
								min={minDateString}
							/>
						)}
					</Field.Affixes>
				</Field>
				{showDebug && (
					/* DEBUG: Display JavaScript Date as ISO string */
					<div style={{
						fontSize: '10px',
						color: '#666',
						marginTop: '4px',
						fontFamily: 'monospace',
						wordBreak: 'break-all'
					}}>
						<span style={{ fontWeight: 'bold' }}>Debug:</span> {alignedDate.toISOString()}
					</div>
				)}
			</div>
		);
	}

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
				locale={currentLang}
				minDate={effectiveMinDate}
				customTimeInput={
					<TimePicker onChangeDate={(e) => {
						onDateChange(e)
					}}
								maxTime={maxTime}
								minTime={minTime}
								date={currentDate} intervals={timeIntervals}/>
				}
				customInput={(
					<Field>
						<Field.Affixes>
							<Field.Affix><CalendarIcon title="a11y-title" fontSize="1.5rem"/></Field.Affix>
							<Input className={"dateView"} onChange={() => {
							}} value={formatSelectedDate(showYear)}/>
						</Field.Affixes>
					</Field>
				)}
			/>
			{showDebug && (
				/* DEBUG: Display JavaScript Date as ISO string */
				<div style={{
					fontSize: '10px',
					color: '#666',
					marginTop: '4px',
					fontFamily: 'monospace',
					wordBreak: 'break-all'
				}}>
					<span style={{ fontWeight: 'bold' }}>Debug:</span> {alignTimeToInterval(currentDate).toISOString()}
				</div>
			)}
		</div>
	);
};

export default CalendarDatePicker;