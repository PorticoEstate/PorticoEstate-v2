import React, {FC} from 'react';
import styles from './event-content.module.scss';
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faUser} from "@fortawesome/free-solid-svg-icons";
import { LayersIcon } from "@navikt/aksel-icons";
import {formatDateRange} from "@/service/util";
import {FCallEvent, FCEventContentArg} from "@/components/building-calendar/building-calendar.types";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {IEventIsAPIEvent} from "@/service/pecalendar.types";

interface EventContentAllDayProps {
    eventInfo: FCEventContentArg<FCallEvent>;
}

const EventContentAllDay: FC<EventContentAllDayProps> = ({eventInfo}) => {
    const t = useTrans();
    const isMobile = useIsMobile();
    const eventData = eventInfo.event.extendedProps.source;

    // const actualTimeText = formatEventTime(eventInfo.event);
    const actualStart = 'actualStart' in eventInfo.event.extendedProps ? eventInfo.event.extendedProps.actualStart : eventInfo.event.start;
    const actualEnd = 'actualEnd' in eventInfo.event.extendedProps ? eventInfo.event.extendedProps.actualEnd : eventInfo.event.end;
    const renderColorCircles = (maxCircles: number) => {
        const resources = eventData.resources;
        const totalResources = resources.length;
        const circlesToShow = resources.slice(0, maxCircles);
        const remainingCount = totalResources - maxCircles;

        return (
            <div className={styles.colorCircles}>
                {circlesToShow.map((res, index) => (
                    <ColourCircle resourceId={res.id} key={index} className={styles.colorCircle} size="small"/>
                ))}
                {remainingCount > 0 && <span className={styles.remainingCount}>+{remainingCount}</span>}
            </div>
        );
    };


    return (
        <div style={{containerType: 'inline-size', width: '100%'}}>
            <div className={`${styles.event} ${styles.allDayEvent}`}>
              <span className={`${styles.joined_date} text-overline`}>
                {/*<FontAwesomeIcon className="text-label" icon={faClock}/>*/}
                  {formatDateRange(actualStart, actualEnd)}

              </span>

                <span className={`${styles.joined_time} text-overline`}>
                {/*<FontAwesomeIcon className="text-label" icon={faClock}/>*/}
                    {formatDateRange(actualStart, actualEnd, true)}
               </span>
                <span className={styles.titleDivider}>|</span>

                <div className={styles.title}>{eventInfo.event.title}</div>
                <span className={styles.resourceIconsDivider}>|</span>

                <div className={`${styles.resourceIcons} text-label`}>

                    <LayersIcon fontSize="1.25rem" />
                    {renderColorCircles(isMobile ? 1 : 3)}
                </div>
                {IEventIsAPIEvent(eventData) && eventData.organizer && (

                <span className={styles.organizerDivider}>|</span>
                )}

                {IEventIsAPIEvent(eventData) && eventData.organizer && (
                    <div className={`text-small ${styles.organizer}`}>
                        <FontAwesomeIcon className="text-small"
                                         icon={faUser}/> {eventData.organizer}
                    </div>)}


            </div>
        </div>
    );
};

export default EventContentAllDay;