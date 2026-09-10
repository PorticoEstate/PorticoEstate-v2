import React, {FC, useState} from 'react';
import {IAPIAllocation} from "@/service/pecalendar.types";
import {IBookingUser} from "@/service/types/api.types";
import styles from "@/components/building-calendar/modules/event/popper/event-popper.module.scss";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
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
 * exists, so the button opens it here rather than the legacy edit page. The
 * design's quick-booking button (`sc-if showQuickBooking`) was REMOVED on
 * operator instruction (task #23606) — the card carries the Manage button
 * ALONE now; a "Create new booking" affordance still exists, but only inside
 * the management modal, not on the popper card itself.
 */
const AllocationPopperActions: FC<AllocationPopperActionsProps> = (props) => {
	const {allocation} = props;
	const t = useTrans();
	const [manageOpen, setManageOpen] = useState<boolean>(false);

	return (
		<React.Fragment>
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
