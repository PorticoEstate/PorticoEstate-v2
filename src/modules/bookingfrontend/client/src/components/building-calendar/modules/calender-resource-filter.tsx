import React, {FC, useState} from 'react';
import styles from './calender-resource-filter.module.scss';
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {Checkbox, Button} from "@digdir/designsystemet-react";
import {useTempEvents} from "@/components/building-calendar/calendar-context";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faLayerGroup} from "@fortawesome/free-solid-svg-icons";
import MobileDialog from "@/components/dialog/mobile-dialog";
import {Chip} from "@mui/material";

export interface CalendarResourceFilterOption {
    value: string;
    label: string;
    color?: string;
}

interface CalendarResourceFilterProps {
    hidden: boolean;
    resourceOptions: CalendarResourceFilterOption[];
    enabledResources: Set<string>;
    onToggle: (resourceId: string) => void;
    onToggleAll: () => void;
    isMobile: boolean;
}

const CalendarResourceFilter: FC<CalendarResourceFilterProps> = ({
                                                                     resourceOptions,
                                                                     enabledResources,
                                                                     onToggle,
                                                                     onToggleAll,
                                                                     hidden,
                                                                     isMobile
                                                                 }) => {
    const [open, setOpen] = useState<boolean>(false);

    const t = useTrans();
    const {tempEvents} = useTempEvents();


    const content = (
        <div className={`${styles.resourceToggleContainer} ${hidden ? styles.hidden : ''}`}
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
                    disabled={Object.values(tempEvents).length > 1}
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
                        disabled={Object.values(tempEvents).length > 1}
                    >
                        <label
                            htmlFor={`resource-${resource.value}`}
                            className={styles.resourceLabel}
                        >
                            {resource.label}
                            <ColourCircle resourceId={+resource.value} size={'medium'}/>
                        </label>
                    </Checkbox>

                </div>
            ))}
        </div>
    );

    if (isMobile) {
        return (
            <div style={{display: "flex", flexDirection: 'column'}}>
                <Button variant={'secondary'} size={'sm'}
                    // className={'captialize'}

                        onClick={() => setOpen(true)}><FontAwesomeIcon
                    icon={faLayerGroup}/>{t('booking.select')} {t('bookingfrontend.resources')}
                    <Chip color="error" size="small" label={enabledResources.size}/>
                </Button>
                <MobileDialog open={open} onClose={() => setOpen(false)}>{content}</MobileDialog>
            </div>
        )
    }

    return content
};

export default CalendarResourceFilter;