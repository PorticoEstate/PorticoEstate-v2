'use client'
import React, {FC, useMemo} from 'react';
import {FCallEvent} from "@/components/building-calendar/building-calendar.types";
import styles from "@/components/building-calendar/modules/event/popper/event-popper.module.scss";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faClock} from "@fortawesome/free-regular-svg-icons";
import {formatEventTime, phpGWLink} from "@/service/util";
import {faUser, faUsers} from "@fortawesome/free-solid-svg-icons";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {Button} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import PopperContentSharedWrapper
    from "@/components/building-calendar/modules/event/popper/content/popper-content-shared-wrapper";
import Link from "next/link";
import {useBookingUser} from "@/service/hooks/api-hooks";
import {IEventIsAPIEvent} from "@/service/pecalendar.types";

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
		if(!user) {
			return false;
		}

		console.log("event is", eventData.type, event, user)
		switch (eventData.type) {
			case 'event':
				const ssn = user.ssn;
				const orgs = user.delegates;
				if(eventData.customer_identifier_type === 'ssn') {
					return eventData.customer_ssn === ssn;
				}
				if(eventData.customer_identifier_type === 'organization_number') {
					return !!orgs?.some(org => org.organization_number === eventData.customer_organization_number);
				}
				break;
			case "allocation":
				break;
			case "booking":
				break;
			default:
				return false;
		}
		return false;
        // return eventData.
    }, [user, eventData]);

	console.log("userHasAccess", userHasAccess, event.title);
    return (
        <PopperContentSharedWrapper onClose={props.onClose}>

            <div className={styles.eventPopperContent}>
                        <span className={`${styles.time} text-overline`}>
                            <FontAwesomeIcon className={'text-label'}
                                             icon={faClock}/>
                            {formatEventTime(event)}
                        </span>
                <h3 className={styles.eventName}>{event.title}</h3>
                <p className={`text-small ${styles.orderNumber}`}># {event.id}</p>
                {IEventIsAPIEvent(eventData) && eventData?.organizer && <p className={`text-small ${styles.organizer}`}>
                    <FontAwesomeIcon className={'text-small'}
                                     icon={faUser}/> {eventData?.organizer}
                </p>}
                <div className={styles.resourcesList}>
                    {event.extendedProps?.source.resources?.map((resource, index: number) => (
                        <div key={index} className={styles.resourceItem}>
                            <ColourCircle resourceId={resource.id} size={'medium'}/>
                            <span className={styles.resourceName}>{resource.name}</span>
                        </div>
                    ))}
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
                {IEventIsAPIEvent(eventData) && userHasAccess && (
                    <Link href={'/event/' + eventData.id} target="_blank"
                          className={styles.actionButton}>
                        {t(`bookingfrontend.edit ${event.extendedProps.type}`)}
                    </Link>
                )}
            </div>
            <div className={styles.eventPopperFooter}>
                <Button onClick={onClose} variant="tertiary" className={'default'} data-size={'sm'}
                        style={{textTransform: 'capitalize'}}>{t('common.ok').toLowerCase()}</Button>
            </div>
        </PopperContentSharedWrapper>
    );
}

export default EventPopperContent


