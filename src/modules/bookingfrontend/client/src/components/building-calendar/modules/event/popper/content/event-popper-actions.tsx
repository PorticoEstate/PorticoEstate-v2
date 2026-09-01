import React, {FC} from 'react';
import {IAPIEvent} from "@/service/pecalendar.types";
import Link from "next/link";
import styles from "@/components/building-calendar/modules/event/popper/event-popper.module.scss";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {Button} from "@digdir/designsystemet-react";
import {phpGWLink} from "@/service/util";

interface EventPopperActionsProps {
	event: IAPIEvent;
	eventType: string;
}

/**
 * Actions for the Event card, design direction 1a — info + low-risk actions only.
 * Cancel moves into the 1c management modal behind the single primary
 * "Manage event" button; until 1c exists that button targets the legacy edit page.
 */
const EventPopperActions: FC<EventPopperActionsProps> = (props) => {
	const {event} = props;
	const t = useTrans();

	return (
		<Button asChild variant={'primary'} data-color={'accent'}>
			<Link href={phpGWLink('bookingfrontend/', {
				menuaction: 'bookingfrontend.uievent.edit',
				id: event.id,
				resource_ids: event.resources.map(a => a.id),
			}, false)} target="_blank"
				  className={styles.actionButton}>
				{t('bookingfrontend.manage_event')}
			</Link>
		</Button>
	);
}

export default EventPopperActions
