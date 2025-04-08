import React, {Dispatch, FC, useCallback, useEffect, useMemo, useState} from 'react';
import interactionPlugin, {EventResizeDoneArg} from "@fullcalendar/interaction";
import dayGridPlugin from "@fullcalendar/daygrid";
import timeGridPlugin from "@fullcalendar/timegrid";
import listPlugin from "@fullcalendar/list";
import {DateTime} from "luxon";
import {
	FCallBackgroundEvent,
	FCallBaseEvent,
	FCallEvent,
	FCallTempEvent, FCEventClickArg,
	FCEventContentArg
} from "@/components/building-calendar/building-calendar.types";
import styles from "@/components/building-calendar/building-calender.module.scss";
import FullCalendar from "@fullcalendar/react";
import EventContentTemp from "@/components/building-calendar/modules/event/content/event-content-temp";
import EventContentList from "@/components/building-calendar/modules/event/content/event-content-list";
import EventContentAllDay from "@/components/building-calendar/modules/event/content/event-content-all-day";
import EventContent from "@/components/building-calendar/modules/event/content";
import {DateSelectArg, DateSpanApi, DatesSetArg, EventDropArg, EventInput} from "@fullcalendar/core";
import {EventImpl} from "@fullcalendar/core/internal";
import {IUpdatePartialApplication} from "@/service/types/api/application.types";
import {FCallEventConverter} from "@/components/building-calendar/util/event-converter";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {useBookingUser, usePartialApplications, useUpdatePartialApplication} from "@/service/hooks/api-hooks";
import {useCurrentBuilding, useEnabledResources, useTempEvents} from "@/components/building-calendar/calendar-context";
import {IEvent} from "@/service/pecalendar.types";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {Season} from "@/service/types/Building";
import {useBuildingResources} from "@/service/api/building";

interface FullCalendarViewProps {
	calendarRef: React.MutableRefObject<FullCalendar | null>,
	viewMode: string,
	setViewMode: (viewMode: string) => void,
	selectEvent: (event: FCallEvent | FCallTempEvent, targetEl?: HTMLElement) => void,
	events?: IEvent[],
	setCurrentDate: (value: (((prevState: DateTime) => DateTime) | DateTime)) => void,
	currentDate: DateTime,
	seasons: Season[],
	onDateChange: Dispatch<DatesSetArg>,
	currentTempEvent?: Partial<FCallTempEvent>,
	handleDateSelect?: (selectInfo?: Partial<DateSelectArg>) => void
}

const FullCalendarView: FC<FullCalendarViewProps> = (props) => {
	const {
		calendarRef,
		viewMode,
		setViewMode,
		selectEvent,
		events,
		setCurrentDate,
		currentDate,
		seasons,
		currentTempEvent,
		handleDateSelect
	} = props;
	const isMobile = useIsMobile();
	const [calendarEvents, setCalendarEvents] = useState<(FCallBaseEvent)[]>([]);
	const [slotMinTime, setSlotMinTime] = useState('00:00:00');
	const [slotMaxTime, setSlotMaxTime] = useState('24:00:00');
	const {data: partials} = usePartialApplications();
	const updateMutation = useUpdatePartialApplication();
	const {tempEvents: storedTempEvents} = useTempEvents();
	const {enabledResources} = useEnabledResources();
	const building = useCurrentBuilding()
	const {data: resources} = useBuildingResources(building)
	const t = useTrans();
	const {data: user} = useBookingUser();

	useEffect(() => {
		if (calendarRef.current) {
			const calendarApi = calendarRef.current.getApi();
			const viewDate = DateTime.fromJSDate(calendarApi.getDate());

			// Only update if the dates don't match
			if (!currentDate.hasSame(viewDate, 'day')) {
				calendarApi.gotoDate(currentDate.toJSDate());
			}
		}
	}, [currentDate, calendarRef]);

	const generateBusinessHours = useCallback(() => {
		// Group boundaries by weekday
		return props.seasons.flatMap(season => {
			// Only use active seasons that cover the current date
			const now = DateTime.now();
			const seasonStart = DateTime.fromISO(season.from_);
			const seasonEnd = DateTime.fromISO(season.to_);

			if (!season.active || now < seasonStart || now > seasonEnd) {
				return [];
			}

			// Map each boundary to business hours
			return season.boundaries.map(boundary => ({
				daysOfWeek: [boundary.wday === 7 ? 0 : boundary.wday], // Convert Sunday from 7 to 0
				startTime: boundary.from_,
				endTime: boundary.to_
			}));
		});
	}, [props.seasons]);

	const generateEventConstraint = useCallback(() => {
		return {
			businessHours: generateBusinessHours(),
			startTime: slotMinTime,
			endTime: slotMaxTime
		};
	}, [generateBusinessHours, slotMinTime, slotMaxTime]);


	const calculateAbsoluteMinMaxTimes = useCallback(() => {
		let minTime = '24:00:00';
		let maxTime = '00:00:00';

		// Helper function to extract time portion from datetime string
		const extractTime = (dateTimeStr: string): string => {
			const dt = DateTime.fromISO(dateTimeStr);
			return dt.toFormat('HH:mm:ss');
		};

		// Check all season boundaries
		props.seasons.forEach(season => {
			season.boundaries.forEach(boundary => {
				if (boundary.from_ < minTime) minTime = boundary.from_;
				if (boundary.to_ > maxTime) maxTime = boundary.to_;
			});
		});

		// Check events
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

	const handleDateClick = useCallback((arg: { date: Date; dateStr: string; allDay: boolean; }) => {
		if (viewMode === 'dayGridMonth') {
			const clickedDate = DateTime.fromJSDate(arg.date);
			setCurrentDate(clickedDate);
			setViewMode('timeGridWeek');
			calendarRef.current?.getApi().gotoDate(clickedDate.toJSDate());
		}
	}, [calendarRef, setCurrentDate, setViewMode, viewMode]);


	const renderBackgroundEvents = useCallback(() => {
		const backgroundEvents: FCallBackgroundEvent[] = [];
		const today = DateTime.now();
		const startDate = currentDate.startOf('week');
		const endDate = startDate.plus({weeks: 4});

		// Add past dates background
		if (startDate.toMillis() < today.toMillis()) {
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

		// Find applicable seasons for the date range
		const applicableSeasons = seasons.filter(season => {
			const seasonStart = DateTime.fromISO(season.from_);
			const seasonEnd = DateTime.fromISO(season.to_);
			return season.active && seasonStart <= endDate && seasonEnd >= startDate;
		});

		// Add closed hours for each day
		for (let date = startDate; date < endDate; date = date.plus({days: 1})) {
			const dayOfWeek = date.weekday;

			// Get boundaries for this day from all applicable seasons
			const dayBoundaries = applicableSeasons.flatMap(season =>
				season.boundaries.filter(b => b.wday === dayOfWeek)
			);

			if (dayBoundaries.length > 0) {
				// Sort boundaries by start time
				const sortedBoundaries = [...dayBoundaries].sort((a, b) =>
					a.from_.localeCompare(b.from_)
				);

				// Add background for time before first opening
				backgroundEvents.push({
					start: date.startOf('day').toJSDate(),
					end: date.set({
						hour: parseInt(sortedBoundaries[0].from_.split(':')[0]),
						minute: parseInt(sortedBoundaries[0].from_.split(':')[1])
					}).toJSDate(),
					display: 'background',
					classNames: styles.closedHours,
					extendedProps: {
						closed: true,
						type: 'background'
					}
				});

				// Add background for time after last closing
				const lastBoundary = sortedBoundaries[sortedBoundaries.length - 1];
				backgroundEvents.push({
					start: date.set({
						hour: parseInt(lastBoundary.to_.split(':')[0]),
						minute: parseInt(lastBoundary.to_.split(':')[1])
					}).toJSDate(),
					end: date.plus({days: 1}).startOf('day').toJSDate(),
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
	}, [currentDate, seasons]);


	useEffect(() => {

	}, [currentDate]);

	useEffect(() => {
		const convertedEvents = (events || [])
			.map((e) => FCallEventConverter(e, enabledResources, user))
			.filter(e => e.mainEvent || e.backgroundEvent);

		const allEvents: FCallBaseEvent[] = [
			...convertedEvents.map(e => e.mainEvent).filter<FCallEvent>((item): item is FCallEvent => item !== null),
			...convertedEvents.map(e => e.backgroundEvent).filter<FCallBackgroundEvent>((item): item is FCallBackgroundEvent => item !== null),
			...renderBackgroundEvents()
		];

		setCalendarEvents(allEvents);
	}, [events, enabledResources, renderBackgroundEvents, user]);

	useEffect(() => {
		if (isMobile) {
			// const newView = whichView(window.innerWidth);
			const calendarApi = calendarRef.current?.getApi(); // Access calendar API

			if (calendarApi && 'timeGridDay' !== viewMode) {
				setViewMode('timeGridDay')
				// calendarApi.changeView(newView); // Change view dynamically
			}
		}
	}, [isMobile, setViewMode, viewMode]);

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

	const handleEventClick = useCallback((clickInfo: FCEventClickArg<FCallBaseEvent>) => {
		// Check if the clicked event is a background event
		if ('display' in clickInfo.event && clickInfo.event.display === 'background') {
			// Do not open popper for background events
			return;
		}

		// Check if the event is a valid, interactive event
		if ('id' in clickInfo.event && clickInfo.event.id) {
			selectEvent(clickInfo.event, clickInfo.el);
		}
	}, [selectEvent]);

	const checkEventOverlap = useCallback((span: DateSpanApi, movingEvent: EventImpl | null): boolean => {
		const calendarApi = calendarRef.current?.getApi();
		if (!calendarApi) return false;

		const selectStart = DateTime.fromJSDate(span.start);
		const selectEnd = DateTime.fromJSDate(span.end);

		// Prevent selections in the past
		const now = DateTime.now();
		if (selectStart < now) {
			return false;
		}

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

		// Check for resources with deny_application_if_booked flag
		const selectedResources = [...enabledResources].map(Number);
		const resourcesWithDenyFlag = events
			?.flatMap(event => event.resources)
			.filter(res => selectedResources.includes(res.id) && resources?.find(r => r.id === res.id)?.deny_application_if_booked === 1);

		const hasResourceWithDenyFlag = resourcesWithDenyFlag && resourcesWithDenyFlag.length > 0;

		// Check for overlap with each event's actual times
		return !relevantEvents.some(event => {
			// Get actual start and end times from extendedProps
			const eventStart = DateTime.fromJSDate(event.extendedProps.actualStart || event.start!);
			const eventEnd = DateTime.fromJSDate(event.extendedProps.actualEnd || event.end!);

			// Check if the selection overlaps with this event
			const overlap = !(selectEnd <= eventStart || selectStart >= eventEnd);

			// If there's a resource with deny_application_if_booked=1, block overlaps with booking, allocation, or event
			if (overlap && hasResourceWithDenyFlag &&
				(event.extendedProps.type === 'booking' ||
					event.extendedProps.type === 'allocation' ||
					event.extendedProps.type === 'event')) {
				return true;
			}

			// If there's no deny flag, use the standard rules (only block closed)
			if (overlap && (event.extendedProps.closed)) {
				return true;
			}

			return false;
		});
	}, [enabledResources, events, resources]);

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


	const calendarVisEvents = useMemo(() => [...calendarEvents, ...tempEventArr, currentTempEvent].filter(Boolean) as EventInput[], [calendarEvents, tempEventArr, currentTempEvent]);

	return (
		<FullCalendar
			ref={calendarRef}
			plugins={[interactionPlugin, dayGridPlugin, timeGridPlugin, listPlugin]}
			initialView={viewMode}
			slotMinTime={slotMinTime}
			slotMaxTime={slotMaxTime}
			headerToolbar={false}
			slotDuration={"00:30:00"}
			themeSystem={'bootstrap'}
			allDayText={t('bookingfrontend.all_day')}
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
	);
}

export default FullCalendarView


