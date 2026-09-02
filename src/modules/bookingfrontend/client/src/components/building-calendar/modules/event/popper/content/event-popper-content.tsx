'use client'
import React, {FC, useCallback, useMemo, useRef, useState} from 'react';
import {FCallEvent} from "@/components/building-calendar/building-calendar.types";
import styles from "@/components/building-calendar/modules/event/popper/event-popper.module.scss";
import {formatEventTime, phpGWLink} from "@/service/util";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {Button, Paragraph, Tooltip} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import PopperContentSharedWrapper
	from "@/components/building-calendar/modules/event/popper/content/popper-content-shared-wrapper";
import Link from "next/link";
import {useBookingUser, useServerSettings} from "@/service/hooks/api-hooks";
import {IEventIsAPIAllocation, IEventIsAPIBooking, IEventIsAPIEvent} from "@/service/pecalendar.types";
import AllocationPopperActions
	from "@/components/building-calendar/modules/event/popper/content/allocation-popper-actions";
import EventPopperActions
	from "@/components/building-calendar/modules/event/popper/content/event-popper-actions";
import BookingPopperActions
	from "@/components/building-calendar/modules/event/popper/content/booking-popper-actions";
import {isOrgAdmin} from "@/components/building-calendar/util/event-converter";

interface EventPopperContentProps {
	event: FCallEvent
	onClose: () => void;
}

/**
 * The calendar popper card — design direction 1a ("Compact", 300 px, glance-first).
 *
 * Layout, per the design: an entity badge carrying the type's colour dot plus the
 * id, the title beneath it, a two-column label/value grid, the resource list, a
 * divider, then a stack of actions ending in one primary "Manage …" button.
 *
 * Three cells the design draws are NOT rendered, because no field on this payload
 * carries them and this seat does not invent data: the allocation's "Series"
 * (weekly-until) and "Season" NAME (only season_id is served), and the
 * "Can be cancelled until …" footnote (Resource::$cancellation_deadline_value /
 * _unit are @Expose but not @Short, and resources[] is serialised @SerializeAs(
 * of=Resource, short=true), so they are structurally unreachable here).
 */
const EventPopperContent: FC<EventPopperContentProps> = (props) => {
	const {event} = props
	const t = useTrans();
	const eventData = event.extendedProps.source;
	const serverSettings = useServerSettings();
	const {data: user} = useBookingUser();

	const userHasAccess = useMemo(() => {
		if (!user) {
			return false;
		}

		if (IEventIsAPIEvent(eventData)) {
			const ssn = user.ssn;
			if (eventData.customer_identifier_type === 'ssn') {
				if (eventData.customer_ssn === ssn) {
					return true;
				}
			}
		}
		return isOrgAdmin(user, eventData)
	}, [user, eventData]);

	// Defect A follow-up (#19569): the tooltip must only attach when the title
	// actually overflows, and it must stay correct across a live resize — the
	// popper card's width can change without a remount, so a mount-only
	// measurement would go stale. A ResizeObserver on the h3 itself (not a
	// window resize listener) reacts to whatever caused ITS box to change.
	const [isTitleTruncated, setIsTitleTruncated] = useState(false);
	const titleResizeObserver = useRef<ResizeObserver | null>(null);
	const measureTitleTruncation = useCallback((el: HTMLHeadingElement) => {
		setIsTitleTruncated(el.scrollWidth > el.clientWidth);
	}, []);
	const eventNameRef = useCallback((el: HTMLHeadingElement | null) => {
		titleResizeObserver.current?.disconnect();
		titleResizeObserver.current = null;
		if (el) {
			measureTitleTruncation(el);
			titleResizeObserver.current = new ResizeObserver(() => measureTitleTruncation(el));
			titleResizeObserver.current.observe(el);
		}
	}, [measureTitleTruncation]);

	const showLink = useMemo(() => {
		let participant_limit = 0;
		if (IEventIsAPIEvent(eventData)) {
			participant_limit = eventData.participant_limit || 0;
		}

		const resWithParticipants = eventData.resources.find(a => (a.participant_limit || 0) > 0)
		if (!participant_limit && resWithParticipants) {
			participant_limit = (resWithParticipants?.participant_limit || 0);
		}
		if (!participant_limit) {
			participant_limit = (serverSettings.data?.booking_config?.participant_limit || 0);
		}
		if (participant_limit > 0) {
			return `bookingfrontend.ui${eventData.type}.show`
		}
	}, [eventData, serverSettings])

	const specRows: { label: string, value: React.ReactNode }[] = [
		{label: t('booking.date and time'), value: formatEventTime(event)},
	];
	if (IEventIsAPIEvent(eventData) && eventData.organizer) {
		specRows.push({label: t('booking.organizer'), value: eventData.organizer});
	}
	if (IEventIsAPIEvent(eventData) && (eventData.participant_limit || 0) > 0) {
		specRows.push({
			label: t('bookingfrontend.participants'),
			value: t('bookingfrontend.max_participants', {count: eventData.participant_limit || 0})
		});
	}
	if (IEventIsAPIBooking(eventData) && eventData.activity_name) {
		specRows.push({label: t('bookingfrontend.activity'), value: eventData.activity_name});
	}
	if (IEventIsAPIBooking(eventData) && !!eventData.allocation_id) {
		specRows.push({
			label: t('bookingfrontend.allocation'),
			value: `#${eventData.allocation_id}`
		});
	}

	return (
		<PopperContentSharedWrapper onClose={props.onClose} header={true}
									headerContent={
										<div className={styles.headerHeading}>
											<div className={styles.entityLine}>
												{/* the design's badge dot carries the ENTITY TYPE's colour,
												    not a resource colour */}
												<span className={styles.entityBadge}
													  data-entity={eventData.type}>
													<span className={styles.entityDot}/>
													{t('bookingfrontend.' + eventData.type)}
												</span>
												<span className={styles.entityId}>#{event.id}</span>
											</div>
											{(() => {
												const titleHeading = (
													<h3
														ref={eventNameRef}
														className={styles.eventName}
														tabIndex={isTitleTruncated ? 0 : undefined}
													>
														{event.title}
													</h3>
												);
												return isTitleTruncated ? (
													<Tooltip content={event.title}>{titleHeading}</Tooltip>
												) : titleHeading;
											})()}
										</div>
									}
		>
			<div className={styles.eventPopperContent}>
				<div className={styles.specList}>
					{specRows.map((row, i) => (
						<div className={styles.specItem} key={i}>
							<span className={styles.specTitle}>{row.label}</span>
							<Paragraph className={styles.specContent} data-size={'sm'}>{row.value}</Paragraph>
						</div>
					))}
				</div>

				<div className={styles.resourcesList}>
					{eventData.resources?.map((resource, index: number) => (
						<div key={index} className={styles.resourceItem}>
							<ColourCircle resourceId={resource.id} size={'medium'}/>
							<span className={styles.resourceName}>{resource.name}</span>
						</div>
					))}
				</div>
			</div>

			<div className={styles.popperDivider}/>

			<div className={styles.eventPopperActions}>
				{showLink && (
					<Button asChild variant={'tertiary'} data-color={'accent'}>
						<Link href={phpGWLink('bookingfrontend/', {
							menuaction: showLink,
							id: eventData.id,
						}, false)} target="_blank"
							  className={styles.actionButton}>
							{t('booking.register participants')}
						</Link>
					</Button>
				)}
				{IEventIsAPIAllocation(eventData) && userHasAccess && user && (
					<AllocationPopperActions allocation={eventData} user={user}/>
				)}
				{IEventIsAPIEvent(eventData) && userHasAccess && (
					<EventPopperActions event={eventData} eventType={event.extendedProps.type}/>
				)}
				{IEventIsAPIBooking(eventData) && userHasAccess && (
					<BookingPopperActions booking={eventData}/>
				)}

				{/*
				  * The design's `sc-if {{ isPublic }}` on the allocation card. An
				  * allocation has no is_public field — it is declared on Event only —
				  * so per henning's ruling this keys off what the client ALREADY
				  * computes, the viewer-permission signal, and not off any new
				  * entity-visibility field. Logged out, isOrgAdmin returns false at
				  * its !user guard, which is exactly the state the design draws.
				  */}
				{IEventIsAPIAllocation(eventData) && !userHasAccess && (
					<span className={styles.popperNotice}>
						{t('bookingfrontend.log_in_as_org_admin_to_manage_allocation')}
					</span>
				)}
				{IEventIsAPIBooking(eventData) && !!eventData.allocation_id && (
					<span className={styles.popperNotice}>
						{t('bookingfrontend.cancelling_booking_frees_hour_back_to_allocation')}
					</span>
				)}
			</div>
		</PopperContentSharedWrapper>
	);
}

export default EventPopperContent
