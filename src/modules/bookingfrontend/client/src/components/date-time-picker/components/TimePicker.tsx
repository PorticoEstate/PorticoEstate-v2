import React, {FC, useEffect, useRef} from 'react';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import type {TimePickerProps} from '../types/interfaces';

const TimePicker: FC<TimePickerProps> = ({
	date,
	onChangeDate,
	intervals = 30,
	minTime,
	maxTime
}) => {
	const t = useTrans();
	
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

		// If 24:00 (midnight) is selected, force minutes to 00
		if (hour === 24) {
			newDate.setMinutes(0);
			newDate.setSeconds(0);
		}

		onChangeDate(newDate);
	};

	const handleMinuteClick = (minute: number) => {
		const newDate = new Date(date);
		const currentHour = date.getHours();

		// If the current hour is 24 (midnight), force minutes to 00 regardless of selection
		if (currentHour === 24) {
			newDate.setMinutes(0);
			newDate.setSeconds(0);
		} else {
			newDate.setMinutes(minute);
		}

		onChangeDate(newDate);
	};

	return (
		<div className="cdp-timeInput">
			<div className="cdp-timeColumns">
				<div className="cdp-timeColumn">
					<div className="cdp-timeColumnHeader">{t('bookingfrontend.hour')}</div>
					<div className="cdp-timeColumnList" ref={hoursListRef}>
						{hours.map(hour => (
							<div
								key={hour}
								data-hour={hour}
								className={`cdp-timeColumnListItem ${
									hour === selectedHour ? 'cdp-timeColumnListItemSelected' : ''
								}`}
								onClick={() => handleHourClick(hour)}
							>
								{hour.toString().padStart(2, '0')}
							</div>
						))}
					</div>
				</div>
				<div className="cdp-timeColumn">
					<div className="cdp-timeColumnHeader">{t('bookingfrontend.minute')}</div>
					<div className="cdp-timeColumnList" ref={minutesListRef}>
						{minutes.map(minute => {
							// When hour is 24, only show 00 minutes and disable others
							const isDisabled = selectedHour === 24 && minute !== 0;
							return (
								<div
									key={minute}
									data-minute={minute}
									className={`cdp-timeColumnListItem ${
										minute === selectedMinute ? 'cdp-timeColumnListItemSelected' : ''
									} ${isDisabled ? 'disabled' : ''}`}
									onClick={() => isDisabled ? null : handleMinuteClick(minute)}
								>
									{minute.toString().padStart(2, '0')}
								</div>
							);
						})}
					</div>
				</div>
			</div>
		</div>
	);
};

export default TimePicker;