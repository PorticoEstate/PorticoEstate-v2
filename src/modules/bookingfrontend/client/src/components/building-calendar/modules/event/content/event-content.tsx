import React, {FC, useEffect, useMemo, useRef, useState} from 'react';
import styles from './event-content.module.scss';
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faClock} from "@fortawesome/free-regular-svg-icons";
import {faLayerGroup, faUser, faUsers} from "@fortawesome/free-solid-svg-icons";
import {formatEventTime} from "@/service/util";
import {FCallEvent, FCEventContentArg} from "@/components/building-calendar/building-calendar.types";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {usePopperGlobalInfo} from "@/service/api/event-info";
import popperStyles from '../popper/event-popper.module.scss'
import {useTrans} from "@/app/i18n/ClientTranslationProvider";

interface EventContentProps {
    eventInfo: FCEventContentArg<FCallEvent>;
}

// Constants for layout calculations
const SHORT_EVENT_HEIGHT = 46;
const MEDIUM_EVENT_HEIGHT = 91;
const LONG_EVENT_HEIGHT = 112;
const HEADER_HEIGHT = 116;
const TITLE_THRESHOLD = 60;

// Component heights
const TIME_HEIGHT = 20;
const TITLE_HEIGHT = 36;
const ORDER_NUMBER_HEIGHT = 28;
const ORGANIZER_HEIGHT = 28;
const PARTICIPANT_LIMIT_HEIGHT = 40;
const RESOURCE_ICONS_HEIGHT = 24;
const RESOURCE_ITEM_HEIGHT = 28;
const RESOURCE_ITEM_GAP = 4;


const PX_TO_MINUTES_RATIO = 1.03448275862;

const colorCircleWidth = 16;
const colorCircleGap = 4;
const minSpaceForTime = 80;

interface LayoutState {
    visibleResources: number;
    visibleCircles: number;
    showTitle: boolean;
    showResourceList: boolean;
    showOrderNumber: boolean;
    showOrganizer: boolean;
    showParticipantLimit: boolean;
    virtualDurationMinutes: number;
    remainingHeight: Record<string, number>;
}

const EventContent: FC<EventContentProps> = (props) => {
    const {eventInfo} = props;
    const t = useTrans();
    const eventRef = useRef<HTMLDivElement>(null);
    const {
        data: infoData,
        isLoading
    } = usePopperGlobalInfo(props.eventInfo.event.extendedProps.type, props.eventInfo.event.id);

    const [layout, setLayout] = useState<LayoutState>({
        visibleResources: 0,
        visibleCircles: 0,
        showTitle: false,
        showResourceList: false,
        showOrderNumber: false,
        showOrganizer: false,
        showParticipantLimit: false,
        remainingHeight: {},
        virtualDurationMinutes: 0
    });


    const eventSegmentInfo = useMemo(() => {
        const isTimeGridWeek = eventInfo.view.type === 'timeGridWeek';
        const isMultiDayEvent = eventInfo.event.end.getDate() !== eventInfo.event.start.getDate();

        if (!isTimeGridWeek || !isMultiDayEvent) {
            return { isStart: true, isEnd: true };
        }

        const segmentStart = eventInfo.timeText.split(' - ')[0];
        const segmentEnd = eventInfo.timeText.split(' - ')[1];
        const eventStart = formatEventTime(eventInfo.event).split(' - ')[0];
        const eventEnd = formatEventTime(eventInfo.event).split(' - ')[1];

        return {
            isStart: segmentStart === eventStart,
            isEnd: segmentEnd === eventEnd
        };
    }, [eventInfo]);


    const eventLen = useMemo(() => {
        if (layout.virtualDurationMinutes <= SHORT_EVENT_HEIGHT) {
            return styles.shortEvent;
        }
        if (layout.virtualDurationMinutes <= MEDIUM_EVENT_HEIGHT) {
            return styles.mediumEvent;
        }
        return styles.longEvent;
    }, [layout.virtualDurationMinutes]);

    useEffect(() => {
        if (!['booking', 'allocation', 'event'].includes(eventInfo.event.extendedProps.type)) {
            return;
        }

        const calculateLayout = () => {
            if (!eventRef.current) return;

            const containerElement = eventRef.current.closest(".fc-timegrid-event-harness") as HTMLElement;
            if (!containerElement) return;

            const eventHeight = containerElement.offsetHeight;
            const eventWidth = containerElement.offsetWidth;
            const virtualDurationMinutes = Math.round(eventHeight * PX_TO_MINUTES_RATIO);

            // Calculate how many circles can fit based on width
            const availableWidth = eventWidth - minSpaceForTime;
            let maxVisibleCircles = Math.floor(availableWidth / (colorCircleWidth + colorCircleGap));

            // Start with required height for time
            let usedHeight = TIME_HEIGHT;

            // Determine what can fit in the remaining space
            let showTitle = virtualDurationMinutes > TITLE_THRESHOLD;
            if (showTitle) {
                usedHeight += TITLE_HEIGHT;
            }

            // Calculate remaining height after title
            let remainingHeight = virtualDurationMinutes - usedHeight;

            const rm: Record<string, number> = {
                title: remainingHeight
            }

            // Check if we can show order number
            const showOrderNumber = showTitle && remainingHeight >= ORDER_NUMBER_HEIGHT;
            if (showOrderNumber) {
                remainingHeight -= ORDER_NUMBER_HEIGHT;
                rm.order = remainingHeight;

            }

            // Check if we can show organizer
            const showOrganizer = showOrderNumber && remainingHeight >= ORGANIZER_HEIGHT;
            if (showOrganizer) {
                remainingHeight -= ORGANIZER_HEIGHT;
                rm.organizer = remainingHeight;

            }

            // Check if we can show participant limit
            const showParticipantLimit = showOrganizer && remainingHeight >= PARTICIPANT_LIMIT_HEIGHT;
            if (showParticipantLimit) {
                remainingHeight -= PARTICIPANT_LIMIT_HEIGHT;
                rm.participant = remainingHeight;

            }

            // Adjust circle count based on virtual duration
            if (virtualDurationMinutes <= SHORT_EVENT_HEIGHT) {
                maxVisibleCircles = Math.min(maxVisibleCircles, 3);
            } else if (virtualDurationMinutes <= MEDIUM_EVENT_HEIGHT) {
                maxVisibleCircles = Math.min(maxVisibleCircles, 6);
            }

            // Calculate resources list
            let maxVisibleResources = 0;
            let showResourceList = false;

            if (remainingHeight >= MEDIUM_EVENT_HEIGHT) {
                maxVisibleResources = Math.floor((remainingHeight + RESOURCE_ITEM_GAP) / (RESOURCE_ITEM_HEIGHT + RESOURCE_ITEM_GAP));
                showResourceList = maxVisibleResources > 0;
                rm.resources = remainingHeight;

            }


            setLayout({
                visibleResources: Math.max(0, maxVisibleResources),
                visibleCircles: Math.max(0, maxVisibleCircles),
                showTitle,
                showResourceList,
                showOrderNumber,
                showOrganizer,
                showParticipantLimit,
                virtualDurationMinutes,
                remainingHeight: rm
            });
        };

        const containerElement = eventRef.current?.closest(".fc-timegrid-event-harness");
        if (containerElement) {
            const resizeObserver = new ResizeObserver(calculateLayout);
            resizeObserver.observe(containerElement);
            calculateLayout(); // Initial calculation

            return () => resizeObserver.disconnect();
        }
    }, [eventInfo]);

    const renderColorCircles = (size: 'medium' | 'small') => {
        const resources = eventInfo.event.extendedProps.source.resources;
        const totalResources = resources.length;
        const circlesToShow = resources.slice(0, layout.visibleCircles);
        const remainingCount = totalResources - layout.visibleCircles;

        return (
            <div className={styles.colorCircles}>
                {circlesToShow.map((res, index) => (
                    <ColourCircle
                        resourceId={res.id}
                        key={index}
                        className={styles.colorCircle}
                        size={size}
                    />
                ))}
                {remainingCount > 0 && (
                    <span className={styles.remainingCount}>+{remainingCount}</span>
                )}
            </div>
        );
    };

    const renderResourceItems = () => {
        const resources = eventInfo.event.extendedProps.source.resources;
        const totalResources = resources.length;
        const resourcesToShow = resources.slice(0, layout.visibleResources);
        const remainingCount = totalResources - layout.visibleResources;

        return (
            <>
                {resourcesToShow.map((resource, index) => (
                    <div key={index} className={`${popperStyles.resourceItem} ${popperStyles.gray}`}>
                        <ColourCircle resourceId={resource.id} size={'medium'}/>
                        <span className={popperStyles.resourceName}>{resource.name}</span>
                    </div>
                ))}
                {remainingCount > 0 && (
                    <div className={`${popperStyles.resourceItem} ${popperStyles.gray}`}>
                        <span className={popperStyles.resourceName}>
                            +{remainingCount} {t('bookingfrontend.more')}
                        </span>
                    </div>
                )}
            </>
        );
    };

    const actualTimeText = formatEventTime(eventInfo.event);

    const content = (
        <div className={`${styles.event} ${eventLen}`} onClick={() => console.log(layout, eventInfo.event.start, eventInfo.event.end)}>
            <span className={`${styles.time} text-overline`}>
                <FontAwesomeIcon className={'text-label'} icon={faClock}/>{actualTimeText}
            </span>

            {layout.showTitle && (
                <>
                    <div className={styles.title}>{eventInfo.event.title}</div>
                    {layout.showOrderNumber && (
                        <div className={`text-small ${styles.orderNumber}`}>#{eventInfo.event.id}</div>
                    )}
                    {layout.showOrganizer && infoData?.organizer && (
                        <div className={`text-small ${styles.organizer}`}>
                            <FontAwesomeIcon className={'text-small'} icon={faUser}/> {infoData?.organizer}
                        </div>
                    )}
                    {layout.showParticipantLimit && (infoData?.info_participant_limit || 0) > 0 && (
                        <div className={`text-small ${styles.participantLimit}`}>
                            <FontAwesomeIcon className={'text-small'} icon={faUsers}/>
                            <span>Max {infoData?.info_participant_limit} participants</span>
                        </div>
                    )}
                </>
            )}

            {!layout.showResourceList ? (
                <div className={`${styles.resourceIcons} text-label`}>
                    <FontAwesomeIcon icon={faLayerGroup}/>
                    {renderColorCircles('medium')}
                </div>
            ) : (
                <div className={popperStyles.resourcesList}>
                    {renderResourceItems()}
                </div>
            )}
        </div>
    );

    return <div ref={eventRef} style={{maxWidth: '100%'}}>{content}</div>;
};

export default EventContent;