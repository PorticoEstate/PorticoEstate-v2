'use client'
import React, {FC} from 'react';
import {DateTime} from "luxon";
import {Alert, Paragraph} from "@digdir/designsystemet-react";
import {IAPIAllocation} from "@/service/pecalendar.types";
import styles from "./manage-modal.module.scss";
import {
	IAllocationCancelPreview,
	IAllocationCancelResult,
	isDeadBlocked,
	realOccurrences,
} from "@/service/api/allocation-cancellation";
import {useAllocationCancel, useAllocationCancelPreview} from "@/service/hooks/allocation-cancellation-hooks";
import ManageModal, {CancelMode, ManageModalAdapter, ManageModalOccurrenceView, TFunction} from "./manage-modal";

interface AllocationManageModalProps {
	allocation: IAPIAllocation;
	open: boolean;
	onClose: () => void;
}

/**
 * Design 1c — the allocation management modal.
 *
 * Thin per-entity wrapper around the shared `ManageModal` core (see #23387 / pop_shared_modal
 * design note, forum/pe-general/21577-21578): the step machine, overview render, scope/confirm/
 * done and the `cancelMode` tri-state all live in manage-modal.tsx now, entity-agnostic. This file
 * supplies only the allocation adapter — everything a booking's equivalent (booking-manage-modal.tsx)
 * fills in differently: endpoints, lang keys, the edit menuaction and delete-flag key, and the
 * per-occurrence blocked/dead interpretation particular to an allocation's own cancel-preview shape.
 *
 * WHAT THE OVERVIEW STILL DOES NOT RENDER, and why (unchanged from before the adapter split — see
 * git history on this file for the original single-component version, and #21603/#21181/#19645 for
 * the individual reachability calls). The design's overview also carries the organisation's contact
 * person, the owning application number and its approval date, the full "3 bookings under it" list,
 * the comment thread, the participant count and the computed cancellation deadline — the contact
 * person is the legacy `contacts[0]` entity and is not on the served Organization; `application_id`
 * is deliberately not exposed on the allocation payload; there is no bb_allocation_comment table;
 * and the deadline's computed instant is not served for an allocation. Blocking-booking names ARE
 * reachable — `blocking_bookings[].group_name`, rendered on the confirm step once cancel-preview has
 * run — so that list is deferred one screen later than the design draws it, not unbuildable. None
 * of the rest is reachable, so none of it is drawn. What IS reachable — organisation, building,
 * resources (with their per-resource participant limits and colours), the occurrence's period and
 * duration, the season's name, the allocation's type and id, and whether the viewer administers the
 * owning organisation — comes straight off the `allocation` prop and the booking user already
 * available on open; no `cancel-preview` call is made to build this screen, since that mutation
 * only fires once the user has chosen to cancel.
 *
 * The recipient recap the design draws in step 2 ("To: case worker · 6 user organisations …") is
 * likewise absent: nothing in the shipped endpoint computes or returns a recipient set.
 */
const AllocationManageModal: FC<AllocationManageModalProps> = ({allocation, open, onClose}) => {
	const adapter: ManageModalAdapter<IAPIAllocation, IAllocationCancelPreview, IAllocationCancelResult> = {
		dialogIdPrefix: 'allocation-manage',
		typeTagLangKey: 'bookingfrontend.allocation',
		titleName: (entity) => entity.organization_name,
		youAreLangKey: 'bookingfrontend.admin_for_organization',
		youAreParams: (entity) => ({organization: entity.organization_name}),
		// Same shape as allocation-popper-actions.tsx's own "+ New booking" link — this modal is
		// a SECOND consumer of that route, not a replacement for the card's button.
		newBookingAllocationId: (entity) => entity.id,
		// Same menuaction uiallocation.inc.php:860-863 already builds server-side.
		registerParticipantsType: 'allocation',
		// ONE edit control, to the one edit form that exists — never a per-field deep-link, and
		// never worded as a request: uiallocation.edit performs a direct edit.
		editMenuaction: 'bookingfrontend.uiallocation.edit',
		editLabelLangKey: 'bookingfrontend.edit allocation',
		buildEditParams: (entity) => ({allocation_id: entity.id}),
		deleteFlagKey: 'user_can_delete_allocations',
		cancelActionLangKey: 'bookingfrontend.cancel_allocation',
		requestModeNoticeLangKey: 'bookingfrontend.allocation_request_mode_notice',
		seriesChangedLangKey: 'bookingfrontend.allocation_series_changed_re_previewed',
		datesWithoutLangKey: 'bookingfrontend.dates_without_allocation',
		cancelReach: 'slim4',
		initialExtraState: {},

		useCancelPreviewMutation: () => {
			const mutation = useAllocationCancelPreview();
			return {
				mutateAsync: ({id, body, secret}) => mutation.mutateAsync({allocationId: id, body: body as any, secret}),
				isPending: mutation.isPending,
				isError: mutation.isError,
				error: mutation.error,
				reset: mutation.reset,
			};
		},
		useCancelMutation: (buildingId) => {
			const mutation = useAllocationCancel(buildingId);
			return {
				mutateAsync: ({id, body, secret}) => mutation.mutateAsync({allocationId: id, body: body as any, secret}),
				isPending: mutation.isPending,
				isError: mutation.isError,
				error: mutation.error,
				reset: mutation.reset,
			};
		},

		buildRequestBody: ({scope, repeatUntil, fieldInterval, message}) => ({
			scope,
			...(scope === 'until' ? {repeat_until: repeatUntil} : {}),
			field_interval: Number(fieldInterval) || 1,
			message,
		}),

		/**
		 * Reuses `occurrence.cancellable` for "is this date blocked by a booking underneath" and
		 * the ONE `cancelMode` discriminator for "may you cancel at all" — same split as before the
		 * adapter split (#19645/#19526): an unresolved OR still-loading setting can no longer show a
		 * green dot and "Kan avbestilles" beside a button reading "Utilgjengelig"/"Laster inn…".
		 */
		mapOccurrences: (preview: IAllocationCancelPreview, cancelMode: CancelMode, t: TFunction): ManageModalOccurrenceView[] => {
			return realOccurrences(preview).map((occurrence) => {
				const dead = isDeadBlocked(occurrence);
				const blocker = occurrence.blocking_bookings[0];
				const assertsCancellable = occurrence.cancellable && (cancelMode === 'request' || cancelMode === 'delete');
				// Blocked rows are untouched: they answer a question that has nothing to do with
				// the setting.
				const dotClass = !occurrence.cancellable
					? (dead ? styles.blockedDead : styles.blockedLive)
					: assertsCancellable
						? styles.cancellable
						: '';

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
					dotClass,
					note: (
						<>
							{assertsCancellable && t('bookingfrontend.free_to_cancel')}
							{!occurrence.cancellable && blocker && (
								<>
									<span className={styles.blockerDetail}>
										{dead
											? t('bookingfrontend.blocked_by_inactive_booking', {group: blocker.group_name ?? '', id: blocker.id})
											: t('bookingfrontend.blocked_by_live_booking', {group: blocker.group_name ?? '', id: blocker.id})}
									</span>
									{dead && (
										<span className={styles.blockerDetail}>
											{t('bookingfrontend.blocked_by_inactive_booking_help')}
										</span>
									)}
								</>
							)}
						</>
					),
				};
			});
		},

		// `no_allocation` counts every date the walk visited and does not represent an allocation
		// at all — it is not "blocked", it is empty. Never the denominator the wizard's "N of M"
		// means; that denominator is the mapped `occurrenceViews` array's own length instead.
		emptyCount: (preview) => preview.no_allocation,

		confirmExtras: () => null,

		doneSummary: (result, t) => {
			// `skipped_count` counts every date the walk visited and did not delete, which includes
			// the weeks that never had an allocation at all. Reporting that number back as
			// "N occurrences were not cancelled" would tell the user the flow spared occurrences
			// that do not exist. Only genuinely blocked occurrences are counted here, for the same
			// reason the step-2 denominator excludes them.
			const skippedReal = result.skipped.filter((s) => s.status !== 'no_allocation').length;
			return (
				<>
					<Alert data-color="success">
						<Paragraph data-size="sm">
							{t('bookingfrontend.allocation_cancelled_summary', {count: result.deleted_count})}
						</Paragraph>
					</Alert>
					{skippedReal > 0 && (
						<span className={styles.mutedFootnote}>
							{t('bookingfrontend.allocation_cancel_skipped_summary', {count: skippedReal})}
						</span>
					)}
				</>
			);
		},
	};

	return <ManageModal entity={allocation} adapter={adapter} open={open} onClose={onClose}/>;
};

export default AllocationManageModal;
