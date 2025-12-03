import React, {FC, useEffect, useMemo, useRef, useState} from 'react';
import styles from './event-content.module.scss';
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faClock} from "@fortawesome/free-regular-svg-icons";
import { LayersIcon } from "@navikt/aksel-icons";
import {formatEventTime} from "@/service/util";
import {FCallTempEvent, FCEventContentArg} from "@/components/building-calendar/building-calendar.types";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import popperStyles from '../popper/event-popper.module.scss'
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useResource} from "@/service/api/building";

interface EventContentTempProps {
    eventInfo: FCEventContentArg<FCallTempEvent>;
}

// Constants for layout calculations
const SHORT_EVENT_HEIGHT = 46;
const MEDIUM_EVENT_HEIGHT = 91;
const LONG_EVENT_HEIGHT = 112;
const HEADER_HEIGHT = 116; // Base header height
const SUBTITLE_EXTRA_HEIGHT = 16; // Additional height for subtitle
const TITLE_THRESHOLD = 60;
const SUBTITLE_THRESHOLD = 122; // Threshold for showing subtitle

const PX_TO_MINUTES_RATIO = 1.03448275862;

const resourceItemHeight = 28;
const resourceItemGap = 4;
const colorCircleWidth = 16;
const colorCircleGap = 4;
const minSpaceForTime = 80;

interface LayoutState {
    visibleResources: number;
    visibleCircles: number;
    showTitle: boolean;
    showSubtitle: boolean;
    showResourceList: boolean;
    virtualDurationMinutes: number;
}

const EventContentTemp: FC<EventContentTempProps> = (props) => {
    const {eventInfo} = props;
    const t = useTrans();
    const eventRef = useRef<HTMLDivElement>(null);
    const [layout, setLayout] = useState<LayoutState>({
        visibleResources: 0,
        visibleCircles: 0,
        showTitle: false,
        showSubtitle: false,
        showResourceList: false,
        virtualDurationMinutes: 0
    });

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
        if (!['temporary'].includes(eventInfo.event.extendedProps.type)) {
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

            // Adjust circle count based on virtual duration
            if (virtualDurationMinutes <= SHORT_EVENT_HEIGHT) {
                maxVisibleCircles = Math.min(maxVisibleCircles, 3);
            } else if (virtualDurationMinutes <= MEDIUM_EVENT_HEIGHT) {
                maxVisibleCircles = Math.min(maxVisibleCircles, 6);
            }

            let maxVisibleResources = 0;
            let showTitle = virtualDurationMinutes > TITLE_THRESHOLD;
            let showSubtitle = virtualDurationMinutes > SUBTITLE_THRESHOLD;
            let showResourceList = false;

            // Calculate header height based on whether subtitle is shown
            const effectiveHeaderHeight = HEADER_HEIGHT + (showSubtitle ? SUBTITLE_EXTRA_HEIGHT : 0);

            if (virtualDurationMinutes > MEDIUM_EVENT_HEIGHT) {
                const availableHeight = eventHeight - effectiveHeaderHeight;
                maxVisibleResources = Math.floor((availableHeight + resourceItemGap) / (resourceItemHeight + resourceItemGap));
                // Only show resource list if we can display at least one resource
                showResourceList = maxVisibleResources > 0;
            }


            setLayout({
                visibleResources: Math.max(0, maxVisibleResources),
                visibleCircles: Math.max(0, maxVisibleCircles),
                showTitle,
                showSubtitle,
                showResourceList,
                virtualDurationMinutes
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

    if (!['temporary'].includes(eventInfo.event.extendedProps.type)) {
        return null;
    }

    const actualTimeText = formatEventTime(eventInfo.event);

    const renderColorCircles = (size: 'medium' | 'small') => {
        const resources = eventInfo.event.extendedProps.resources;
        const totalResources = resources.length;
        const circlesToShow = resources.slice(0, layout.visibleCircles);
        const remainingCount = totalResources - layout.visibleCircles;

        return (
            <div className={styles.colorCircles}>
                {circlesToShow.map((res, index) => (
                    <ColourCircle
                        resourceId={+res}
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
        const resources = eventInfo.event.extendedProps.resources;
        const totalResources = resources.length;
        const resourcesToShow = resources.slice(0, layout.visibleResources);
        const remainingCount = totalResources - layout.visibleResources;

        return (
            <>
                {resourcesToShow.map((resource, index) => (
                    <ResourceTitle resourceId={resource} key={'circle-' + resource}/>
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

    const content = (
        <div className={`${styles.event} ${eventLen}`}>
            <span className={`${styles.time} text-overline`}>
                <FontAwesomeIcon className={'text-label'} icon={faClock}/>{actualTimeText}
            </span>

            {layout.showTitle && (
                <div className={styles.titleContainer}>
                    <div className={styles.title}>
                        {eventInfo.event.extendedProps?.isRecurringInstance 
                            ? t('bookingfrontend.new_repeating')
                            : t('bookingfrontend.new application')
                        }
                    </div>
                    {layout.showSubtitle && (
                        <div className={styles.subtitleText}>{eventInfo.event.title}</div>
                    )}
                </div>
            )}

            {!layout.showResourceList ? (
                <div className={`${styles.resourceIcons} text-label`}>
                    <LayersIcon fontSize="1.25rem" />
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


interface ResourceTitleProps {
    resourceId: string | number;
}

const ResourceTitle: FC<ResourceTitleProps> = (props) => {
    const {data: resource} = useResource(props.resourceId);

    if (!resource) {
        return null;
    }
    return (
        <div className={`${popperStyles.resourceItem} ${popperStyles.gray}`}>
            <ColourCircle resourceId={resource.id} size={'medium'}/>
            <span className={popperStyles.resourceName}>{resource.name}</span>
        </div>
    );
}


export default EventContentTemp;