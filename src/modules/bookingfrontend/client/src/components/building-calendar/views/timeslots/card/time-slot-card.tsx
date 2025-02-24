import React, { FC } from 'react';
import { DateTime } from 'luxon';
import styles from './time-slot-card.module.scss';
import {IFreeTimeSlot} from "@/service/pecalendar.types";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {Button} from "@digdir/designsystemet-react";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {ColourIndex} from "@/service/hooks/Colours";

interface TimeSlotCardProps {
	slot: IFreeTimeSlot;
	resourceId?: string;
}

const TimeSlotCard: FC<TimeSlotCardProps> = ({ slot, resourceId }) => {
	const startDateTime = DateTime.fromISO(slot.start_iso);
	const endDateTime = DateTime.fromISO(slot.end_iso);
	const t = useTrans();
	const sameDay = startDateTime.hasSame(endDateTime, 'day');
	const getStatusText = (overlap: IFreeTimeSlot['overlap']) => {
		switch (overlap) {
			case false:
				return t('bookingfrontend.available');
			case 2:
				return t('bookingfrontend.reserved');
			default:
				return t('bookingfrontend.leased');
		}
	};
	const formatDate = (date: DateTime) => {
		return date.toFormat("d'.' LLL").toLowerCase();
	};

	return (
		<div className={styles.card}>
			<div className={`${styles.statusColumn} ${styles[`status-${slot.overlap}`]}`}>
				<div className={`${styles.status} ${styles[`status-${slot.overlap}`]}`}>
					<ColourCircle
						resourceId={resourceId ? +resourceId : ColourIndex.Hvit}
						size={'medium'}
					/>{getStatusText(slot.overlap)}
				</div>
			</div>

			<div className={styles.timeColumn}>
				{sameDay ? (
					<>
						<div className={styles.date}>{formatDate(startDateTime)}</div>
						<div className={styles.time}>
							{startDateTime.toFormat('HH:mm')} - {endDateTime.toFormat('HH:mm')}
						</div>
					</>
				) : (
					<>
						<div className={styles.date}>
							{formatDate(startDateTime)} <span className={styles.time}>{startDateTime.toFormat('HH:mm')}</span>
						</div>
						<div className={styles.date}>
							{formatDate(endDateTime)} <span className={styles.time}>{endDateTime.toFormat('HH:mm')}</span>
						</div>
					</>
					)}
			</div>

			<div className={styles.actionColumn}>
				{slot.overlap === false && slot.applicationLink && (
					<Button className={styles.actionButton} variant={'primary'} data-color={'primary'} data-size={'md'}>
						{t('booking.select')}
					</Button>
				)}
			</div>
		</div>
	);
};

export default TimeSlotCard;