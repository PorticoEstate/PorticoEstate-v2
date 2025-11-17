import React, {FC, useState, useRef, useEffect} from 'react';
import DatePicker from "react-datepicker";
import {CalendarIcon} from "@navikt/aksel-icons";
import {Field, Input} from "@digdir/designsystemet-react";
import styles from './calendar-date-picker.module.scss';
import {DateTime} from "luxon";
import {useClientTranslation} from "@/app/i18n/ClientTranslationProvider";
import {useIsMobile} from "@/service/hooks/is-mobile";
import TimePicker from './components/TimePicker';
import CustomHeader from './components/CustomHeader';
import type {CalendarDatePickerProps} from './types/interfaces';

const CalendarDatePicker: FC<CalendarDatePickerProps> = (props) => {
	const {
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
		maxDate,
		showDebug = false,
		showYear = false,
		seasons
	} = props;

	// Type guard to determine if this is an empty variant
	const allowEmpty = 'allowEmpty' in props && props.allowEmpty === true;
	const placeholder = allowEmpty ? props.placeholder : undefined;
	
	// Get current language from i18n
	const {i18n} = useClientTranslation();
	const currentLang = i18n.language || 'no';
	const isMobile = useIsMobile();
	
	const [isCalendarOpen, setIsCalendarOpen] = useState<boolean>(false);
	const datePickerRef = useRef<DatePicker>(null);
	const inputContainerRef = useRef<HTMLDivElement>(null);

	// Check if element is in viewport
	const isElementInViewport = (element: HTMLElement) => {
		const rect = element.getBoundingClientRect();
		return (
			rect.top >= 0 &&
			rect.left >= 0 &&
			rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
			rect.right <= (window.innerWidth || document.documentElement.clientWidth)
		);
	};

	// Handle scroll events to close calendar if input is out of view
	useEffect(() => {
		if (!isCalendarOpen || isMobile) return;

		const handleScroll = () => {
			if (inputContainerRef.current && !isElementInViewport(inputContainerRef.current)) {
				// Input is out of view, close the calendar
				if (datePickerRef.current) {
					datePickerRef.current.setOpen(false);
				}
				setIsCalendarOpen(false);
			}
		};

		// Add scroll listeners to window and all scrollable parents
		const addScrollListeners = () => {
			window.addEventListener('scroll', handleScroll, true);
			window.addEventListener('resize', handleScroll);
		};

		const removeScrollListeners = () => {
			window.removeEventListener('scroll', handleScroll, true);
			window.removeEventListener('resize', handleScroll);
		};

		addScrollListeners();
		return removeScrollListeners;
	}, [isCalendarOpen, isMobile]);

	const formatSelectedDate = (showYear?: boolean) => {
		if (!currentDate) {
			return placeholder || '';
		}

		const luxonDate = DateTime.fromJSDate(currentDate).setLocale(currentLang);
		const timeStr = showTimeSelect ? luxonDate.toFormat(' HH:mm') : '';

		switch (view) {
			case 'timeGridDay':
				return luxonDate.toFormat(`d'.' MMMM${showYear ? ' yyyy' : ''}`) + timeStr;
			case 'dayGridDay':
				return luxonDate.toFormat(`d'.' MMMM${showYear ? ' yyyy' : ''}`);
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
		if (!e.target.value) {
			if (allowEmpty) {
				(onDateChange as (date: Date | null) => void)(null);
			}
			return;
		}

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
			if (currentDate) {
				newDate.setHours(currentDate.getHours());
				newDate.setMinutes(currentDate.getMinutes());
				newDate.setSeconds(currentDate.getSeconds());
			}
			onDateChange(newDate);
		}
	};

	// Determine the minimum date
	const effectiveMinDate = minDate ? minDate : allowPastDates ? undefined : new Date(new Date().setHours(0, 0, 0, 0));

	// Format the min/max dates for HTML inputs
	const getMinDateString = () => {
		if (minDate) {
			return DateTime.fromJSDate(minDate).toFormat('yyyy-MM-dd');
		}

		if (!allowPastDates) {
			return DateTime.now().toFormat('yyyy-MM-dd');
		}

		return undefined;
	};

	const getMaxDateString = () => {
		if (maxDate) {
			return DateTime.fromJSDate(maxDate).toFormat('yyyy-MM-dd');
		}

		return undefined;
	};

	const minDateString = getMinDateString();
	const maxDateString = getMaxDateString();

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

	// Function to get season info for a specific date
	const getSeasonForDate = (date: Date) => {
		if (!seasons) return null;

		const dt = DateTime.fromJSDate(date);
		return seasons.find(season => {
			if (!season.active) return false;
			const seasonStart = DateTime.fromISO(season.from_);
			const seasonEnd = DateTime.fromISO(season.to_);
			return dt >= seasonStart.startOf('day') && dt <= seasonEnd.endOf('day');
		});
	};

	// Function to check if a date is a season transition boundary
	const isSeasonBoundary = (date: Date) => {
		if (!seasons || seasons.length <= 1) return false;

		const dt = DateTime.fromJSDate(date);
		
		// Check if this date is the start or end of any season
		return seasons.some(season => {
			if (!season.active) return false;
			const seasonStart = DateTime.fromISO(season.from_).startOf('day');
			const seasonEnd = DateTime.fromISO(season.to_).endOf('day');
			return dt.equals(seasonStart) || dt.equals(seasonEnd);
		});
	};

	// Custom day content renderer with season boundaries
	const renderDayContents = (day: number, date: Date) => {
		const isBoundary = isSeasonBoundary(date);
		const season = getSeasonForDate(date);
		
		return (
			<div className={`${styles.dayContent} ${isBoundary ? styles.seasonBoundary : ''}`}>
				<span className={styles.dayNumber}>{day}</span>
				{isBoundary && (
					<div className={styles.boundaryIndicator} title={season ? `Season: ${season.name}` : 'Season boundary'}>
						●
					</div>
				)}
			</div>
		);
	};

	// Use native date inputs on mobile
	if (isMobile) {
		const dateValue = currentDate ? DateTime.fromJSDate(alignTimeToInterval(currentDate)).toFormat('yyyy-MM-dd') : '';
		const dateTimeValue = currentDate ? DateTime.fromJSDate(alignTimeToInterval(currentDate)).toFormat('yyyy-MM-dd\'T\'HH:mm') : '';

		return (
			<div>
				<Field className={styles.datePicker}>
					<Field.Affixes>
						<Field.Affix><CalendarIcon title="a11y-title" fontSize="1.5rem"/></Field.Affix>
						{showTimeSelect ? (
							<Input
								type="datetime-local"
								value={dateTimeValue}
								onChange={handleNativeDateChange}
								min={minDateString ? minDateString + "T00:00" : undefined}
								max={maxDateString ? maxDateString + "T23:59" : undefined}
								step={timeIntervals * 60}
								placeholder={placeholder}
							/>
						) : (
							<Input
								type="date"
								value={dateValue}
								onChange={handleNativeDateChange}
								min={minDateString}
								max={maxDateString}
								placeholder={placeholder}
							/>
						)}
					</Field.Affixes>
				</Field>
				{showDebug && currentDate && (
					<div style={{
						fontSize: '10px',
						color: '#666',
						marginTop: '4px',
						fontFamily: 'monospace',
						wordBreak: 'break-all'
					}}>
						<span style={{fontWeight: 'bold'}}>Debug:</span> {currentDate ? alignTimeToInterval(currentDate).toISOString() : 'null'}
					</div>
				)}
			</div>
		);
	}

	return (
		<div className={styles.datePicker} ref={inputContainerRef}>
			<DatePicker
				ref={datePickerRef}
				selected={currentDate}
				onChange={(date) => onDateChange(date as any)}
				showMonthYearPicker={view === 'dayGridMonth'}
				showWeekNumbers={true}
				showWeekPicker={view === 'timeGridWeek' || view === 'listWeek'}
				renderCustomHeader={(props) => <CustomHeader {...props} />}
				renderDayContents={seasons ? renderDayContents : undefined}
				calendarClassName="cdp-calendar"
				wrapperClassName={styles.wrapper}
				onCalendarOpen={() => setIsCalendarOpen(true)}
				onCalendarClose={() => setIsCalendarOpen(false)}
				monthsShown={1}
				shouldCloseOnSelect={false}
				dateFormat={dateFormat}
				showTimeInput={showTimeSelect}
				locale={currentLang}
				minDate={effectiveMinDate}
				maxDate={maxDate}
				isClearable={false}
				placeholderText={placeholder}
				customTimeInput={
					currentDate ? (
						<TimePicker
							onChangeDate={(e) => onDateChange(e)}
							maxTime={maxTime}
							minTime={minTime}
							date={currentDate}
							intervals={timeIntervals}
						/>
					) : undefined
				}
				customInput={(
					<Field>
						<Field.Affixes>
							<Field.Affix><CalendarIcon title="a11y-title" fontSize="1.5rem"/></Field.Affix>
							<Input
								className={"dateView"}
								onChange={() => {}}
								value={formatSelectedDate(showYear)}
								placeholder={placeholder}
							/>
						</Field.Affixes>
					</Field>
				)}
			/>
			{showDebug && (
				<div style={{
					fontSize: '10px',
					color: '#666',
					marginTop: '4px',
					fontFamily: 'monospace',
					wordBreak: 'break-all'
				}}>
					<span style={{fontWeight: 'bold'}}>Debug:</span> {currentDate ? alignTimeToInterval(currentDate).toISOString() : 'null'}
				</div>
			)}
		</div>
	);
};

export default CalendarDatePicker;