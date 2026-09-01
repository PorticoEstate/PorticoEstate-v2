import React, {FC, useState} from 'react';
import {IAPIAllocation} from "@/service/pecalendar.types";
import {IBookingUser} from "@/service/types/api.types";
import Link from "next/link";
import styles from "@/components/building-calendar/modules/event/popper/event-popper.module.scss";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {isFutureDate, phpGWLink} from "@/service/util";
import {DateTime} from "luxon";
import {PlusIcon} from "@navikt/aksel-icons";
import {Button} from "@digdir/designsystemet-react";
import AllocationManageModal from "@/components/building-calendar/modules/event/manage/allocation-manage-modal";

interface AllocationPopperActionsProps {
	allocation: IAPIAllocation;
	user: IBookingUser;
}

/**
 * Actions for the Allocation card, design direction 1a.
 *
 * The design carries INFO + LOW-RISK ACTIONS ONLY: the heavy operations (edit
 * time, edit resources, move, cancel) all move into the 1c management modal,
 * reached through the single primary "Manage allocation" button. That modal now
 * exists, so the button opens it here rather than the legacy edit page.
 */
const AllocationPopperActions: FC<AllocationPopperActionsProps> = (props) => {
	const {allocation} = props;
	const t = useTrans();
	const [manageOpen, setManageOpen] = useState<boolean>(false);
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
			{/* design: sc-if showManage -> {{ manageLabel }}, the primary action, opening 1c */}
			<Button
				variant={'primary'}
				data-color={'accent'}
				className={styles.actionButton}
				onClick={() => setManageOpen(true)}
			>
				{t('bookingfrontend.manage_allocation')}
			</Button>
			<AllocationManageModal
				allocation={allocation}
				open={manageOpen}
				onClose={() => setManageOpen(false)}
			/>
		</React.Fragment>
	);
}

export default AllocationPopperActions
