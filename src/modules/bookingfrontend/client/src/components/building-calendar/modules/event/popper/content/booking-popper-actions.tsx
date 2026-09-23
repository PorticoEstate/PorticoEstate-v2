import React, {FC, useState} from 'react';
import {IAPIBooking} from "@/service/pecalendar.types";
import styles from "@/components/building-calendar/modules/event/popper/event-popper.module.scss";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {Button} from "@digdir/designsystemet-react";
import BookingManageModal from "@/components/building-calendar/modules/event/manage/booking-manage-modal";

interface BookingPopperActionsProps {
	booking: IAPIBooking;
}

/**
 * Actions for the Booking card, design direction 1a — info + low-risk actions only.
 *
 * New construction: before this change nothing in the client branched on a booking
 * at all (IEventIsAPIBooking had zero call sites). Cancel lives in the 1c
 * management modal behind the single primary "Manage booking" button. That modal
 * now exists, so the button opens it here rather than the legacy edit page.
 */
const BookingPopperActions: FC<BookingPopperActionsProps> = (props) => {
	const {booking} = props;
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
				{t('bookingfrontend.manage_booking')}
			</Button>
			<BookingManageModal
				booking={booking}
				open={manageOpen}
				onClose={() => setManageOpen(false)}
			/>
		</React.Fragment>
	);
}

export default BookingPopperActions
