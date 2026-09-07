'use client'
import React, {FC} from 'react';
import {Alert, Paragraph} from "@digdir/designsystemet-react";
import {IAPIEvent} from "@/service/pecalendar.types";
import {phpGWLink} from "@/service/util";
import ManageModal, {ManageModalAdapter, ManageModalMutation, ManageModalPreviewBase} from "./manage-modal";

interface EventManageModalProps {
	event: IAPIEvent;
	open: boolean;
	onClose: () => void;
}

/**
 * Design 1c — the event management modal. Sibling of allocation-manage-modal.tsx /
 * booking-manage-modal.tsx, filling the SAME shared `ManageModal` core — but with
 * `cancelReach: 'legacy'`, not 'slim4' (see manage-modal.tsx's top docblock).
 *
 * WHY THIS ADAPTER LOOKS DIFFERENT FROM ITS TWO SIBLINGS. Measured this branch (#23459/#23470/
 * #23462/#23468): events are not bookings-minus. `uievent::cancel()` (bookingfrontend/inc/
 * class.uievent.inc.php:406) is a real, guarded legacy mutation with no Slim4 equivalent — no
 * cancel-preview endpoint, no request-vs-delete arm (the `user_can_delete_events` config toggle
 * is hard-delete vs soft-deactivate, decided entirely server-side, never a "send a request"
 * path), and its own cascade (rejecting the parent application when this was its last active
 * event, #23462) already happens inside that guarded mutation — there is nothing left for a
 * client-side preview step to compute or confirm. So this adapter supplies no real
 * useCancelPreviewMutation/useCancelMutation/buildRequestBody/mapOccurrences/confirmExtras/
 * doneSummary — every one of those is slim4-only machinery, structurally unreachable once
 * `cancelReach` is 'legacy' (manage-modal.tsx never calls `setStep` past 'overview' for this
 * adapter — see its overview action block). They are filled with inert stand-ins below only
 * because every adapter must satisfy ONE required interface (so the shared hooks in the core are
 * still called unconditionally, in the same order, on every render, regardless of adapter — see
 * the interface's own docblock for why that matters). None of them do real work; none are wired
 * to a network call.
 *
 * WHAT THE OVERVIEW RENDERS. `IAPIEvent` has no `season_id` at all (Event.php carries no such
 * column — an event is not scheduled against a season) — the core's `season_id` field is
 * optional for exactly this reason, and the title's meta line falls to its existing '—' rather
 * than a fabricated season. There is no `organization_id`/`organization_name` on the wire either
 * (an event's customer may be an org OR a private individual, `customer_identifier_type` decides
 * which); the "you are" sidebar card reuses `isOrgAdmin`'s own event branch (event-converter.ts),
 * which already returns true only for an org-identified event matching the viewer's own org, and
 * `customer_organization_name` is `@Expose`d under that exact same condition (Event.php:145-154),
 * so it is never blank when the card is shown. `entity.name` (`@Default("PRIVATE EVENT")`,
 * `@Expose` conditional on the same visibility rules as the rest of the customer-facing fields)
 * is the title, matching the "actual name, not description" instruction — no per-event
 * description/organizer/equipment row is added; none of that is asked for and none of it is
 * invented here.
 *
 * THE CASCADE DISCLOSURE. `cancellation_closes_application` (Event.php:238, `@Expose`
 * unconditionally, computed server-side by ScheduleEntityService::computeCancellationClosesApplication
 * on all four schedule endpoints — buildings/{id}/schedule, resources/{id}/schedule,
 * organizations/{id}/schedule, applications/{id}/schedule) is a plain boolean already ON the
 * entity this component receives as a prop — there is no preview step to source it from, so
 * `cancelExtras` reads it directly off `entity`, not off a `TPreview`. Rendered as a STATEMENT
 * (`event_cancellation_closes_application`, only when true) — never a conditional the user cannot
 * resolve, and never English prose from the payload: the flag drives one lang() lookup, nothing
 * server-authored is rendered. No application id/name is interpolated into that sentence:
 * `application_id` carries no `@Expose` on any schedule entity (confirmed absent from every
 * curled event object) and is never invented here.
 *
 * THE CANCEL ACTION. `buildCancelHref` constructs the SAME deep link legacy's own code builds at
 * every one of its three `uievent.cancel` call sites (class.uiapplication.inc.php:293,
 * class.uievent.inc.php:752, :995) — `menuaction=bookingfrontend.uievent.cancel` +
 * `id`/`resource_ids`, no `date` param (legacy's own builders never pass one either — Sanitizer::
 * get_var('date') falls back to "now" via `new DateTime('')` when absent) and no `from_org` (an
 * optional redirect-target hint this modal has no context to supply, exactly like the
 * edit/register-participants links' own omissions). Never a server-supplied `cancel_link` field —
 * that field exists on `IAPIScheduleEntity` but reading it here would be the #21147 trap.
 */
const EventManageModal: FC<EventManageModalProps> = ({event, open, onClose}) => {
	const inertMutation = <TData, >(): ManageModalMutation<TData> => ({
		mutateAsync: async () => {
			throw new Error('unreachable: cancelReach=legacy never previews or mutates in-modal');
		},
		isPending: false,
		isError: false,
		error: null,
		reset: () => {
		},
	});

	const adapter: ManageModalAdapter<IAPIEvent, ManageModalPreviewBase, never> = {
		dialogIdPrefix: 'event-manage',
		typeTagLangKey: 'bookingfrontend.event',
		titleName: (entity) => entity.name,
		youAreLangKey: 'bookingfrontend.admin_for_organization',
		youAreParams: (entity) => ({organization: entity.customer_organization_name ?? ''}),
		newBookingAllocationId: () => undefined,
		registerParticipantsType: 'event',
		editMenuaction: 'bookingfrontend.uievent.edit',
		editLabelLangKey: 'bookingfrontend.edit event',
		buildEditParams: (entity) => ({id: entity.id, resource_ids: entity.resources.map((resource) => resource.id)}),
		deleteFlagKey: 'user_can_delete_events',
		cancelActionLangKey: 'bookingfrontend.cancel event',
		cancelReach: 'legacy',

		buildCancelHref: (entity) => phpGWLink('bookingfrontend/', {
			menuaction: 'bookingfrontend.uievent.cancel',
			id: entity.id,
			resource_ids: entity.resources.map((resource) => resource.id),
		}, false),

		cancelExtras: (entity, t) => entity.cancellation_closes_application ? (
			<Alert data-color="warning">
				<Paragraph data-size="sm">{t('bookingfrontend.event_cancellation_closes_application')}</Paragraph>
			</Alert>
		) : null,

		// Everything below is slim4-only wizard machinery. Unreachable for cancelReach='legacy'
		// (manage-modal.tsx's overview action block never calls `setStep` for this adapter) —
		// present only to satisfy the shared adapter interface. See this file's top docblock.
		requestModeNoticeLangKey: '',
		seriesChangedLangKey: '',
		datesWithoutLangKey: '',
		initialExtraState: {},
		useCancelPreviewMutation: () => inertMutation<ManageModalPreviewBase>(),
		useCancelMutation: () => inertMutation<never>(),
		buildRequestBody: () => ({}),
		mapOccurrences: () => [],
		emptyCount: () => 0,
		confirmExtras: () => null,
		doneSummary: () => null,
	};

	return <ManageModal entity={event} adapter={adapter} open={open} onClose={onClose}/>;
};

export default EventManageModal;
