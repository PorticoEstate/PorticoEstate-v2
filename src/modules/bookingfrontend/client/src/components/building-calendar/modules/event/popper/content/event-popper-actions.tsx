import React, {FC, useState} from 'react';
import {IAPIEvent} from "@/service/pecalendar.types";
import styles from "@/components/building-calendar/modules/event/popper/event-popper.module.scss";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {Button} from "@digdir/designsystemet-react";
import EventManageModal from "@/components/building-calendar/modules/event/manage/event-manage-modal";

interface EventPopperActionsProps {
	event: IAPIEvent;
	eventType: string;
}

/**
 * Actions for the Event card, design direction 1a — info + low-risk actions only.
 * Cancel lives in the 1c management modal behind the single primary
 * "Manage event" button. That modal now exists, so the button opens it here
 * rather than the legacy edit page.
 */
const EventPopperActions: FC<EventPopperActionsProps> = (props) => {
	const {event} = props;
	const t = useTrans();
	const [manageOpen, setManageOpen] = useState<boolean>(false);

	return (
		<React.Fragment>
			<Button
				variant={'primary'}
				data-color={'accent'}
				className={styles.actionButton}
				onClick={() => setManageOpen(true)}
			>
				{t('bookingfrontend.manage_event')}
			</Button>
			<EventManageModal
				event={event}
				open={manageOpen}
				onClose={() => setManageOpen(false)}
			/>
		</React.Fragment>
	);
}

export default EventPopperActions
