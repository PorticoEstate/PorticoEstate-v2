import React, {FC} from 'react';
import styles from '../event-popper.module.scss';
import {formatEventTime} from "@/service/util";
import {FCallTempEvent} from "@/components/building-calendar/building-calendar.types";
import {faClock} from "@fortawesome/free-regular-svg-icons";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {Button, Checkbox} from "@digdir/designsystemet-react";
import {useCurrentBuilding, useTempEvents} from "@/components/building-calendar/calendar-context";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import PopperContentSharedWrapper
    from "@/components/building-calendar/modules/event/popper/content/popper-content-shared-wrapper";
import {useBuildingResources} from "@/service/api/building";

interface TempEventPopperProps {
    event: FCallTempEvent;
    onClose: () => void;
}

const TempEventPopperContent: FC<TempEventPopperProps> = (props) => {
    const {tempEvents, setTempEvents} = useTempEvents();
    const currentBuilding = useCurrentBuilding();
    const {data: buildingResources} = useBuildingResources(currentBuilding);
    const t = useTrans();

    const event = tempEvents[props.event.id];

    if (!event || !currentBuilding || !buildingResources) {
        return;
    }
    const removeTempEvent = () => {
        setTempEvents(tempEvents => {
            const temp = {...tempEvents};

            delete temp[props.event.id]
            return temp;
        })
    }
    const onResourceToggle = (resourceId: number) => {
        const enabled = event.extendedProps.resources.some(res => res == resourceId);

        if (enabled) {
            setTempEvents(tempEvents => {
                const temp = {...tempEvents};

                const thisEvent = {...temp[event.id], extendedProps: {...temp[event.id].extendedProps}};
                thisEvent.extendedProps.resources = thisEvent.extendedProps.resources.filter(res => res !== resourceId);
                temp[event.id] = thisEvent;
                return temp;
            })
        } else {
            setTempEvents(tempEvents => {
                const temp = {...tempEvents};

                const thisEvent = {...temp[event.id], extendedProps: {...temp[event.id].extendedProps}};
                thisEvent.extendedProps.resources = [...thisEvent.extendedProps.resources, buildingResources!.find(res => res.id == resourceId)!.id];
                temp[event.id] = thisEvent;
                return temp;
            })
        }
    }

    return (
        <PopperContentSharedWrapper onClose={props.onClose}>
            <div className={styles.eventPopperContent}>
                        <span className={`${styles.time} text-overline`}>
                            <FontAwesomeIcon className={'text-label'} icon={faClock}/>
                            {formatEventTime(event)}
                        </span>
                <h3 className={styles.eventName}>{event.title}</h3>
                <div className={styles.resourcesList}>
                    {buildingResources.map((resource) => (
                        <div key={`ResourceToggle-${resource.id}`} className={styles.resourceItem}>
                            <Checkbox
                                value={`${resource.id}`}
                                id={`resource-${resource.id}`}
                                checked={event.extendedProps.resources.some(res => res == resource.id)}
                                onChange={() => onResourceToggle(resource.id)}
                                disabled={Object.values(tempEvents).length > 1}
                            />
                            <ColourCircle resourceId={resource.id} size={'medium'}/>
                            <span className={styles.resourceName}>{resource.name}</span>
                        </div>
                    ))}
                </div>
            </div>
            <div className={styles.eventPopperFooter}>
                <Button onClick={props.onClose} variant="tertiary" className={'default'}
                        size={'sm'}>{t('booking.close')}</Button>
                <Button onClick={removeTempEvent} variant="tertiary" className={'default'} color={"danger"}
                        size={'sm'}>{t('common.delete')}</Button>
            </div>
        </PopperContentSharedWrapper>);
};

export default TempEventPopperContent;
