import React, {FC, memo, useMemo, useState} from 'react';
import styles from './calender-resource-filter.module.scss';
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {Checkbox, Button} from "@digdir/designsystemet-react";
import {useEnabledResources, useTempEvents} from "@/components/building-calendar/calendar-context";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import MobileDialog from "@/components/dialog/mobile-dialog";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {InformationSquareIcon} from "@navikt/aksel-icons";
import ResourceInfoModal
    from "@/components/building-calendar/modules/resource-filter/resource-info-popper/resource-info-popper";
import {useBuildingResources} from "@/service/api/building";

export interface CalendarResourceFilterOption {
    value: string;
    label: string;
}

interface CalendarResourceFilterProps {
    open: boolean;
    transparent: boolean;
    setOpen: (open: boolean) => void;
    buildingId: string | number;
}

const CalendarResourceFilter: FC<CalendarResourceFilterProps> = ({
                                                                     open,
                                                                     transparent,
                                                                     setOpen,
                                                                     buildingId
                                                                 }) => {
    const isMobile = useIsMobile();
    const t = useTrans();
    const {tempEvents} = useTempEvents();
    const [popperResource, setPopperResource] = useState<CalendarResourceFilterOption | null>(null);
    const {setEnabledResources, enabledResources} = useEnabledResources();
    const {data: resources} = useBuildingResources(buildingId)

    const resourceOptions = useMemo<CalendarResourceFilterOption[]>(() => {
        return (resources || []).map((resource, index) => ({
            value: resource.id.toString(),
            label: resource.name
        }));
    }, [resources]);

    const onToggle = (resourceId: string) => {
        setEnabledResources(prevEnabled => {
            const newEnabled = new Set(prevEnabled);
            if (newEnabled.has(resourceId)) {
                newEnabled.delete(resourceId);
            } else {
                newEnabled.add(resourceId);
            }
            return newEnabled;
        });
    };

    const onToggleAll = () => {
        if (enabledResources.size === resourceOptions.length) {
            setEnabledResources(new Set());
        } else {
            setEnabledResources(new Set(resourceOptions.map(option => option.value)));
        }
    };
    const content = (
        <div
            className={`${styles.resourceToggleContainer} ${!open ? styles.hidden : ''}  ${transparent ? styles.transparent : ''}`}
        >
            <div className={styles.toggleAllContainer}>
                <Checkbox
                    data-size={'sm'}
                    value={'choose_all'}
                    id={`resource-all`}
                    checked={enabledResources.size === resourceOptions.length}
                    onChange={onToggleAll}
                    label={<div className={styles.resourceLabel}>
                        {t('common.select all')} {t('bookingfrontend.resources').toLowerCase()}

                    </div>}
                    className={styles.resourceCheckbox}
                    // disabled={Object.values(tempEvents).length > 0}
                />

            </div>
            {resourceOptions.map(resource => (
                <div key={resource.value}
                     className={`${styles.resourceItem} ${enabledResources.has(resource.value) ? styles.active : ''}`}>
                    <Checkbox
                        value={`${resource.value}`}
                        id={`resource-${resource.value}`}
                        checked={enabledResources.has(resource.value)}
                        onChange={() => onToggle(resource.value)}
                        label={<div
                            className={`${styles.resourceLabel} text-normal`}
                        >
                            <div>
                                <ColourCircle resourceId={+resource.value} size={'medium'}/>

                                <span>{resource.label}</span>
                            </div>
                            {!isMobile && (
                                <Button variant={'tertiary'} data-size={'sm'} onClick={(a) => {
                                    setPopperResource(resource);
                                }}><InformationSquareIcon
                                    fontSize={'1.5rem'}/></Button>)}
                        </div>}
                        className={styles.resourceCheckbox}
                        // disabled={Object.values(tempEvents).length > 0}
                    />

                </div>
            ))}
            {!isMobile && (
                <ResourceInfoModal resource_id={popperResource?.value || null}
                                   resource_name={popperResource?.label || null} onClose={() => {
                    setPopperResource(null);
                }}/>
            )}
        </div>
    );

    if (isMobile) {
        return (
            <div className={styles.resourceToggleContainer}>
                <MobileDialog open={open} onClose={() => setOpen(false)}>{content}</MobileDialog>
            </div>
        )
    }

    return content
};

export default memo(CalendarResourceFilter);
