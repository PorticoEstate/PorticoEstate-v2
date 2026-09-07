'use client'
import React, {FC} from 'react';
import {DateTime} from "luxon";
import {Alert, Checkbox, Paragraph} from "@digdir/designsystemet-react";
import {IAPIBooking} from "@/service/pecalendar.types";
import styles from "./manage-modal.module.scss";
import {
	emptyBookingOccurrenceCount,
	IBookingCancelPreview,
	IBookingCancelResult,
	realBookingOccurrences,
} from "@/service/api/booking-cancellation";
import {useBookingCancel, useBookingCancelPreview} from "@/service/hooks/booking-cancellation-hooks";
import ManageModal, {ManageModalAdapter, ManageModalOccurrenceView} from "./manage-modal";

interface BookingManageModalProps {
	booking: IAPIBooking;
	open: boolean;
	onClose: () => void;
}

interface BookingExtraState {
	deleteAllocation: boolean;
}

/**
 * Design 1c — the booking management modal. Sibling of allocation-manage-modal.tsx, filling the
 * SAME shared `ManageModal` core with booking's own adapter (see manage-modal.tsx's docblock, and
 * #23387 / forum/pe-general/21577-21578 for why this is an adapter rather than a parallel build).
 *
 * WHAT THE OVERVIEW RENDERS, and why it stops there. `IAPIBooking` carries no organisation on the
 * wire at all (no `organization_id`/`organization_name` field on the type — a booking belongs to a
 * GROUP, not directly to an org), so the title and the "you are" panel key off `group_name`, not an
 * organisation, and no organisation contact/admin info is drawn for the same reason it is unreached
 * for allocation. `activity_name` IS reachable (@Expose, computed) and has no allocation analogue,
 * so it is the one extra overview row this adapter adds. `application_id` stays unreached here for
 * the same reason as on the allocation side — nullable, no `@Expose`, `undefined` at runtime.
 *
 * The confirm step's cascade/split_pool disclosure is rendered from `IBookingCancelPreview`'s
 * STRUCTURED fields (`cascade.count`/`allocation_ids`, `split_pool.active`) only — `cascade.note`
 * and `split_pool.effect` are server-authored English prose, dead by design (see that type's
 * docblock and BookingCancellationService's), and are never read here.
 */
const BookingManageModal: FC<BookingManageModalProps> = ({booking, open, onClose}) => {
	const adapter: ManageModalAdapter<IAPIBooking, IBookingCancelPreview, IBookingCancelResult> = {
		dialogIdPrefix: 'booking-manage',
		typeTagLangKey: 'bookingfrontend.booking',
		titleName: (entity) => entity.group_name,
		youAreLangKey: 'bookingfrontend.admin_for_group',
		youAreParams: (entity) => ({group: entity.group_name}),
		overviewExtraRows: (entity, t) => (
			<>
				<span className={styles.overviewLabel}>{t('bookingfrontend.activity')}</span>
				<span>{entity.activity_name}</span>
			</>
		),
		newBookingAllocationId: (entity) => entity.allocation_id,
		registerParticipantsType: 'booking',
		editMenuaction: 'bookingfrontend.uibooking.edit',
		editLabelLangKey: 'bookingfrontend.edit booking',
		buildEditParams: (entity) => ({id: entity.id, resource_ids: entity.resources.map((resource) => resource.id)}),
		deleteFlagKey: 'user_can_delete_bookings',
		cancelActionLangKey: 'bookingfrontend.cancel_booking',
		requestModeNoticeLangKey: 'bookingfrontend.booking_request_mode_notice',
		seriesChangedLangKey: 'bookingfrontend.allocation_series_changed_re_previewed',
		datesWithoutLangKey: 'bookingfrontend.dates_without_booking',
		cancelReach: 'slim4',
		initialExtraState: {deleteAllocation: false} as BookingExtraState,

		useCancelPreviewMutation: () => {
			const mutation = useBookingCancelPreview();
			return {
				mutateAsync: ({id, body, secret}) => mutation.mutateAsync({bookingId: id, body: body as any, secret}),
				isPending: mutation.isPending,
				isError: mutation.isError,
				error: mutation.error,
				reset: mutation.reset,
			};
		},
		useCancelMutation: (buildingId) => {
			const mutation = useBookingCancel(buildingId);
			return {
				mutateAsync: ({id, body, secret}) => mutation.mutateAsync({bookingId: id, body: body as any, secret}),
				isPending: mutation.isPending,
				isError: mutation.isError,
				error: mutation.error,
				reset: mutation.reset,
			};
		},

		buildRequestBody: ({scope, repeatUntil, fieldInterval, message}, extraState) => {
			const state = extraState as BookingExtraState;
			return {
				scope,
				...(scope === 'until' ? {repeat_until: repeatUntil} : {}),
				field_interval: Number(fieldInterval) || 1,
				message,
				delete_allocation: state.deleteAllocation,
			};
		},

		/**
		 * Unlike an allocation occurrence, a booking occurrence is never "blocked" — only
		 * 'cancellable' or 'no_booking' (see IBookingCancelOccurrence's docblock: nothing prevents
		 * cancelling a booking once the #1210 guard has passed). Every non-cancellable row is
		 * therefore the same "nothing here to cancel" case, dotted with the base neutral dot.
		 */
		mapOccurrences: (preview, cancelMode, t): ManageModalOccurrenceView[] => {
			return realBookingOccurrences(preview).map((occurrence) => {
				const assertsCancellable = occurrence.cancellable && (cancelMode === 'request' || cancelMode === 'delete');
				const from = DateTime.fromSQL(occurrence.from_);
				const to = DateTime.fromSQL(occurrence.to_);
				const when = !from.isValid
					? occurrence.from_
					: to.isValid
						? `${from.toFormat('ccc d. LLL yyyy')}, ${from.toFormat('HH:mm')} – ${to.toFormat('HH:mm')}`
						: from.toFormat('ccc d. LLL yyyy HH:mm');

				return {
					key: `${occurrence.index}-${occurrence.from_}`,
					when,
					cancellable: occurrence.cancellable,
					dotClass: assertsCancellable ? styles.cancellable : '',
					note: assertsCancellable
						? t('bookingfrontend.free_to_cancel')
						: (!occurrence.cancellable ? t('bookingfrontend.booking_no_booking_this_date') : ''),
				};
			});
		},

		emptyCount: emptyBookingOccurrenceCount,

		scopeExtras: (extraState, setExtraState, t) => {
			const state = extraState as BookingExtraState;
			return (
				<div className={styles.panel}>
					<Checkbox
						value="delete_allocation"
						id="booking-cancel-delete-allocation"
						checked={state.deleteAllocation}
						onChange={(e) => setExtraState({deleteAllocation: e.target.checked} as BookingExtraState)}
						label={t('bookingfrontend.delete_allocation_option')}
					/>
				</div>
			);
		},

		confirmExtras: (preview, extraState, t) => {
			const state = extraState as BookingExtraState;
			const {cascade, split_pool: splitPool} = preview;
			return (
				<>
					{cascade.count > 0 && (
						<Alert data-color={state.deleteAllocation ? 'danger' : 'info'}>
							<Paragraph data-size="sm">
								{state.deleteAllocation
									? t('bookingfrontend.cascade_would_delete_allocations', {
										count: cascade.count,
										ids: cascade.allocation_ids.map((id) => `#${id}`).join(', '),
									})
									: t('bookingfrontend.cascade_would_leave_allocations_empty', {count: cascade.count})}
							</Paragraph>
						</Alert>
					)}
					{splitPool.active && (
						<Alert data-color="info">
							<Paragraph data-size="sm">{t('bookingfrontend.split_pool_active_no_effect')}</Paragraph>
						</Alert>
					)}
				</>
			);
		},

		doneSummary: (result, t) => {
			const skippedReal = result.skipped.filter((s) => s.reason !== 'no_booking_at_this_time').length;
			return (
				<>
					<Alert data-color="success">
						<Paragraph data-size="sm">
							{t('bookingfrontend.booking_cancelled_summary', {count: result.deleted_bookings_count})}
						</Paragraph>
					</Alert>
					{result.deleted_allocations_count > 0 && (
						<span className={styles.mutedFootnote}>
							{t('bookingfrontend.cascade_deleted_allocations_summary', {
								count: result.deleted_allocations_count,
								ids: result.deleted_allocations.map((id) => `#${id}`).join(', '),
							})}
						</span>
					)}
					{skippedReal > 0 && (
						<span className={styles.mutedFootnote}>
							{t('bookingfrontend.booking_cancel_skipped_summary', {count: skippedReal})}
						</span>
					)}
				</>
			);
		},
	};

	return <ManageModal entity={booking} adapter={adapter} open={open} onClose={onClose}/>;
};

export default BookingManageModal;
