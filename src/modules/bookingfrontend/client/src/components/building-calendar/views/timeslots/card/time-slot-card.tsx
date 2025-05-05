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
	if(slot.when === '09/05-2025 12:00 - 10/05-2025 15:00') {
		console.log(slot);
	}
	const getStatusText = (slot: IFreeTimeSlot) => {
		const { overlap, overlap_reason, overlap_type } = slot;

		switch (overlap) {
			case false:
				return t('bookingfrontend.available');
			case 2:
				// Enhanced status for reservation types
				// if (overlap_reason) {
				// 	switch (overlap_reason) {
				// 		case 'complete_overlap':
				// 			return t('bookingfrontend.reserved');
				// 		case 'start_overlap':
				// 			return t('bookingfrontend.partial_start_reserved');
				// 		case 'end_overlap':
				// 			return t('bookingfrontend.partial_end_reserved');
				// 		default:
				// 			return t('bookingfrontend.reserved');
				// 	}
				// }
				return t('bookingfrontend.reserved');
			case 3:
				return t('bookingfrontend.past');
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
				<div className={`${styles.status} ${styles[`status-${slot.overlap}`]} ${slot.overlap_type ? styles[`overlap-${slot.overlap_type}`] : ''}`}>
					<ColourCircle
						resourceId={resourceId ? +resourceId : ColourIndex.Hvit}
						size={'medium'}
					/>{getStatusText(slot)}
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