import React, {FC, useMemo} from 'react';
import {Paragraph, Spinner} from "@digdir/designsystemet-react";
import {IEvent} from "@/service/pecalendar.types";
import {EventContentList} from "@/components/building-calendar/modules/event/content";
import {useApplicationScheduleEntities} from "@/service/hooks/api-hooks";
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import EventPopper from "@/components/building-calendar/modules/event/popper/event-popper";

interface ApplicationScheduleProps {
	applicationId: number
	secret?: string
}

const ApplicationSchedule: FC<ApplicationScheduleProps> = (props) => {
	const t = useTrans();
	const {data: eventsAllocationsBookings, isLoading: eventsLoading} = useApplicationScheduleEntities(
		props.applicationId,
		props.secret || undefined
	);
	// Event popper state
	const [selectedEvent, setSelectedEvent] = React.useState<any>(null);
	const [popperAnchor, setPopperAnchor] = React.useState<HTMLElement | null>(null);


	const applicationSchedule: IEvent[] = useMemo(() => [
		...(eventsAllocationsBookings?.events || []),
		...(eventsAllocationsBookings?.allocations || []),
		...(eventsAllocationsBookings?.bookings || [])
	], [eventsAllocationsBookings])

	const handleEventClick = (event: any, element: HTMLElement) => {
		setSelectedEvent(event);
		setPopperAnchor(element);
	};

	const handleClosePopper = () => {
		setSelectedEvent(null);
		setPopperAnchor(null);
	};


	if (eventsLoading) {
		return (<div style={{display: 'flex', justifyContent: 'center', padding: '1rem'}}>
			<Spinner data-size="sm" aria-label={t('common.loading')}/>
		</div>)
	}
	if (applicationSchedule.length === 0) return (
		<Paragraph>{t('bookingfrontend.no information available')}</Paragraph>)

	return (
		<div>
			<div style={{marginBottom: '1rem'}}>
				{applicationSchedule.map((ev, index) => (
					<div key={ev.id}
						 onClick={(e) => handleEventClick(ev, e.currentTarget)}
						 style={{
							 cursor: 'pointer',
							 backgroundColor: index % 2 === 0 ? 'transparent' : 'var(--ds-color-background-tinted)',
							 padding: '0.5rem',
							 borderRadius: '4px'
						 }}>
						<EventContentList eventInfo={ev}/>
					</div>
				))}
			</div>

			{/* Event Popper */}
			{selectedEvent && (
				<EventPopper
					event={{
						id: selectedEvent.id,
						title: 'name' in selectedEvent ? selectedEvent.name : selectedEvent.building_name,
						start: new Date(selectedEvent.from_),
						end: new Date(selectedEvent.to_),
						className: [],
						extendedProps: {
							actualStart: new Date(selectedEvent.from_),
							actualEnd: new Date(selectedEvent.to_),
							isExtended: false,
							source: selectedEvent,
							type: selectedEvent.type
						}
					}}
					onClose={handleClosePopper}
					anchor={popperAnchor}
					placement="bottom-start"
				/>
			)}
		</div>
	);
}

export default ApplicationSchedule


