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

const EventPopperActions: FC<EventPopperActionsProps> = (props) => {
	const {event, eventType} = props;
	const t = useTrans();

	return (
		<React.Fragment>
			{/*<Button asChild variant={'tertiary'} data-color={'accent'}>*/}
			{/*	<Link href={'/event/' + event.id} target="_blank"*/}
			{/*		  className={styles.actionButton}>*/}
			{/*		{t(`bookingfrontend.edit ${eventType}`)}*/}
			{/*	</Link>*/}
			{/*</Button>*/}
			<Button asChild variant={'tertiary'} data-color={'accent'}>

				<Link href={phpGWLink('bookingfrontend/', {
					menuaction: 'bookingfrontend.uievent.edit',
					id: event.id,
				}, false)} target="_blank"
					  className={styles.actionButton}>
					{t('bookingfrontend.edit event')}
				</Link>
			</Button>
		</React.Fragment>

	);
}

export default EventPopperActions
