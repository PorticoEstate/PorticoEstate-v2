import React, {FC} from 'react';
import styles from './event-content.module.scss';
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faClock, faUser} from "@fortawesome/free-solid-svg-icons";
import {formatTimeStamp} from "@/service/util";
import {FCallEvent, FCEventContentArg} from "@/components/building-calendar/building-calendar.types";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {IEventIsAPIEvent, IEvent} from "@/service/pecalendar.types";
import ResourceIcon from "@/icons/ResourceIcon";

interface EventContentListProps {
    eventInfo: FCEventContentArg<FCallEvent> | IEvent;
}

const EventContentList: FC<EventContentListProps> = ({eventInfo}) => {
    const t = useTrans();
    const isMobile = useIsMobile();

    // Type guard to check if eventInfo is FCEventContentArg
    const isFullCalendarEvent = (eventInfo: FCEventContentArg<FCallEvent> | IEvent): eventInfo is FCEventContentArg<FCallEvent> => {
        return 'event' in eventInfo;
    };

    let eventData: IEvent;
    let actualStart: Date;
    let actualEnd: Date;
    let title: string;

    if (isFullCalendarEvent(eventInfo)) {
        eventData = eventInfo.event.extendedProps.source;
        actualStart = 'actualStart' in eventInfo.event.extendedProps ? eventInfo.event.extendedProps.actualStart : eventInfo.event.start;
        actualEnd = 'actualEnd' in eventInfo.event.extendedProps ? eventInfo.event.extendedProps.actualEnd : eventInfo.event.end;
        title = eventInfo.event.title;
    } else {
        eventData = eventInfo;
        actualStart = new Date(eventData.from_);
        actualEnd = new Date(eventData.to_);
        title = 'name' in eventData ? eventData.name : eventData.building_name;
    }

    const renderColorCircles = (maxCircles: number) => {
        const resources = eventData.resources;
        const totalResources = resources.length;
        const circlesToShow = resources.slice(0, maxCircles);
        const remainingCount = totalResources - maxCircles;

        return (
            <div className={styles.colorCircles}>
                {circlesToShow.map((res: any, index: number) => (
                    <ColourCircle resourceId={res.id} key={index} className={styles.colorCircle} size="small"/>
                ))}
                {remainingCount > 0 && <span className={styles.remainingCount}>+{remainingCount}</span>}
            </div>
        );
    };


    return (
        <div className={`${styles.event} ${styles.listEvent}`}>
              <span className={`${styles.time} text-overline`}>
                <FontAwesomeIcon className="text-label" icon={faClock}/>{formatTimeStamp(actualStart, true)}
              </span>
            <div className={styles.title}>{title}</div>
            <div className={`${styles.resourceIcons} text-label`}>
                <ResourceIcon fontSize="1.25rem" />
                {renderColorCircles(isMobile ? 1 : 3)}
            </div>
            {((IEventIsAPIEvent(eventData) && eventData.organizer) && !isMobile) && (
                <div className={`text-small ${styles.organizer}`}>
                    <FontAwesomeIcon className="text-small"
                                     icon={faUser}/> {eventData?.is_public  ? eventData?.organizer || t('bookingfrontend.not_available') : t('bookingfrontend.private')}
                </div>)}
            <span className={`${styles.to_time} text-overline`}>
                <FontAwesomeIcon className="text-label" icon={faClock}/>{formatTimeStamp(actualEnd, true)}
              </span>
        </div>
    );
};

export default EventContentList;