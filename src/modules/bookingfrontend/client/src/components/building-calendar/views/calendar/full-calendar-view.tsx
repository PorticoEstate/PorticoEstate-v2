import React, {Dispatch, FC, useCallback, useEffect, useMemo, useState} from 'react';
import { IResource } from '@/service/types/resource.types';
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
import {
	useCurrentBuilding,
	useEnabledResources,
	useIsOrganization,
	useTempEvents
} from "@/components/building-calendar/calendar-context";
import {IEvent} from "@/service/pecalendar.types";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {Season} from "@/service/types/Building";
import {useBuilding, useBuildingResources} from "@/service/api/building";
import { useToast } from "@/components/toast/toast-context";
import {isApplicationDeactivated} from "@/service/utils/deactivation-utils";

interface FullCalendarViewProps {
	calendarRef: React.MutableRefObject<FullCalendar | null>,
	viewMode: string,
	setViewMode: (viewMode: string) => void,
	selectEvent: (event: FCallEvent | FCallTempEvent, targetEl?: HTMLElement) => void,
	events?: IEvent[],
	setCurrentDate: (value: (((prevState: DateTime) => DateTime) | DateTime)) => void,
	currentDate: DateTime,
	seasons?: Season[],
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
	const buildingId = useCurrentBuilding()
	const {data: building} = useBuilding(buildingId);
	const {data: resources} = useBuildingResources(buildingId)
	const t = useTrans();
	const {data: user} = useBookingUser();
	const { addToast } = useToast();
	const isOrg = useIsOrganization();

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
		return props.seasons?.flatMap(season => {
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
			// Use the calculated slotMaxTime which respects boundaries
			// but also extends to midnight when appropriate
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
		props.seasons?.forEach(season => {
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

		// If seasons is not set and isOrg is true, set default times for organizations
		if (!props.seasons && isOrg) {
			if (minTime === '24:00:00') minTime = '08:00:00';
			if (maxTime === '00:00:00') maxTime = '23:00:00';
		}

		// Set default values if no valid times found
		setSlotMinTime(minTime === "24:00:00" ? '00:00:00' : minTime);

		// For max time, check if the calculated time is very late (23:45 or later)
		// If so, extend it to the end of day (24:00). Otherwise respect the boundary.
		// This means we'll respect season boundaries for display but allow booking until
		// midnight for seasons that end very late or when no boundaries exist.
		setSlotMaxTime(
			maxTime === "00:00:00" || // No boundaries found
			maxTime >= "23:45:00"     // Season closes very late
				? '24:00:00'          // Allow until midnight
				: maxTime             // Otherwise respect the boundary
		);
	}, [props.seasons, events, isOrg]);


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
					type: 'background',
					source: 'past'
				}
			});
		}

		// Find applicable seasons for the date range
		const applicableSeasons = seasons?.filter(season => {
			const seasonStart = DateTime.fromISO(season.from_);
			const seasonEnd = DateTime.fromISO(season.to_);
			return season.active && seasonStart <= endDate && seasonEnd >= startDate;
		});

		// Add closed hours for each day
		for (let date = startDate; date < endDate; date = date.plus({days: 1})) {
			const dayOfWeek = date.weekday;

			// Get boundaries for this day from all applicable seasons
			const dayBoundaries = applicableSeasons?.flatMap(season =>
				season.boundaries.filter(b => b.wday === dayOfWeek)
			);

			if ((dayBoundaries?.length || 0) > 0) {
				// Sort boundaries by start time
				const sortedBoundaries = [...(dayBoundaries || [])].sort((a, b) =>
					a.from_.localeCompare(b.from_)
				);

				// Add background for time before first opening - but only if start time isn't midnight
				const firstBoundaryFrom = sortedBoundaries[0].from_;
				if (firstBoundaryFrom !== "00:00:00") {
					backgroundEvents.push({
						start: date.startOf('day').toJSDate(),
						end: date.set({
							hour: parseInt(firstBoundaryFrom.split(':')[0]),
							minute: parseInt(firstBoundaryFrom.split(':')[1])
						}).toJSDate(),
						display: 'background',
						classNames: styles.closedHours,
						extendedProps: {
							closed: true,
							type: 'background',
							source: 'seasonBeforeStart'
						}
					});
				}

				// Add background for time after last closing
				const lastBoundary = sortedBoundaries[sortedBoundaries.length - 1];
				const lastBoundaryTo = lastBoundary.to_;

				// Only add after-hours background if the venue doesn't close near midnight
				// (Skip if closing time is 23:45:00 or later)
				if (lastBoundaryTo < "23:45:00") {
					backgroundEvents.push({
						start: date.set({
							hour: parseInt(lastBoundaryTo.split(':')[0]),
							minute: parseInt(lastBoundaryTo.split(':')[1])
						}).toJSDate(),
						end: date.plus({days: 1}).startOf('day').toJSDate(),
						display: 'background',
						classNames: styles.closedHours,
						extendedProps: {
							closed: true,
							type: 'background',
							source:'seasonAfterHours'
						}
					});
				}
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
			// Only show toast for actual user selection attempts, not for validation calls
			if (span.end && span.start) { // This indicates a complete user selection
				addToast({
					type: 'info',
					// title: t('bookingfrontend.error'),
					text: t('bookingfrontend.start_time_in_past'),
					autoHide: true,
					messageId: 'start_time_in_past' // Unique ID for this type of error
				});
			}
			return false;
		}

		// Check if the calendar is deactivated for the building
		if (building?.deactivate_calendar) {
			// Only show toast for actual user selection attempts
			if (span.end && span.start) {
				addToast({
					type: 'warning',
					text: t('bookingfrontend.booking_unavailable'),
					autoHide: true,
					messageId: 'calendar_deactivated'
				});
			}
			return false;
		}

		// Check if any selected resources have deactivated applications
		const selectedResources = [...enabledResources].map(Number);
		const deactivatedResources = selectedResources.filter(resourceId => {
			const resource = resources?.find(r => r.id === resourceId);
			return resource && building ? isApplicationDeactivated(resource, building) : resource?.deactivate_application;
		});

		if (deactivatedResources.length > 0) {
			// Only show toast for actual user selection attempts
			if (span.end && span.start) {
				addToast({
					type: 'warning',
					text: t('bookingfrontend.booking_unavailable'),
					autoHide: true,
					messageId: 'booking_unavailable'
				});
			}
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
		const unixTime = Date.now() / 1000;
		const checkDirectBooking = (res?: IResource) => {
			if(!res || !res.direct_booking) {
				return false;
			}
			return unixTime > res.direct_booking;
		}

		// Check for resources with deny_application_if_booked flag
		// selectedResources already defined above
		let resourcesWithDenyFlagArr = events
			?.flatMap(event => event.resources)
			.filter(res => selectedResources.includes(res.id) && (resources?.find(r => r.id === res.id)?.deny_application_if_booked === 1 || checkDirectBooking(resources?.find(r => r.id === res.id))));
		const resourcesWithDenyFlag = [...new Set(resourcesWithDenyFlagArr?.map(a => a.id))];
		const hasResourceWithDenyFlag = resourcesWithDenyFlag && resourcesWithDenyFlag.length > 0;

		// Check for overlap with each event's actual times
		let overlapEventName = '';

		const hasNoOverlap = !relevantEvents.some(event => {
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
				// Store the overlapping event name if available
				if (event.title) {
					overlapEventName = event.title;
				}
				return true;
			}

			// If there's no deny flag, use the standard rules (only block closed)
			return !!(overlap && (event.extendedProps.closed));


		});

		// If we have an overlap, show a toast notification ONLY on final user selection
		if (!hasNoOverlap && span.end && span.start) { // This indicates a complete selection
			// Include the overlapping event name in the message if available
			let message = <span>{t('bookingfrontend.resource_overlap_detected')}</span>;


			// Create a unique messageId based on the overlapping resources and event
			const resourceNames = resourcesWithDenyFlag?.map(resource => {
				const res = resources?.find(r => r.id === resource);
				return res?.name || resource.toString();
			});
			if (resourceNames) {
				message = <span>{message}<br />{t('bookingfrontend.no_overlap', {res: resourceNames.join(', ')})}</span>
			}
			const messageId = `overlap_${resourceNames.join('_')}_${overlapEventName || 'unknown'}`;

			addToast({
				type: 'info',
				// title: t('bookingfrontend.booking_conflict'),
				text: message,
				autoHide: true,
				messageId: messageId
			});
		}

		return hasNoOverlap;
	}, [enabledResources, events, resources, addToast, t, buildingId]);

	const handleEventResize = useCallback((resizeInfo: EventResizeDoneArg | EventDropArg) => {
		const newEnd = resizeInfo.event.end;
		const newStart = resizeInfo.event.start;
		if (!newEnd || !newStart) {
			// console.log("No new date")
			return;
		}
		if (resizeInfo.event.extendedProps?.type === 'temporary' && 'applicationId' in resizeInfo.event.extendedProps) {
			const eventId = resizeInfo.event.extendedProps.applicationId;
			const dateId = resizeInfo.event.id;
			const existingEvent = partials?.list.find(app => +app.id === +eventId);

			if (!eventId || !dateId || !existingEvent) {
				// console.log("missing data", eventId, dateId, existingEvent)
				return;
			}

			// Check for overlap before updating
			const span: DateSpanApi = {
				start: newStart,
				end: newEnd,
				allDay: false,
				startStr: newStart.toISOString(),
				endStr: newEnd.toISOString()
			};

			// Only proceed with the update if there's no overlap
			const hasNoOverlap = checkEventOverlap(span, resizeInfo.event as EventImpl);

			if (!hasNoOverlap) {
				// If there's an overlap, revert the event to its original position
				resizeInfo.revert();
				return;
			}

			const updatedApplication: IUpdatePartialApplication = {
				id: eventId,
			}

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
		}

	}, [partials?.list, updateMutation, checkEventOverlap]);

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
			selectable={!isOrg}
			height={'auto'}
			eventMaxStack={4}
			select={handleDateSelect}
			dateClick={handleDateClick}
			events={calendarVisEvents}
			eventClassNames={({event}) => {
				if (event.extendedProps?.type === 'temporary') {
					return `${styles.event} ${styles['event-temporary']}`;
				}
				return '';
			}}
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