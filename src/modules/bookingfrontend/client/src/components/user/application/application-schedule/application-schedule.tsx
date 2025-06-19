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
						 style={{
							 backgroundColor: index % 2 === 0 ? 'transparent' : 'var(--ds-color-background-tinted)',
							 padding: '0.5rem',
							 borderRadius: '4px',
							 display: 'flex',
							 alignItems: 'center',
							 justifyContent: 'space-between'
						 }}>
						<div 
							onClick={(e) => handleEventClick(ev, e.currentTarget)}
							style={{
								cursor: 'pointer',
								flex: 1
							}}>
							<EventContentList eventInfo={ev}/>
						</div>
						
						{/* Edit/Cancel Links */}
						{(ev.edit_link || ev.cancel_link) && (
							<div style={{
								display: 'flex',
								gap: '0.5rem',
								marginLeft: '1rem'
							}}>
								{ev.edit_link && (
									<a 
										href={ev.edit_link}
										style={{
											color: 'var(--ds-color-foreground-action)',
											textDecoration: 'none',
											fontSize: '0.875rem',
											fontWeight: '500'
										}}
										onMouseEnter={(e) => {
											e.currentTarget.style.textDecoration = 'underline';
										}}
										onMouseLeave={(e) => {
											e.currentTarget.style.textDecoration = 'none';
										}}
									>
										{t('bookingfrontend.edit')}
									</a>
								)}
								{ev.cancel_link && (
									<a 
										href={ev.cancel_link}
										style={{
											color: 'var(--ds-color-foreground-danger)',
											textDecoration: 'none',
											fontSize: '0.875rem',
											fontWeight: '500'
										}}
										onMouseEnter={(e) => {
											e.currentTarget.style.textDecoration = 'underline';
										}}
										onMouseLeave={(e) => {
											e.currentTarget.style.textDecoration = 'none';
										}}
									>
										{t('bookingfrontend.cancel')}
									</a>
								)}
							</div>
						)}
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


