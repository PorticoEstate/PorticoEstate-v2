import React from 'react';

export interface CalendarDatePickerBaseProps {
	view: string;
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

export interface CalendarDatePickerNonEmptyProps extends CalendarDatePickerBaseProps {
	currentDate: Date;
	onDateChange: (date: Date) => void;
	placeholder?: never;
	allowEmpty?: false;
}

export interface CalendarDatePickerEmptyProps extends CalendarDatePickerBaseProps {
	currentDate: Date | null | undefined;
	onDateChange: (date: Date | null) => void;
	/** Placeholder text to show when date is empty */
	placeholder?: string;
	allowEmpty: true;
}

export type CalendarDatePickerProps = CalendarDatePickerNonEmptyProps | CalendarDatePickerEmptyProps;

export interface CustomHeaderProps {
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

export interface TimePickerProps {
	date: Date;
	onChangeDate: (date: Date) => void;
	intervals?: number;
	minTime?: string;
	maxTime?: string;
}