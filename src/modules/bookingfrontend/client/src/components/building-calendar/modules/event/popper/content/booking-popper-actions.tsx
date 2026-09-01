import React, {FC} from 'react';
import {IAPIBooking} from "@/service/pecalendar.types";
import Link from "next/link";
import styles from "@/components/building-calendar/modules/event/popper/event-popper.module.scss";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {Button} from "@digdir/designsystemet-react";
import {phpGWLink} from "@/service/util";

interface BookingPopperActionsProps {
	booking: IAPIBooking;
}

/**
 * Actions for the Booking card, design direction 1a — info + low-risk actions only.
 *
 * New construction: before this change nothing in the client branched on a booking
 * at all (IEventIsAPIBooking had zero call sites). Cancel lives in the 1c
 * management modal behind the single primary "Manage booking" button; until 1c
 * exists that button targets the legacy edit page.
 */
const BookingPopperActions: FC<BookingPopperActionsProps> = (props) => {
	const {booking} = props;
	const t = useTrans();

	return (
		<Button asChild variant={'primary'} data-color={'accent'}>
			<Link href={phpGWLink('bookingfrontend/', {
				menuaction: 'bookingfrontend.uibooking.edit',
				id: booking.id,
				resource_ids: booking.resources.map(a => a.id),
			}, false)} target="_blank"
				  className={styles.actionButton}>
				{t('bookingfrontend.manage_booking')}
			</Link>
		</Button>
	);
}

export default BookingPopperActions
