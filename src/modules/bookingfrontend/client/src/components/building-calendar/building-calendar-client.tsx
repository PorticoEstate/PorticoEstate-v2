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
import {useCalenderViewMode, useEnabledResources} from "@/components/building-calendar/calendar-context";
import {IBuilding, Season} from "@/service/types/Building";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import ApplicationCrud from "@/components/building-calendar/modules/event/edit/application-crud";
import FullCalendarView from "@/components/building-calendar/views/calendar/full-calendar-view";
import TimeslotView from "@/components/building-calendar/views/timeslots/timeslot-view";
import {isCalendarDeactivated} from "@/service/utils/deactivation-utils";

interface BuildingCalendarProps {
	events?: IEvent[];
	onDateChange: Dispatch<DatesSetArg>
	seasons: Season[];
	building: IBuilding;
	initialDate: DateTime;
	initialEnabledResources: Set<string>;
}

Settings.defaultLocale = "nb";


const BuildingCalendarClient = React.forwardRef<FullCalendar, BuildingCalendarProps>((props, ref) => {
	const t = useTrans();
	const {events} = props;
	const [currentDate, setCurrentDate] = useState<DateTime>(props.initialDate);
	const internalRef = useRef<FullCalendar | null>(null);
	const calendarRef = (ref || internalRef) as React.MutableRefObject<FullCalendar | null>;
	const [view, setView] = useState<string>(window.innerWidth < 601 ? 'timeGridDay' : 'timeGridWeek');
	const [lastCalendarView, setLastCalendarView] = useState<string>('timeGridWeek');
	const calendarViewMode = useCalenderViewMode();
	const {enabledResources} = useEnabledResources();

	const [selectedEvent, setSelectedEvent] = useState<FCallEvent | FCallTempEvent | null>(null);
	const [popperAnchorEl, setPopperAnchorEl] = useState<HTMLElement | null>(null);
	const [currentTempEvent, setCurrentTempEvent] = useState<Partial<FCallTempEvent>>();


	useEffect(() => {
		if (view === 'listWeek') {
			return;
		}
		if (view === lastCalendarView) {
			return;
		}
		setLastCalendarView(view)

	}, [view, lastCalendarView]);

	// Force day view when in calendar mode on mobile
	useEffect(() => {
		if (window.innerWidth < 601 && calendarViewMode === 'calendar' && view !== 'timeGridDay' && view !== 'listWeek') {
			setView('timeGridDay');
		}
	}, [calendarViewMode, view]);


	const selectEvent = useCallback((event: FCallEvent | FCallTempEvent, targetEl?: HTMLElement) => {
		if (event.extendedProps.type === 'temporary') {
			setCurrentTempEvent(event as FCallTempEvent);
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
		// Prevent date selection if calendar is deactivated
		if (props.building.deactivate_calendar) {
			return;
		}

		if (selectInfo?.view?.type === 'dayGridMonth') {
			return;
		}

		// Prevent creating events in the past
		if (selectInfo?.start && DateTime.fromJSDate(selectInfo.start) < DateTime.now()) {
			return;
		}

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
		selectEvent(newEvent, undefined);
		selectInfo?.view?.calendar.unselect(); // Clear selection
	}, [t, enabledResources, props.building.id, props.building.deactivate_calendar, selectEvent]);

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
								 setLastCalendarView={() => setView(lastCalendarView)} building={props.building}
								 createNew={() => handleDateSelect()}/>
			{calendarViewMode === 'calendar' && !props.building.deactivate_calendar && (
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
					handleDateSelect={handleDateSelect}
				/>

			)}
			{calendarViewMode === 'calendar' && props.building.deactivate_calendar && (
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
			{calendarViewMode === 'timeslots' && (
				<TimeslotView
					viewMode={view}
					currentDate={currentDate}
					building={props.building}
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

			<ApplicationCrud onClose={() => setCurrentTempEvent(undefined)} selectedTempApplication={currentTempEvent}
							 building_id={props.building.id}/>


		</React.Fragment>
	);
});

BuildingCalendarClient.displayName = 'BuildingCalendarClient';

export default BuildingCalendarClient;



