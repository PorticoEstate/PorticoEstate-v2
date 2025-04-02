import React, { FC } from 'react';
import { DateTime } from 'luxon';
import styles from './time-slot-card.module.scss';
import {IFreeTimeSlot} from "@/service/pecalendar.types";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {Button, Spinner} from "@digdir/designsystemet-react";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {ColourIndex} from "@/service/hooks/Colours";
import { usePartialApplications } from '@/service/hooks/api-hooks';

interface TimeSlotCardProps {
	slot: IFreeTimeSlot;
	resourceId?: string;
	onSelect: (slot: IFreeTimeSlot) => void;
	isProcessing?: boolean;
}

const TimeSlotCard: FC<TimeSlotCardProps> = ({ slot, resourceId, onSelect, isProcessing = false }) => {
	const startDateTime = DateTime.fromISO(slot.start_iso);
	const endDateTime = DateTime.fromISO(slot.end_iso);
	const t = useTrans();
	const { data: partialApplications } = usePartialApplications();
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

	const hasRemovableOverlap = slot.overlap &&
		slot.overlap_reason === 'complete_overlap' &&
		slot.overlap_type === 'complete' &&
		slot.overlap_event?.type === 'application' &&
		slot.overlap_event?.status === 'NEWPARTIAL1' &&
		slot.overlap_event?.id !== undefined &&
		partialApplications?.list.some(app => app.id === slot.overlap_event?.id);

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
				{isProcessing && (
					<Spinner data-size={'md'} aria-label={t('common.loading')} />
				)}
				{!isProcessing && slot.overlap === false && (
					<Button
						className={styles.actionButton}
						variant={'primary'}
						data-size={'md'}
						onClick={() => onSelect(slot)}
						disabled={isProcessing}
					>
						{t('booking.select')}
					</Button>
				)}
				{!isProcessing && hasRemovableOverlap && (
					<Button
						className={styles.actionButton}
						data-color={'danger'}
						data-size={'md'}
						onClick={() => onSelect(slot)}
						disabled={isProcessing}
					>
						{t('bookingfrontend.delete')}
					</Button>
				)}
			</div>
		</div>
	);
};

export default TimeSlotCard;