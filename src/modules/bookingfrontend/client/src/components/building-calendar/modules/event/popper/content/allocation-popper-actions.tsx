import React, {FC} from 'react';
import {IAPIAllocation} from "@/service/pecalendar.types";
import {IBookingUser} from "@/service/types/api.types";
import Link from "next/link";
import styles from "@/components/building-calendar/modules/event/popper/event-popper.module.scss";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {isFutureDate, phpGWLink} from "@/service/util";
import {DateTime} from "luxon";
import {PlusIcon} from "@navikt/aksel-icons";
import {Button} from "@digdir/designsystemet-react";

interface AllocationPopperActionsProps {
	allocation: IAPIAllocation;
	user: IBookingUser;
}

/**
 * Actions for the Allocation card, design direction 1a.
 *
 * The design carries INFO + LOW-RISK ACTIONS ONLY: the heavy operations (edit
 * time, edit resources, move, cancel) all move into the 1c management modal,
 * reached through the single primary "Manage allocation" button. Until 1c is
 * built that button targets the legacy edit page, which is where those
 * operations live today.
 */
const AllocationPopperActions: FC<AllocationPopperActionsProps> = (props) => {
	const {allocation} = props;
	const t = useTrans();
	const fromUnix = Date.parse(allocation.from_) / 1000;
	const toUnix = Date.parse(allocation.to_) / 1000;
	const isInFuture = isFutureDate(DateTime.fromISO(allocation.from_));

	return (
		<React.Fragment>
			{/* design: sc-if showQuickBooking */}
			{isInFuture && (
				<Button asChild variant={'tertiary'} data-color={'accent'}>
					<Link href={phpGWLink('bookingfrontend/', {
						menuaction: 'bookingfrontend.uibooking.add',
						allocation_id: allocation.id,
						from_: fromUnix,
						to_: toUnix,
						resource_ids: allocation.resources.map(a => a.id),
					}, false)} target="_blank"
						  className={styles.actionButton}>
						<PlusIcon/>
						{t('bookingfrontend.create new booking')}
					</Link>
				</Button>
			)}
			{/* design: sc-if showManage -> {{ manageLabel }}, the primary action */}
			<Button asChild variant={'primary'} data-color={'accent'}>
				<Link href={phpGWLink('bookingfrontend/', {
					menuaction: 'bookingfrontend.uiallocation.edit',
					allocation_id: allocation.id,
				}, false)} target="_blank"
					  className={styles.actionButton}>
					{t('bookingfrontend.manage_allocation')}
				</Link>
			</Button>
		</React.Fragment>
	);
}

export default AllocationPopperActions
