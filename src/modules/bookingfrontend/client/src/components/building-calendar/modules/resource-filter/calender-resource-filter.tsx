import React, {FC, useState} from 'react';
import styles from './calender-resource-filter.module.scss';
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {Checkbox, Button} from "@digdir/designsystemet-react";
import {useTempEvents} from "@/components/building-calendar/calendar-context";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import MobileDialog from "@/components/dialog/mobile-dialog";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {InformationSquareIcon} from "@navikt/aksel-icons";
import ResourceInfoPopper
    from "@/components/building-calendar/modules/resource-filter/resource-info-popper/resource-info-popper";

export interface CalendarResourceFilterOption {
    value: string;
    label: string;
}

interface CalendarResourceFilterProps {
    open: boolean;
    resourceOptions: CalendarResourceFilterOption[];
    enabledResources: Set<string>;
    onToggle: (resourceId: string) => void;
    transparent: boolean;
    onToggleAll: () => void;
    setOpen: (open: boolean) => void;
}

const CalendarResourceFilter: FC<CalendarResourceFilterProps> = ({
                                                                     resourceOptions,
                                                                     enabledResources,
                                                                     onToggle,
                                                                     onToggleAll,
                                                                     open,
                                                                     transparent,
                                                                     setOpen
                                                                 }) => {
    const isMobile = useIsMobile();
    const t = useTrans();
    const {tempEvents} = useTempEvents();
    const [popperResource, setPopperResource] = useState<CalendarResourceFilterOption | null>(null);
    const [popperAnchorEl, setPopperAnchorEl] = useState<HTMLElement | null>(null);

    const content = (
        <div
            className={`${styles.resourceToggleContainer} ${!open ? styles.hidden : ''}  ${transparent ? styles.transparent : ''}`}
        >
            <div className={styles.toggleAllContainer}>
                {/*<Button*/}
                {/*    onClick={onToggleAll}*/}
                {/*    className={styles.toggleAllButton}*/}
                {/*    variant={'tertiary'}*/}
                {/*    size={'sm'}*/}
                {/*>*/}
                {/*    {enabledResources.size === resourceOptions.length ? t('bookingfrontend.deselect_all') : t('common.select all')}*/}
                {/*</Button>*/}
                <Checkbox
                    value={'choose_all'}
                    id={`resource-all`}
                    checked={enabledResources.size === resourceOptions.length}
                    onChange={onToggleAll}
                    className={styles.resourceCheckbox}
                    disabled={Object.values(tempEvents).length > 0}
                >
                    <label
                        htmlFor={`resource-all`}
                        className={styles.resourceLabel}
                    >
                        {t('common.select all')} {t('bookingfrontend.resources').toLowerCase()}
                    </label>
                </Checkbox>
            </div>
            {resourceOptions.map(resource => (
                <div key={resource.value}
                     className={`${styles.resourceItem} ${enabledResources.has(resource.value) ? styles.active : ''}`}>
                    <Checkbox
                        value={`${resource.value}`}
                        id={`resource-${resource.value}`}
                        checked={enabledResources.has(resource.value)}
                        onChange={() => onToggle(resource.value)}
                        className={styles.resourceCheckbox}
                        disabled={Object.values(tempEvents).length > 0}
                    >
                        <label
                            htmlFor={`resource-${resource.value}`}
                            className={`${styles.resourceLabel} text-normal`}
                        >
                            <div>
                                <ColourCircle resourceId={+resource.value} size={'medium'}/>

                                <span>{resource.label}</span>
                            </div>
                            {!isMobile && (
                                <Button variant={'tertiary'} size={'sm'} data-size="xs" onClick={(a) => {
                                    setPopperResource(null)
                                    setPopperAnchorEl(null)
                                    setPopperResource(resource);
                                    setPopperAnchorEl(a.currentTarget)
                                }}><InformationSquareIcon
                                    fontSize={'1.5rem'}/></Button>)}
                        </label>
                    </Checkbox>

                </div>
            ))}
            {!isMobile && (
                <ResourceInfoPopper resource_id={popperResource?.value || null} resource_name={popperResource?.label || null} onClose={() => {
                    setPopperResource(null);
                    setPopperAnchorEl(null);
                }} anchor={popperAnchorEl} placement={'right-start'} />
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

export default CalendarResourceFilter;
