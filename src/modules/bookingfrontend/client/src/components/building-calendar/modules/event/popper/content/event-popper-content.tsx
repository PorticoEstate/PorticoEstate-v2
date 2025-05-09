'use client'
import React, {FC, useMemo} from 'react';
import {FCallEvent} from "@/components/building-calendar/building-calendar.types";
import styles from "@/components/building-calendar/modules/event/popper/event-popper.module.scss";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {formatEventTime, isDevMode, phpGWLink} from "@/service/util";
import {faUser, faUsers} from "@fortawesome/free-solid-svg-icons";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {Button, Label, Paragraph} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import PopperContentSharedWrapper
	from "@/components/building-calendar/modules/event/popper/content/popper-content-shared-wrapper";
import Link from "next/link";
import {useBookingUser} from "@/service/hooks/api-hooks";
import {IEventIsAPIAllocation, IEventIsAPIEvent} from "@/service/pecalendar.types";
import AllocationPopperActions
	from "@/components/building-calendar/modules/event/popper/content/allocation-popper-actions";
import {boolean} from "zod";
import {isOrgAdmin} from "@/components/building-calendar/util/event-converter";
import {PlusIcon} from "@navikt/aksel-icons";

interface EventPopperContentProps {
	event: FCallEvent
	onClose: () => void;
}

const EventPopperContent: FC<EventPopperContentProps> = (props) => {
	const {event, onClose} = props
	const t = useTrans();
	const eventData = event.extendedProps.source;
	const {data: user} = useBookingUser();
	// if (!popperInfo) {
	//     return null;
	// }
	const userHasAccess = useMemo(() => {
		if (!user) {
			return false;
		}

		if (IEventIsAPIEvent(eventData)) {
			const ssn = user.ssn;
			if (eventData.customer_identifier_type === 'ssn') {
				if (eventData.customer_ssn === ssn) {
					return true;
				}
			}
		}
		return isOrgAdmin(user, eventData)
	}, [user, eventData]);

	// console.log("userHasAccess", userHasAccess, event.title);
	return (
		<PopperContentSharedWrapper onClose={props.onClose} header={true}
									headerContent={
										<h3 className={styles.eventName}>{event.title}</h3>
									}
		>

			<div className={styles.eventPopperContent}>

				<div className={styles.specList}>
					{isDevMode() && (
						<div className={styles.specItem}>
							<Label className={styles.specTitle}>
								#ID DEBUG
							</Label>
							<Paragraph className={styles.specContent}>
								{event.id}
							</Paragraph>
						</div>
					)}
					{IEventIsAPIEvent(eventData) && eventData?.organizer && <div className={styles.specItem}>
						<Label className={styles.specTitle}>
							{t('booking.organizer')}
						</Label>
						<Paragraph className={styles.specContent}>
							{eventData?.organizer}
						</Paragraph>
					</div>}
					<div className={styles.specItem}>
						<Label className={styles.specTitle}>
							{t('booking.date and time')}
						</Label>
						<Paragraph className={styles.specContent}>
							{formatEventTime(event)}
						</Paragraph>
					</div>
					<div className={styles.specItem}>
						<Label className={styles.specTitle}>
							{t('bookingfrontend.type')}
						</Label>
						<Paragraph className={styles.specContent}>
							{t('bookingfrontend.' + eventData.type)}
						</Paragraph>
					</div>
					<div className={styles.specItem}>
						<Label className={styles.specTitle}>
							{t('booking.resources')}
						</Label>
						<Paragraph className={styles.specContent}>
							<div className={styles.resourcesList}>
								{event.extendedProps?.source.resources?.map((resource, index: number) => (
									<div key={index} className={styles.resourceItem}>
										<ColourCircle resourceId={resource.id} size={'medium'}/>
										<span className={styles.resourceName}>{resource.name}</span>
									</div>
								))}
							</div>
						</Paragraph>
					</div>
				</div>

				{IEventIsAPIEvent(eventData) && (eventData.participant_limit || 0) > 0 && (
					<p className={styles.participantLimit}>
						<FontAwesomeIcon className={'text-small'}
										 icon={faUsers}/>{t('bookingfrontend.max_participants', {count: eventData.participant_limit || 0})}
					</p>
				)}
			</div>
			<div className={styles.eventPopperActions}>
				{/*{event.extendedProps?.show_link && (popperInfo?.info_participant_limit || 0) > 0 && (*/}
				{/*    <a href={event.extendedProps?.show_link} target="_blank" rel="noopener noreferrer"*/}
				{/*       className={styles.actionButton}>*/}
				{/*        Register Participants*/}
				{/*    </a>*/}
				{/*)}*/}
				{IEventIsAPIAllocation(eventData) && userHasAccess && user && (
					<AllocationPopperActions allocation={eventData} user={user}/>
				)}
				{IEventIsAPIEvent(eventData) && userHasAccess && (
					<Button asChild variant={'tertiary'} data-color={'accent'}>
						<Link href={'/event/' + eventData.id} target="_blank"
							  className={styles.actionButton}>
							{t(`bookingfrontend.edit ${event.extendedProps.type}`)}
						</Link>
					</Button>

				)}
			</div>

		</PopperContentSharedWrapper>
	);
}

export default EventPopperContent


