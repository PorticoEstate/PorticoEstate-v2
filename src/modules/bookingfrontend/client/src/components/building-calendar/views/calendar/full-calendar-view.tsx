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
	selectEvent: (event: FCallEvent | FCallTempEvent, storedTempEvents: FCallTempEvent[], targetEl?: HTMLElement) => void,
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
	const [viewStart, setViewStart] = useState<DateTime | null>(null);
	const [viewEnd, setViewEnd] = useState<DateTime | null>(null);
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
		// For business hours, we'll use a simplified approach that doesn't mix seasons
		// The detailed season handling will be done in renderBackgroundEvents
		if (!viewStart || !viewEnd || !props.seasons) return [];

		// Find the primary season that covers most of the view period
		const viewMiddle = viewStart.plus({milliseconds: viewEnd.diff(viewStart).milliseconds / 2});
		const primarySeason = props.seasons.find(season => {
			if (!season.active) return false;
			const seasonStart = DateTime.fromISO(season.from_);
			const seasonEnd = DateTime.fromISO(season.to_);

			// Check if season has any resources that match enabled resources (from V2)
			const hasMatchingResources = season.resources.some(seasonResource =>
				enabledResources.has(seasonResource.id.toString())
			);

			return viewMiddle >= seasonStart && viewMiddle <= seasonEnd && hasMatchingResources;
		});

		if (!primarySeason) return [];

		// Use the primary season's boundaries for business hours
		return primarySeason.boundaries.map(boundary => ({
			daysOfWeek: [boundary.wday === 7 ? 0 : boundary.wday], // Convert Sunday from 7 to 0
			startTime: boundary.from_,
			endTime: boundary.to_
		}));
	}, [props.seasons, viewStart, viewEnd, enabledResources]);

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
// Check season boundaries that are relevant to the current calendar view
		props.seasons?.forEach(season => {
			if (!season.active) return;

			const seasonStart = DateTime.fromISO(season.from_);
			const seasonEnd = DateTime.fromISO(season.to_);

			// If we have view dates, only consider seasons that overlap with the view (from V1)
			if (viewStart && viewEnd) {
				const seasonOverlapsView = seasonStart <= viewEnd && seasonEnd >= viewStart;
				if (!seasonOverlapsView) return;
			}

			// Check if season has any resources that match enabled resources (from V2)
			const hasMatchingResources = season.resources.some(seasonResource =>
				enabledResources.has(seasonResource.id.toString())
			);

			if (!hasMatchingResources) return;

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
		const finalMinTime = minTime === "24:00:00" ? '00:00:00' : minTime;
		// Round down to the nearest full hour
		const minHour = Math.floor(parseInt(finalMinTime.split(':')[0]));
		setSlotMinTime(`${minHour.toString().padStart(2, '0')}:00:00`);

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
	}, [props.seasons, events, isOrg, viewStart, viewEnd, enabledResources]);

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

		// Use actual calendar view dates if available, fallback to current date based calculation
		const startDate = viewStart || currentDate.startOf('week');
		const endDate = viewEnd || startDate.plus({weeks: 4});

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

		// Check if we have season transitions during the view period
		const hasSeasonTransition = seasons && seasons.length > 1 && viewStart && viewEnd && seasons.some(season1 =>
			seasons.some(season2 =>
				season1.id !== season2.id &&
				season1.active && season2.active &&
				DateTime.fromISO(season1.to_) >= viewStart &&
				DateTime.fromISO(season2.from_) <= viewEnd
			)
		);

		// Only add custom background events during season transitions
		if (hasSeasonTransition) {
			for (let date = startDate; date < endDate; date = date.plus({days: 1})) {
				const dayOfWeek = date.weekday;

				// Find the season that applies to this specific day
				const applicableSeasons = seasons?.filter(season => {
					if (!season.active) return false;
					const seasonStart = DateTime.fromISO(season.from_);
					const seasonEnd = DateTime.fromISO(season.to_);

					// Check if season has any resources that match enabled resources (from V2)
					const hasMatchingResources = season.resources.some(seasonResource =>
						enabledResources.has(seasonResource.id.toString())
					);

					// Check if this day falls within the season's date range
					return date >= seasonStart.startOf('day') && date <= seasonEnd.endOf('day') && hasMatchingResources;
				}) || [];

				// Check if any applicable season covers this date but has no boundary for this weekday
				const isDateInSeasonWithNoBoundary = applicableSeasons?.some(season => {
					const seasonStart = DateTime.fromISO(season.from_);
					const seasonEnd = DateTime.fromISO(season.to_);
					const hasMatchingResources = season.resources.some(seasonResource =>
						enabledResources.has(seasonResource.id.toString())
					);

					// Date is within season range and has matching resources
					const dateInRange = date >= seasonStart && date <= seasonEnd && hasMatchingResources;
					// But season has no boundary defined for this weekday
					const noBoundaryForDay = !season.boundaries.some(b => b.wday === dayOfWeek);

					return dateInRange && noBoundaryForDay;
				});

				if (isDateInSeasonWithNoBoundary) {
					// Add full-day closed background for days missing from season boundaries
					backgroundEvents.push({
						start: date.startOf('day').toJSDate(),
						end: date.plus({days: 1}).startOf('day').toJSDate(),
						display: 'background',
						classNames: styles.closedHours,
						extendedProps: {
							closed: true,
							type: 'background',
							source: 'seasonMissingDay'
						}
					});
					continue;
				}

				// If multiple seasons apply to the same day, prioritize the one that starts most recently
				const daysSeason = applicableSeasons.sort((a, b) => {
					const aStart = DateTime.fromISO(a.from_);
					const bStart = DateTime.fromISO(b.from_);
					return bStart.toMillis() - aStart.toMillis(); // Most recent first
				})[0];

				if (!daysSeason) continue;

				const dayBoundaries = daysSeason.boundaries.filter(b => b.wday === dayOfWeek);
				if (dayBoundaries.length === 0) continue;

				// Check if this day's season boundaries differ from the primary season used for businessHours
				const viewMiddle = viewStart.plus({milliseconds: viewEnd.diff(viewStart).milliseconds / 2});
				const primarySeason = seasons.find(season => {
					if (!season.active) return false;
					const seasonStart = DateTime.fromISO(season.from_);
					const seasonEnd = DateTime.fromISO(season.to_);

					// Check resources (from V2)
					const hasMatchingResources = season.resources.some(seasonResource =>
						enabledResources.has(seasonResource.id.toString())
					);

					return viewMiddle >= seasonStart && viewMiddle <= seasonEnd && hasMatchingResources;
				});

				// Only add background events if this day uses a different season than the primary one
				if (daysSeason.id !== primarySeason?.id) {
					const sortedBoundaries = dayBoundaries.sort((a, b) => a.from_.localeCompare(b.from_));

					// Add background for time before first opening
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
								source: `season-${daysSeason.id}-beforeStart`
							}
						});
					}

					// Add background for time after last closing
					const lastBoundary = sortedBoundaries[sortedBoundaries.length - 1];
					const lastBoundaryTo = lastBoundary.to_;
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
								source: `season-${daysSeason.id}-afterHours`
							}
						});
					}
				}
			}
		}

		return backgroundEvents;
	}, [currentDate, seasons, viewStart, viewEnd, enabledResources]);


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
			return <EventContentTemp
				eventInfo={eventInfo as FCEventContentArg<FCallTempEvent>}
			/>
		}

		if (calendarRef.current?.getApi().view.type === 'listWeek') {
			return <EventContentList
				eventInfo={eventInfo as FCEventContentArg<FCallEvent>}
			/>;
		}
		if (eventInfo.event.allDay) {
			return <EventContentAllDay
				eventInfo={eventInfo as FCEventContentArg<FCallEvent>}
			/>;
		}


		return <EventContent
			eventInfo={eventInfo as FCEventContentArg<FCallEvent>}
		/>
	}
	const tempEventArr = useMemo(() => Object.values(storedTempEvents), [storedTempEvents])

	const handleEventClick = useCallback((clickInfo: FCEventClickArg<FCallBaseEvent>) => {
		// Check if the clicked event is a background event
		if ('display' in clickInfo.event && clickInfo.event.display === 'background') {
			// Do not open popper for background events
			return;
		}

		// Check if the event is a valid, interactive event
		if ('id' in clickInfo.event && clickInfo.event.id) {
			selectEvent(clickInfo.event, tempEventArr, clickInfo.el);
		}
	}, [selectEvent, tempEventArr]);

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

		// Check if selection is on a day that's within a season but has no boundary defined
		const selectionDate = selectStart.startOf('day');
		const selectionWeekday = selectionDate.weekday;

		const isSelectionInClosedSeasonDay = seasons?.some(season => {
			const seasonStart = DateTime.fromISO(season.from_).startOf('day');
			const seasonEnd = DateTime.fromISO(season.to_).startOf('day');

			// Check if season has any resources that match enabled resources
			const hasMatchingResources = season.resources.some(seasonResource =>
				enabledResources.has(seasonResource.id.toString())
			);

			// Selection date is within season range and has matching resources
			const dateInRange = season.active &&
				selectionDate >= seasonStart &&
				selectionDate <= seasonEnd &&
				hasMatchingResources;

			// But season has no boundary defined for this weekday
			const noBoundaryForDay = !season.boundaries.some(b => b.wday === selectionWeekday);

			return dateInRange && noBoundaryForDay;
		});

		if (isSelectionInClosedSeasonDay) {
			// Only show toast for actual user selection attempts
			if (span.end && span.start) {
				addToast({
					type: 'info',
					text: t('bookingfrontend.booking_unavailable'),
					autoHide: true,
					messageId: 'season_day_closed'
				});
			}
			return false;
		}

		// Check if selection is within business hours for each specific day
		// Only do this validation if we have seasons and this is a final selection (not just validation calls)
		if (props.seasons && span.start && span.end && span.allDay === false) {
			const selectionStart = DateTime.fromJSDate(span.start);
			const selectionEnd = DateTime.fromJSDate(span.end);

			// Check each day in the selection span
			for (let date = selectionStart.startOf('day'); date <= selectionEnd.startOf('day'); date = date.plus({days: 1})) {
				// Find the season that applies to this specific day
				const daysSeason = props.seasons.find(season => {
					if (!season.active) return false;
					const seasonStart = DateTime.fromISO(season.from_);
					const seasonEnd = DateTime.fromISO(season.to_);
					return date >= seasonStart.startOf('day') && date <= seasonEnd.endOf('day');
				});

				if (!daysSeason) continue; // No season = allow (fallback)

				// Get boundaries for this day's weekday
				const dayOfWeek = date.weekday;
				const dayBoundaries = daysSeason.boundaries.filter(b => b.wday === dayOfWeek);

				if (dayBoundaries.length === 0) continue; // No boundaries = allow (fallback)

				// Check if the selection overlaps with this day
				const dayStart = date.startOf('day');
				const dayEnd = date.endOf('day');
				const selectionStartOnDay = DateTime.max(selectionStart, dayStart);
				const selectionEndOnDay = DateTime.min(selectionEnd, dayEnd);

				if (selectionStartOnDay >= selectionEndOnDay) continue; // No overlap with this day

				// Selection overlaps with this day - check business hours
				let isWithinBusinessHours = false;

				for (const boundary of dayBoundaries) {
					const boundaryStart = date.set({
						hour: parseInt(boundary.from_.split(':')[0]),
						minute: parseInt(boundary.from_.split(':')[1]),
						second: 0
					});
					const boundaryEnd = date.set({
						hour: parseInt(boundary.to_.split(':')[0]),
						minute: parseInt(boundary.to_.split(':')[1]),
						second: 0
					});

					// Check if the selection for this day is within this boundary
					if (selectionStartOnDay >= boundaryStart && selectionEndOnDay <= boundaryEnd) {
						isWithinBusinessHours = true;
						break;
					}
				}

				if (!isWithinBusinessHours) {
					// Outside business hours - only show toast for complete selections
					if (span.end && span.start && selectionEnd.diff(selectionStart).milliseconds > 0) {
						addToast({
							type: 'info',
							text: t('bookingfrontend.outside_opening_hours') || 'Outside opening hours',
							autoHide: true,
							messageId: 'outside_business_hours'
						});
					}
					return false;
				}
			}
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

		console.log('Event resize debug:', {
			eventId: resizeInfo.event.id,
			type: resizeInfo.event.extendedProps?.type,
			isRecurringInstance: resizeInfo.event.extendedProps?.isRecurringInstance,
			hasSource: !!resizeInfo.event.extendedProps?.source,
			allProps: resizeInfo.event.extendedProps
		});
		if (resizeInfo.event.extendedProps?.type === 'temporary' &&
			'applicationId' in resizeInfo.event.extendedProps &&
			!resizeInfo.event.extendedProps?.isRecurringInstance) {
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

		// Handle recurring temp event manipulation
		else if (resizeInfo.event.extendedProps?.type === 'temporary' &&
				 resizeInfo.event.extendedProps?.isRecurringInstance &&
				 resizeInfo.event.extendedProps?.source) {

			const weekOffset = resizeInfo.event.extendedProps._weekOffset || 0;
			const originalApplicationId = resizeInfo.event.extendedProps.applicationId;

			const existingApplication = partials?.list.find(app => +app.id === +originalApplicationId);

			if (!existingApplication || !existingApplication.dates || existingApplication.dates.length === 0) {
				console.warn('Could not find original application for recurring temp event');
				resizeInfo.revert();
				return;
			}

			// Get the new times from the manipulated event
			const newStartDt = DateTime.fromJSDate(newStart);
			const newEndDt = DateTime.fromJSDate(newEnd);

			// Calculate what the original date should be by subtracting the week offset
			const newOriginalStart = newStartDt.minus({ weeks: weekOffset });
			const newOriginalEnd = newEndDt.minus({ weeks: weekOffset });

			console.log('Recurring manipulation debug:', {
				eventId: resizeInfo.event.id,
				weekOffset,
				originalApplicationId,
				manipulatedStart: newStartDt.toISO(),
				manipulatedEnd: newEndDt.toISO(),
				calculatedOriginalStart: newOriginalStart.toISO(),
				calculatedOriginalEnd: newOriginalEnd.toISO(),
				currentOriginalDate: existingApplication.dates[0]
			});

			// Check for overlap before updating
			const span: DateSpanApi = {
				start: newStart,
				end: newEnd,
				allDay: false,
				startStr: newStart.toISOString(),
				endStr: newEnd.toISOString()
			};

			const hasNoOverlap = checkEventOverlap(span, resizeInfo.event as EventImpl);

			if (!hasNoOverlap) {
				resizeInfo.revert();
				return;
			}

			// Update the original application's date
			const originalDate = existingApplication.dates[0];
			const updatedApplication: IUpdatePartialApplication = {
				id: originalApplicationId,
				dates: [{
					...originalDate,
					from_: newOriginalStart.toISO()!,
					to_: newOriginalEnd.toISO()!
				}]
			};

			updateMutation.mutate({id: originalApplicationId, application: updatedApplication});
		}

	}, [partials?.list, updateMutation, checkEventOverlap]);



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
				// Update view dates for season-based calculations
				setViewStart(DateTime.fromJSDate(dateInfo.start));
				setViewEnd(DateTime.fromJSDate(dateInfo.end));
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
				let classNames = '';

				if (event.extendedProps?.type === 'temporary') {
					classNames += `${styles.event} ${styles['event-temporary']}`;
				}

				// Add specific styling for partial applications from shopping cart
				if (event.extendedProps?.isPartialApplication) {
					classNames += ` ${styles['partial-application']}`;

					// Add special styling for recurring instances
					if (event.extendedProps?.isRecurringInstance) {
						classNames += ` ${styles['recurring-instance']}`;
					}
				}

				return classNames.trim();
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