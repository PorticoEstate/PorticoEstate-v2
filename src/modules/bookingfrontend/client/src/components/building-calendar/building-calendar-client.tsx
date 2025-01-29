import React, {Dispatch, FC, useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {DateTime, Settings} from 'luxon'
import FullCalendar from "@fullcalendar/react";
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin, {EventResizeDoneArg} from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import {IEvent, Season} from "@/service/pecalendar.types";
import {
    DateSelectArg, DateSpanApi,
    DatesSetArg,
    EventDropArg,
    EventInput,
} from "@fullcalendar/core";
import styles from './building-calender.module.scss'
import {FCallEventConverter} from "@/components/building-calendar/util/event-converter";
import EventContent from "@/components/building-calendar/modules/event/content/event-content";
import EventPopper from "@/components/building-calendar/modules/event/popper/event-popper";
import CalendarInnerHeader from "@/components/building-calendar/modules/header/calendar-inner-header";
import {usePopperData} from "@/service/api/event-info";
import {
    FCallBackgroundEvent, FCallBaseEvent,
    FCallEvent,
    FCallTempEvent, FCEventClickArg,
    FCEventContentArg
} from "@/components/building-calendar/building-calendar.types";
import {useEnabledResources, useTempEvents} from "@/components/building-calendar/calendar-context";
import EventContentTemp from "@/components/building-calendar/modules/event/content/event-content-temp";
import {IBuilding} from "@/service/types/Building";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useIsMobile} from "@/service/hooks/is-mobile";
import EventContentList from "@/components/building-calendar/modules/event/content/event-content-list";
import {EventImpl} from "@fullcalendar/core/internal";
import EventContentAllDay from "@/components/building-calendar/modules/event/content/event-content-all-day";
import {useBuilding, useBuildingResources} from "@/service/api/building";
import {usePartialApplications, useUpdatePartialApplication} from "@/service/hooks/api-hooks";
import {IUpdatePartialApplication} from "@/service/types/api/application.types";
import DebugInfo from "@/components/building-calendar/util/debug-info/debug-info";
import ApplicationCrud from "@/components/building-calendar/modules/event/edit/application-crud";

interface BuildingCalendarProps {
    events?: IEvent[];
    onDateChange: Dispatch<DatesSetArg>
    seasons: Season[];
    building: IBuilding;
    initialDate: DateTime;
    initialEnabledResources: Set<string>;
}

Settings.defaultLocale = "nb";


const BuildingCalendarClient: FC<BuildingCalendarProps> = (props) => {
    const isMobile = useIsMobile();
    const {data: building, isLoading, isStale} = useBuilding(props.building.id, undefined, props.building);
    const t = useTrans();
    const {events} = props;
    const [currentDate, setCurrentDate] = useState<DateTime>(props.initialDate);
    const [calendarEvents, setCalendarEvents] = useState<(FCallBaseEvent)[]>([]);
    const [slotMinTime, setSlotMinTime] = useState('00:00:00');
    const [slotMaxTime, setSlotMaxTime] = useState('24:00:00');
    const calendarRef = useRef<FullCalendar | null>(null);
    const [view, setView] = useState<string>(window.innerWidth < 601 ? 'timeGridDay' : 'timeGridWeek');
    const [lastCalendarView, setLastCalendarView] = useState<string>('timeGridWeek');
    const {data: partials} = usePartialApplications();

    const updateMutation = useUpdatePartialApplication();


    const [selectedEvent, setSelectedEvent] = useState<FCallEvent | FCallTempEvent | null>(null);
    const [popperAnchorEl, setPopperAnchorEl] = useState<HTMLElement | null>(null);

    const [currentTempEvent, setCurrentTempEvent] = useState<Partial<FCallTempEvent>>();
    const {tempEvents: storedTempEvents} = useTempEvents();

    const {enabledResources} = useEnabledResources();
    const {data: resources} = useBuildingResources(props.building.id);

    const eventInfos = usePopperData(
        (events || []).filter(e => e.type === 'event').map(e => e.id),
        (events || []).filter(e => e.type === 'allocation').map(e => e.id),
        (events || []).filter(e => e.type === 'booking').map(e => e.id)
    );


    useEffect(() => {
        if (view === 'listWeek') {
            return;
        }
        if (view === lastCalendarView) {
            return;
        }
        setLastCalendarView(view)

    }, [view, lastCalendarView]);


    const handleDateClick = (arg: { date: Date; dateStr: string; allDay: boolean; }) => {
        if (view === 'dayGridMonth') {
            const clickedDate = DateTime.fromJSDate(arg.date);
            setCurrentDate(clickedDate);
            setView('timeGridWeek');
            calendarRef.current?.getApi().gotoDate(clickedDate.toJSDate());
        }
    };

    const handleDateSelect = useCallback((selectInfo?: Partial<DateSelectArg>) => {
        if (selectInfo?.view?.type === 'dayGridMonth') {
            return;
        }
        // console.log(enabledResources, props.resources);
        const title = t('bookingfrontend.new application');

        const newEvent: FCallTempEvent = {
            id: `temp-${Date.now()}`,
            title,
            start: selectInfo?.start,
            end: selectInfo?.end,
            allDay: selectInfo?.allDay ?? false,
            editable: true,
            extendedProps: {
                type: 'temporary',
                resources: [...enabledResources],
                building_id: props.building.id,
            },
        };
        // setTempEvents(prev => ({...prev, [newEvent.id]: newEvent}))
        setCurrentTempEvent(newEvent);
        selectInfo?.view?.calendar.unselect(); // Clear selection
    }, [t, enabledResources, props.building.id]);


    const handleEventClick = useCallback((clickInfo: FCEventClickArg<FCallBaseEvent>) => {
        // Check if the clicked event is a background event
        if ('display' in clickInfo.event && clickInfo.event.display === 'background') {
            // Do not open popper for background events
            return;
        }

        // Check if the event is a valid, interactive event
        if ('id' in clickInfo.event && clickInfo.event.id) {
            if (clickInfo.event.extendedProps.type === 'temporary') {
                setCurrentTempEvent(clickInfo.event as FCallTempEvent);
            } else {
                setSelectedEvent(clickInfo.event);
                setPopperAnchorEl(clickInfo.el);
            }
        }
    }, []);


    const calculateAbsoluteMinMaxTimes = useCallback(() => {
        let minTime = '24:00:00';
        let maxTime = '00:00:00';

        // Helper function to extract time portion from datetime string
        const extractTime = (dateTimeStr: string): string => {
            // If it's already in HH:mm:ss format, return as is
            if (/^\d{2}:\d{2}:\d{2}$/.test(dateTimeStr)) {
                return dateTimeStr;
            }

            // Parse datetime string and return time portion
            try {
                const dt = DateTime.fromSQL(dateTimeStr); // Remove milliseconds if present
                return dt.toFormat('HH:mm:ss');
            } catch (e) {
                console.error('Error parsing datetime:', dateTimeStr);
                return '00:00:00';
            }
        };

        // Check seasons (assuming seasons format hasn't changed)
        props.seasons.forEach(season => {
            if (season.from_ < minTime) minTime = season.from_;
            if (season.to_ > maxTime) maxTime = season.to_;
        });

        // Check events with new format
        (events || []).forEach(event => {
            const eventStartTime = extractTime(event.from_);
            const eventEndTime = extractTime(event.to_);

            if (eventStartTime < minTime) minTime = eventStartTime;
            if (eventEndTime > maxTime) maxTime = eventEndTime;
        });

        // Set default values if no valid times found
        setSlotMinTime(minTime === "24:00:00" ? '06:00:00' : minTime);
        setSlotMaxTime(maxTime === "00:00:00" ? '24:00:00' : maxTime);
    }, [props.seasons, events]);
    useEffect(() => {
        calculateAbsoluteMinMaxTimes();
    }, [calculateAbsoluteMinMaxTimes]);


    const renderBackgroundEvents = useCallback(() => {
        const backgroundEvents: FCallBackgroundEvent[] = [];
        const today = DateTime.now();
        const startDate = currentDate.startOf('week');
        const endDate = startDate.plus({weeks: 4});
        // Add past dates background
        if (startDate.toMillis() < today.toMillis()) {
            console.log(startDate.toJSDate(), today.toJSDate())

            backgroundEvents.push({
                start: startDate.toJSDate(),
                end: today.toJSDate(),
                display: 'background',
                classNames: styles.closedHours,
                extendedProps: {
                    type: 'background'
                }
            });
        }

        // Add closed hours for each day
        for (let date = startDate; date < endDate; date = date.plus({days: 1})) {
            const dayOfWeek = date.weekday;
            const season = props.seasons.find(s => s.wday === dayOfWeek);

            if (season) {
                // Add background event for time before opening
                backgroundEvents.push({
                    start: new Date(date.toFormat("yyyy-MM-dd'T00:00:00'")),
                    end: new Date(date.toFormat(`yyyy-MM-dd'T${season.from_}'`)),
                    display: 'background',
                    classNames: styles.closedHours,
                    extendedProps: {
                        closed: true,
                        type: 'background'
                    }
                });

                // Add background event for time after closing
                backgroundEvents.push({
                    start: new Date(date.toFormat(`yyyy-MM-dd'T${season.to_}'`)),
                    end: new Date(date.plus({days: 1}).toFormat("yyyy-MM-dd'T00:00:00'")),
                    display: 'background',
                    classNames: styles.closedHours,
                    extendedProps: {
                        closed: true,
                        type: 'background'
                    }
                });
            }
        }

        return backgroundEvents;
    }, [currentDate, props.seasons]);


    const checkEventOverlap = useCallback((span: DateSpanApi, movingEvent: EventImpl | null): boolean => {
        const calendarApi = calendarRef.current?.getApi();
        if (!calendarApi) return false;

        const selectStart = DateTime.fromJSDate(span.start);
        const selectEnd = DateTime.fromJSDate(span.end);

        // Get all events in the calendar
        const allEvents = calendarApi.getEvents();

        // Filter to only get actual events (not background events)
        const relevantEvents = allEvents.filter(event => {
            const eventProps = event.extendedProps as any;
            // Skip the moving event if it exists
            if (movingEvent && event === movingEvent) {
                return false;
            }
            return eventProps.type === 'event' || eventProps.type === 'booking' || eventProps.type === 'allocation' || eventProps.closed;
        });

        // Check for overlap with each event's actual times
        return !relevantEvents.some(event => {
            // Get actual start and end times from extendedProps
            const eventStart = DateTime.fromJSDate(event.extendedProps.actualStart || event.start!);
            const eventEnd = DateTime.fromJSDate(event.extendedProps.actualEnd || event.end!);

            // Check if the selection overlaps with this event
            const overlap = !(selectEnd <= eventStart || selectStart >= eventEnd);

            // If there's an overlap and it's an event type that blocks selection, return true
            if (overlap && (event.extendedProps.type === 'event' || event.extendedProps.closed)) {
                return true;
            }

            return false;
        });
    }, []);

    const handleEventResize = useCallback((resizeInfo: EventResizeDoneArg | EventDropArg) => {
        const newEnd = resizeInfo.event.end;
        const newStart = resizeInfo.event.start;
        if (!newEnd || !newStart) {
            console.log("No new date")
            return;
        }
        if (resizeInfo.event.extendedProps?.type === 'temporary' && 'applicationId' in resizeInfo.event.extendedProps) {
            const eventId = resizeInfo.event.extendedProps.applicationId;
            const dateId = resizeInfo.event.id;
            console.log(partials?.list);
            const existingEvent = partials?.list.find(app => +app.id === +eventId);

            if (!eventId || !dateId || !existingEvent) {
                console.log("missing data", eventId, dateId, existingEvent)

                return;
            }

            const updatedApplication: IUpdatePartialApplication = {
                id: eventId,
            }
            // if(existingEvent.dates.length > 1) {
            updatedApplication.dates = existingEvent.dates.map(date => {
                if (date.id && date && +dateId === +date.id) {
                    return {
                        ...date,
                        from_: newStart.toISOString(),
                        to_: newEnd.toISOString()
                    }
                }
                return date
            })

            updateMutation.mutate({id: existingEvent.id, application: updatedApplication});
            // onClose();
            // return;
            // if (currentTempEvent && resizeInfo.event.id === currentTempEvent.id) {
            //     setCurrentTempEvent({
            //         ...currentTempEvent,
            //         end: resizeInfo.event.end as Date,
            //         start: resizeInfo.event.start as Date
            //     });
            //     return;
            // }
            // setStoredTempEvents(prev => ({
            //     ...prev,
            //     [resizeInfo.event.id]: {
            //         ...prev[resizeInfo.event.id],
            //         start: resizeInfo.event.start as Date,
            //         end: resizeInfo.event.end as Date
            //     }
            // }))

        }

    }, [partials?.list, updateMutation]);

    const tempEventArr = useMemo(() => Object.values(storedTempEvents), [storedTempEvents])

    const popperPlacement = useMemo(() => {
        switch (calendarRef.current?.getApi().view.type) {
            case 'timeGridDay':
                return 'bottom-start';
            case 'listWeek':
                return 'bottom-start';
            default:
                const el = popperAnchorEl;
                if (el) {
                    const rect = el.getBoundingClientRect();
                    const screenWidth = window.innerWidth;
                    const elementRightPosition = rect.right;

                    // Check if element is more than 60% to the right of the screen
                    return (elementRightPosition / screenWidth > 0.6) ? 'left-start' : 'right-start';
                }
                return 'right-start';
        }
    }, [calendarRef.current?.getApi().view.type, popperAnchorEl]);

    useEffect(() => {
        const convertedEvents = (events || [])
            .map((e) => FCallEventConverter(e, enabledResources))
            .filter(e => e.mainEvent || e.backgroundEvent);

        const allEvents: FCallBaseEvent[] = [
            ...convertedEvents.map(e => e.mainEvent).filter<FCallEvent>((item): item is FCallEvent => item !== null),
            ...convertedEvents.map(e => e.backgroundEvent).filter<FCallBackgroundEvent>((item): item is FCallBackgroundEvent => item !== null),
            ...renderBackgroundEvents()
        ];

        setCalendarEvents(allEvents);
    }, [events, enabledResources]);


    useEffect(() => {
        calendarRef?.current?.getApi().changeView(view)
    }, [view]);


    useEffect(() => {
        if (isMobile) {
            // const newView = whichView(window.innerWidth);
            const calendarApi = calendarRef.current?.getApi(); // Access calendar API

            if (calendarApi && 'timeGridDay' !== view) {
                setView('timeGridDay')
                // calendarApi.changeView(newView); // Change view dynamically
            }
        }
    }, [isMobile]);

    const generateBusinessHours = useCallback(() => {
        return props.seasons.map(season => ({
            daysOfWeek: [season.wday === 7 ? 0 : season.wday], // Convert Sunday from 7 to 0
            startTime: season.from_,
            endTime: season.to_
        }));
    }, [props.seasons]);

    const generateEventConstraint = useCallback(() => {
        return {
            businessHours: generateBusinessHours(),
            startTime: slotMinTime,
            endTime: slotMaxTime
        };
    }, [generateBusinessHours, slotMinTime, slotMaxTime]);


    const calendarVisEvents = useMemo(() => [...calendarEvents, ...tempEventArr, currentTempEvent].filter(Boolean) as EventInput[], [calendarEvents, tempEventArr, currentTempEvent]);

    // console.log([...calendarEvents, ...tempEventArr, ...renderBackgroundEvents()])

    function renderEventContent(eventInfo: FCEventContentArg<FCallBaseEvent>) {
        const type = eventInfo.event.extendedProps.type;
        if (type === 'background') {
            return null;
        }
        if (type === 'temporary') {
            return <EventContentTemp eventInfo={eventInfo as FCEventContentArg<FCallTempEvent>}/>
        }

        if (calendarRef.current?.getApi().view.type === 'listWeek') {
            return <EventContentList eventInfo={eventInfo as FCEventContentArg<FCallEvent>}/>;
        }
        if (eventInfo.event.allDay) {
            return <EventContentAllDay eventInfo={eventInfo as FCEventContentArg<FCallEvent>}/>;
        }
        return <EventContent eventInfo={eventInfo as FCEventContentArg<FCallEvent>}
        />
    }


    return (
        <React.Fragment>
            <DebugInfo
                currentDate={currentDate}
                seasons={props.seasons}
                view={view}
                enabledResources={enabledResources}
            />
            <CalendarInnerHeader view={view} calendarRef={calendarRef}
                                 setView={(v) => setView(v)}
                                 setLastCalendarView={() => setView(lastCalendarView)} building={props.building}
                                 createNew={() => handleDateSelect()}/>
            <FullCalendar
                ref={calendarRef}
                plugins={[interactionPlugin, dayGridPlugin, timeGridPlugin, listPlugin]}
                initialView={view}
                slotMinTime={slotMinTime}
                slotMaxTime={slotMaxTime}
                headerToolbar={false}
                slotDuration={"00:30:00"}
                themeSystem={'bootstrap'}

                firstDay={1}
                eventClick={(clickInfo) => handleEventClick(clickInfo as any)}
                datesSet={(dateInfo) => {
                    props.onDateChange(dateInfo);
                    setCurrentDate(DateTime.fromJSDate(dateInfo.start));
                }}
                eventContent={(eventInfo: FCEventContentArg<FCallEvent | FCallTempEvent>) => renderEventContent(eventInfo)}
                views={{
                    timeGrid: {
                        slotLabelFormat: {
                            hour: '2-digit',
                            minute: '2-digit',
                            hour12: false
                        }
                    },
                    list: {
                        eventClassNames: ({event: {extendedProps}}) => {
                            return `clickable ${
                                extendedProps.cancelled ? 'event-cancelled' : ''
                            }`
                        },
                    },
                    month: {
                        eventTimeFormat: {
                            hour: '2-digit',
                            minute: '2-digit',
                            hour12: false
                        },
                    },
                }}
                dayHeaderFormat={{weekday: 'long'}}
                dayHeaderContent={(args) => (
                    <div className={styles.dayHeader}>
                        <div>{args.date.toLocaleDateString('nb-NO', {weekday: 'long'})}</div>
                        <div>{args.date.getDate()}</div>
                    </div>
                )}
                weekNumbers={true}
                weekText="Uke "
                locale={DateTime.local().locale}
                selectable={true}
                height={'auto'}
                eventMaxStack={4}
                select={handleDateSelect}
                dateClick={handleDateClick}
                events={calendarVisEvents}
                // editable={true}
                // selectOverlap={(stillEvent, movingEvent) => {
                //     console.log(stillEvent);
                //     return stillEvent?.extendedProps?.type !== 'event'
                // }}
                selectAllow={checkEventOverlap}
                eventResize={handleEventResize}
                eventDrop={handleEventResize}
                initialDate={currentDate.toJSDate()}
                businessHours={generateBusinessHours()}
                eventConstraint={generateEventConstraint()}
                selectConstraint={generateEventConstraint()}

                // selectConstraint={{
                //     startTime: slotMinTime,
                //     endTime: slotMaxTime,
                //     daysOfWeek: props.seasons.map(season => (season.wday === 7 ? 0 : season.wday))
                // }}
                // style={{gridColumn: 2}}
            />

            <EventPopper
                event={selectedEvent}
                placement={
                    popperPlacement
                }
                anchor={popperAnchorEl} onClose={() => {
                setSelectedEvent(null);
                setPopperAnchorEl(null);
            }}/>

                <ApplicationCrud onClose={() => setCurrentTempEvent(undefined)} selectedTempApplication={currentTempEvent}
                           building_id={props.building.id}/>


        </React.Fragment>
    );
}

export default BuildingCalendarClient;



