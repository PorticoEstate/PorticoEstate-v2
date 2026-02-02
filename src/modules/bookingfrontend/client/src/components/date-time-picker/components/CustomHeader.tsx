import React, {FC} from 'react';
import {ChevronLeftIcon, ChevronRightIcon} from "@navikt/aksel-icons";
import {Button, Select} from "@digdir/designsystemet-react";
import {useClientTranslation} from "@/app/i18n/ClientTranslationProvider";
import styles from '../calendar-date-picker.module.scss';
import type {CustomHeaderProps} from '../types/interfaces';

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
	const {i18n} = useClientTranslation();
	const currentLang = i18n.language || 'no';

	return (
		<div className="cdp-header">
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

			<div className="cdp-selects">
				<Select
					className="cdp-select"
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
					className="cdp-select"
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

export default CustomHeader;