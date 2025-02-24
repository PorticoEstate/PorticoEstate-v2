import React, {FC} from 'react';
import {IAPIAllocation} from "@/service/pecalendar.types";
import {IBookingUser} from "@/service/types/api.types";
import Link from "next/link";
import styles from "@/components/building-calendar/modules/event/popper/event-popper.module.scss";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {isFutureDate, phpGWLink} from "@/service/util";
import {useServerSettings} from "@/service/hooks/api-hooks";
import {DateTime} from "luxon";
import {PlusIcon} from "@navikt/aksel-icons";
import {Button} from "@digdir/designsystemet-react";

interface AllocationPopperActionsProps {
	allocation: IAPIAllocation;
	user: IBookingUser;
}

const AllocationPopperActions: FC<AllocationPopperActionsProps> = (props) => {
	const {allocation, user} = props;
	const t = useTrans();
	const {data: serverSettings} = useServerSettings();
	const fromUnix = Date.parse(allocation.from_) / 1000;
	const toUnix = Date.parse(allocation.to_) / 1000;
	const isInFuture = isFutureDate(DateTime.fromISO(allocation.from_));
	return (
		<React.Fragment>
			<Button asChild variant={'tertiary'} data-color={'accent'}>

				<Link href={phpGWLink('bookingfrontend/', {
					menuaction: 'bookingfrontend.uibooking.add',
					allocation_id: allocation.id,
					from_: fromUnix,
					to_: toUnix,
					resource_ids: allocation.resources.map(a => a.id),
				}, false)} target="_blank"
					  className={styles.actionButton}>
					<PlusIcon></PlusIcon>
					{t(`bookingfrontend.create new booking`)}
				</Link>
			</Button>
			{isInFuture && (
				<React.Fragment>
					{serverSettings?.booking_config?.user_can_delete_allocations && (
						<Button asChild variant={'tertiary'} data-color={'accent'}>

							<Link href={phpGWLink('bookingfrontend/', {
								menuaction: 'bookingfrontend.uiallocation.cancel',
								allocation_id: allocation.id,
								from_: fromUnix,
								to_: toUnix,
								resource_ids: allocation.resources.map(a => a.id),
							}, false)} target="_blank"
								  className={styles.actionButton}>
								{t('bookingfrontend.cancel allocation')}
							</Link>
						</Button>
					)}
					<Button asChild variant={'tertiary'} data-color={'accent'}>

						<Link href={phpGWLink('bookingfrontend/', {
							menuaction: 'bookingfrontend.uiallocation.edit',
							allocation_id: allocation.id,
						}, false)} target="_blank"
							  className={styles.actionButton}>
							{t('bookingfrontend.edit allocation')}
						</Link>
					</Button>
				</React.Fragment>)}

		</React.Fragment>

	);
}

export default AllocationPopperActions


