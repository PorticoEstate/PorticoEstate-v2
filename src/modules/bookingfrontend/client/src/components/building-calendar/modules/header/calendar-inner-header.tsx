import React, {Dispatch, FC, MutableRefObject, useEffect, useMemo} from 'react';
import {Badge, Button} from "@digdir/designsystemet-react";
import {ChevronLeftIcon, ChevronRightIcon} from "@navikt/aksel-icons";
import styles from './calendar-inner-header.module.scss';
import {IBuilding} from "@/service/types/Building";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import CalendarDatePicker from "@/components/building-calendar/modules/header/calendar-date-picker";
import FullCalendar from "@fullcalendar/react";
import ButtonGroup from "@/components/button-group/button-group";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faCalendar} from "@fortawesome/free-regular-svg-icons";
import {faArrowRightLong, faLayerGroup, faTableList} from "@fortawesome/free-solid-svg-icons";
import {useEnabledResources, useTempEvents} from "@/components/building-calendar/calendar-context";
import {phpGWLink} from "@/service/util";
import Link from "next/link";

interface CalendarInnerHeaderProps {
    resourcesHidden: boolean
    setResourcesHidden: Dispatch<boolean>
    setView: Dispatch<string>;
    setLastCalendarView: Dispatch<void>;
    view: string;
    building: IBuilding;
    calendarRef: MutableRefObject<FullCalendar | null>;

}

const CalendarInnerHeader: FC<CalendarInnerHeaderProps> = (props) => {
    const t = useTrans();
    const {resourcesHidden, setResourcesHidden, view, calendarRef, setView} = props
    const enabledResources = useEnabledResources();
    const {tempEvents, setTempEvents} = useTempEvents();


    const applicationURL = useMemo(() => {
        const params = {
            menuaction: 'bookingfrontend.uiapplication.add',
            building_id: props.building.id,
            resources: [...enabledResources],
            dates: Object.values(tempEvents).map((ev) => `${Math.floor(ev.start.getTime() / 1000)}_${Math.floor(ev.end.getTime() / 1000)}`)

        }
        return phpGWLink('bookingfrontend/', params, false);
    }, [tempEvents, props.building, enabledResources]);

    const c = calendarRef.current;

    if (!c) {
        return null;
    }
    const calendarApi = c.getApi();
    const currentDate = calendarApi ? calendarApi.getDate() : new Date();



    return (
        <div className={styles.innerHeader}>
            <Button size={'sm'} icon={true} variant='tertiary'
                    style={{}}
                    className={`${styles.expandCollapseButton} ${resourcesHidden ? styles.closed : styles.open}`}
                    onClick={() => setResourcesHidden(!resourcesHidden)}>


                {props.building.name}
                <ChevronLeftIcon
                    className={`${styles.expandCollapseIcon} ${resourcesHidden ? styles.closed : styles.open}`}
                    fontSize='2.25rem'/>
            </Button>
            <Button variant={'secondary'} size={'sm'}
                    className={styles.mobileResourcesButton}
                // className={'captialize'}
                    onClick={() => setResourcesHidden(!resourcesHidden)}><FontAwesomeIcon
                icon={faLayerGroup}/>{t('booking.select')} {t('bookingfrontend.resources')}
                <Badge count={enabledResources.size} size={"md"} color={"danger"}></Badge>
            </Button>

            <div className={styles.datePicker}>
                <Button size={'sm'} icon={true} variant='tertiary' style={{borderRadius: "50%"}}
                        onClick={() => {
                            if (c) {
                                calendarApi.prev();
                            }
                        }}
                >
                    <ChevronLeftIcon style={{
                        height: '100%',
                        width: '100%'
                    }}/>
                </Button>
                <CalendarDatePicker currentDate={currentDate} view={c.getApi().view.type}
                                    onDateChange={(v) => v && calendarApi.gotoDate(v)}/>
                <Button icon={true} size={'sm'} variant='tertiary' style={{borderRadius: "50%"}}
                        onClick={() => {
                            if (c) {
                                calendarApi.next();
                            }
                        }}
                >
                    <ChevronRightIcon style={{
                        height: '100%',
                        width: '100%'
                    }}/>
                </Button>
            </div>

            <ButtonGroup className={styles.modeSelectTime}>
                <Button variant={view === 'timeGridDay' ? 'primary' : 'secondary'} color={'neutral'} size={'sm'}
                        className={'captialize'}

                        onClick={() => setView('timeGridDay')}>{t('bookingfrontend.day')}</Button>
                <Button variant={view === 'timeGridWeek' ? 'primary' : 'secondary'} color={'neutral'} size={'sm'}
                        className={'captialize'}

                        onClick={() => setView('timeGridWeek')}>{t('bookingfrontend.week')}</Button>
                {/*<Button variant={view === 'dayGridMonth' ? 'primary' : 'secondary'} color={'neutral'} size={'sm'}*/}
                {/*        className={'captialize'}*/}

                {/*        onClick={() => setView('dayGridMonth')}>{t('bookingfrontend.month')}</Button>*/}

            </ButtonGroup>

            <ButtonGroup className={styles.modeSelect}>
                <Button variant={view !== 'listWeek' ? 'primary' : 'secondary'} color={'neutral'} aria-active={'true'}
                        aria-current={'true'} size={'sm'}
                        className={'captialize'} onClick={() => {
                    props.setLastCalendarView()
                }}><FontAwesomeIcon icon={faCalendar}/> <span
                    className={styles.modeTitle}>{t('bookingfrontend.calendar_view')}</span></Button>
                <Button variant={view === 'listWeek' ? 'primary' : 'secondary'} color={'neutral'} size={'sm'}
                        className={'captialize'} onClick={() => {
                    props.setView('listWeek')
                }}><FontAwesomeIcon icon={faTableList}/> <span
                    className={styles.modeTitle}>{t('bookingfrontend.list_view')}</span></Button>
            </ButtonGroup>
            <Button variant={'primary'} asChild size={'sm'} className={styles.orderButton}>
                <Link href={applicationURL}>
                    {t('bookingfrontend.to application site')}
                    {Object.values(tempEvents).length > 0 &&
                        <Badge count={Object.values(tempEvents).length}
                               color={'info'} size={'sm'}>

                        </Badge>}
                    <FontAwesomeIcon icon={faArrowRightLong}/>
                </Link>

            </Button>
        </div>
    );
}

export default CalendarInnerHeader


