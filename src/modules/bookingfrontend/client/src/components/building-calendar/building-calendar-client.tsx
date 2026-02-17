import React, {Dispatch, FC, useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {DateTime, Settings} from 'luxon'
import FullCalendar from "@fullcalendar/react";
import {IEvent} from "@/service/pecalendar.types";
import {DateSelectArg, DatesSetArg} from "@fullcalendar/core";
import EventPopper from "@/components/building-calendar/modules/event/popper/event-popper";
import CalendarInnerHeader from "@/components/building-calendar/modules/header/calendar-inner-header";
import {
	FCallEvent,
	FCallTempEvent
} from "@/components/building-calendar/building-calendar.types";
import {useCalenderViewMode, useEnabledResources, useTempEvents} from "@/components/building-calendar/calendar-context";
import {IBuilding, Season} from "@/service/types/Building";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import ApplicationCrud from "@/components/building-calendar/modules/event/edit/application-crud";
import {useBookingUser} from "@/service/hooks/api-hooks";
import FullCalendarView from "@/components/building-calendar/views/calendar/full-calendar-view";
import TimeslotView from "@/components/building-calendar/views/timeslots/timeslot-view";
import {isCalendarDeactivated} from "@/service/utils/deactivation-utils";

interface BuildingCalendarProps {
	events?: IEvent[];
	onDateChange: Dispatch<DatesSetArg>
	seasons?: Season[];
	building?: IBuilding;
	buildings?: IBuilding[];
	initialDate: DateTime;
	initialEnabledResources: Set<string>;
	readOnly?: boolean;
}

Settings.defaultLocale = "nb";


const BuildingCalendarClient = React.forwardRef<FullCalendar, BuildingCalendarProps>((props, ref) => {
	const t = useTrans();
	const {events, building, buildings, readOnly = false} = props;
	const [currentDate, setCurrentDate] = useState<DateTime>(props.initialDate);
	const internalRef = useRef<FullCalendar | null>(null);
	const calendarRef = (ref || internalRef) as React.MutableRefObject<FullCalendar | null>;
	const [view, setView] = useState<string>(window.innerWidth < 601 ? 'timeGridDay' : 'timeGridWeek');
	const [lastCalendarView, setLastCalendarView] = useState<string>('timeGridWeek');
	const calendarViewMode = useCalenderViewMode();
	const {enabledResources} = useEnabledResources();
	const {data: bookingUser} = useBookingUser();

	// Determine if we're in organization mode
	const isOrganizationMode = !building && buildings && buildings.length > 0;

	// For organization mode, use the first building as fallback or create a mock building
	const currentBuilding = building;

	const [selectedEvent, setSelectedEvent] = useState<FCallEvent | FCallTempEvent | null>(null);
	const [popperAnchorEl, setPopperAnchorEl] = useState<HTMLElement | null>(null);
	const [currentTempEvent, setCurrentTempEvent] = useState<Partial<FCallTempEvent>>();


	useEffect(() => {
		if (view === 'listWeek' || view === 'listDay') {
			return;
		}
		if (view === lastCalendarView) {
			return;
		}
		setLastCalendarView(view)

	}, [view, lastCalendarView]);

	// Force day view when in calendar mode on mobile
	useEffect(() => {
		if (window.innerWidth < 601 && calendarViewMode === 'calendar' && view !== 'timeGridDay' && view !== 'listWeek' && view !== 'listDay') {
			setView('timeGridDay');
		}
	}, [calendarViewMode, view]);

	// Switch between listDay and listWeek on resize
	useEffect(() => {
		const handleResize = () => {
			const isMobileSize = window.innerWidth < 601;

			// Switch from listDay to listWeek when resizing to desktop
			if (!isMobileSize && view === 'listDay') {
				setView('listWeek');
			}
			// Switch from listWeek to listDay when resizing to mobile
			else if (isMobileSize && view === 'listWeek') {
				setView('listDay');
			}
		};

		window.addEventListener('resize', handleResize);
		return () => window.removeEventListener('resize', handleResize);
	}, [view]);

	// Auto-open ApplicationCrud when user logs in with pending recurring data
	useEffect(() => {
		if (bookingUser && currentBuilding && !currentTempEvent && !readOnly && !isOrganizationMode) {
			const pendingData = localStorage.getItem('pendingRecurringApplication');
			if (pendingData) {
				try {
					const storedData = JSON.parse(pendingData);

					// Check if data is expired (10 minutes = 600000 ms)
					const isExpired = storedData.timestamp && (Date.now() - storedData.timestamp > 600000);

					if (isExpired) {
						localStorage.removeItem('pendingRecurringApplication');
						return;
					}

					// Check if this matches the current building context AND is for a NEW application (no applicationId)
					if (storedData.building_id && +storedData.building_id === +currentBuilding.id && !storedData.applicationId) {
						// Create a temp event to trigger the ApplicationCrud
						const tempEvent: Partial<FCallTempEvent> = {
							id: `temp-${Date.now()}`,
							title: storedData.title || t('bookingfrontend.new application'),
							start: storedData.start ? new Date(storedData.start) : new Date(),
							end: storedData.end ? new Date(storedData.end) : new Date(),
							allDay: false,
							editable: true,
							extendedProps: {
								type: 'temporary',
								resources: storedData.resources || [...enabledResources],
								building_id: currentBuilding.id,
								restorePendingData: true // Flag to indicate this should restore data
							}
						};

						setCurrentTempEvent(tempEvent);
					}
				} catch (error) {
					console.error('Error parsing pending recurring application data:', error);
					localStorage.removeItem('pendingRecurringApplication');
				}
			}
		}
	}, [bookingUser, currentBuilding, currentTempEvent, readOnly, isOrganizationMode, enabledResources, t]);


	const selectEvent = useCallback((event: FCallEvent | FCallTempEvent, storedTempEvents?: FCallTempEvent[], targetEl?: HTMLElement) => {
		if (event.extendedProps.type === 'temporary') {
			console.log(event, storedTempEvents)
			let tempEv;
			if(event.extendedProps.isRecurringInstance) {
				const appId = event.extendedProps.applicationId!;
				tempEv = storedTempEvents ? storedTempEvents.find(a => +a.id === +appId) : event;
			}

			setCurrentTempEvent((tempEv || event) as FCallTempEvent);
		} else {
			if (!targetEl) {
				throw new Error("No selected target element")
			}
			setSelectedEvent(event);
			setPopperAnchorEl(targetEl);
		}
	}, []);


	const currentViewType = calendarRef.current?.getApi().view.type;
	const popperPlacement = useMemo(() => {
		switch (currentViewType) {
			case 'timeGridDay':
				return 'bottom-start';
			case 'listWeek':
			case 'listDay':
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
	}, [currentViewType, popperAnchorEl]);


	useEffect(() => {
		calendarRef?.current?.getApi().changeView(view)
	}, [view, calendarRef]);


	const handleDateSelect = useCallback((selectInfo?: Partial<DateSelectArg>) => {
		// Prevent date selection if calendar is deactivated or in read-only mode
		if (readOnly || (currentBuilding?.deactivate_calendar)) {
			return;
		}

		// Prevent date selection if no resources are enabled
		if (enabledResources.size === 0) {
			return;
		}

		if (selectInfo?.view?.type === 'dayGridMonth') {
			return;
		}

		// Prevent creating events in the past
		if (selectInfo?.start && DateTime.fromJSDate(selectInfo.start) < DateTime.now()) {
			return;
		}

		// Don't allow creating events in organization mode
		if (isOrganizationMode) {
			return;
		}

		const title = t('bookingfrontend.new application');

		// Determine the start date/time
		let startDate: Date | undefined;
		let endDate: Date | undefined;

		if (selectInfo?.start && selectInfo?.end) {
			// User clicked/dragged on the calendar
			startDate = selectInfo.start;
			endDate = selectInfo.end;
		} else {
			// User clicked "New Application" button - use currentDate or next future day
			const now = DateTime.now();
			let baseDate = currentDate;

			// If currentDate is in the past, use tomorrow
			if (baseDate < now.startOf('day')) {
				baseDate = now.plus({ days: 1 }).startOf('day');
			}

			// Set default time to 13:00 (1:00 PM)
			const startTime = baseDate.set({ hour: 13, minute: 0, second: 0, millisecond: 0 });
			const endTime = startTime.plus({ hours: 1 }); // Default 1 hour duration (13:00-14:00)

			startDate = startTime.toJSDate();
			endDate = endTime.toJSDate();
		}

		const newEvent: FCallTempEvent = {
			id: `temp-${Date.now()}`,
			title,
			start: startDate,
			end: endDate,
			allDay: selectInfo?.allDay ?? false,
			editable: true,
			extendedProps: {
				type: 'temporary',
				resources: [...enabledResources],
				building_id: currentBuilding?.id || 0,
			},
		};
		selectEvent(newEvent);
		selectInfo?.view?.calendar.unselect(); // Clear selection
	}, [t, enabledResources, currentBuilding?.id, currentBuilding?.deactivate_calendar, readOnly, isOrganizationMode, selectEvent, currentDate]);

	return (
		<React.Fragment>
			{/*<DebugInfo*/}
			{/*	currentDate={currentDate}*/}
			{/*	seasons={props.seasons}*/}
			{/*	view={view}*/}
			{/*/>*/}
			<CalendarInnerHeader view={view} calendarRef={calendarRef}
								 setView={(v) => setView(v)}
								 currentDate={currentDate} setCurrentDate={setCurrentDate}
								 setLastCalendarView={() => setView(lastCalendarView)} building={currentBuilding}
								 createNew={readOnly || isOrganizationMode ? undefined : () => handleDateSelect()}/>
			{calendarViewMode === 'calendar' && !currentBuilding?.deactivate_calendar && (
				<FullCalendarView
					calendarRef={calendarRef}
					viewMode={view}
					selectEvent={selectEvent}
					events={events}
					setViewMode={setView}
					setCurrentDate={setCurrentDate}
					currentDate={currentDate}
					seasons={props.seasons}
					currentTempEvent={currentTempEvent}
					onDateChange={props.onDateChange}
					handleDateSelect={readOnly || isOrganizationMode ? undefined : handleDateSelect}
				/>

			)}
			{calendarViewMode === 'calendar' && currentBuilding?.deactivate_calendar && (
				<div style={{
					padding: '2rem',
					textAlign: 'center',
					color: '#666',
					fontSize: '1.1rem',
					border: '1px solid #e0e0e0',
					borderRadius: '8px',
					backgroundColor: '#f9f9f9',
					margin: '1rem 0',
					gridArea: 'calendar-body'
				}}>
					{t('bookingfrontend.calendar_view_disabled')}
				</div>
			)}
			{calendarViewMode === 'timeslots' && currentBuilding && (
				<TimeslotView
					viewMode={view}
					currentDate={currentDate}
					building={currentBuilding}
				/>
			)}

			<EventPopper
				event={selectedEvent}
				placement={
					popperPlacement
				}
				anchor={popperAnchorEl} onClose={() => {
				setSelectedEvent(null);
				setPopperAnchorEl(null);
			}}/>

			{!readOnly && !isOrganizationMode && currentBuilding && (
				<ApplicationCrud onClose={() => setCurrentTempEvent(undefined)}
								 selectedTempApplication={currentTempEvent}
								 building_id={currentBuilding.id}/>
			)}


		</React.Fragment>
	);
});

BuildingCalendarClient.displayName = 'BuildingCalendarClient';

export default BuildingCalendarClient;



