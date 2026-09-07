'use client'
import React, {ReactNode, useCallback, useMemo, useRef, useState} from 'react';
import {DateTime} from "luxon";
import {Alert, Button, Fieldset, Heading, Label, Paragraph, Radio, Spinner, Tag, Textarea, Textfield, Tooltip} from "@digdir/designsystemet-react";
import Dialog from "@/components/dialog/mobile-dialog";
import Link from "next/link";
import {PlusIcon} from "@navikt/aksel-icons";
import {useClientTranslation} from "@/app/i18n/ClientTranslationProvider";
import {useBookingUser, useBuildingSeasons, useServerSettings} from "@/service/hooks/api-hooks";
import {useCurrentBuilding} from "@/components/building-calendar/calendar-context";
import {isOrgAdmin} from "@/components/building-calendar/util/event-converter";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {IAPIScheduleEntity} from "@/service/pecalendar.types";
import {isFutureDate, phpGWLink} from "@/service/util";
import styles from "./manage-modal.module.scss";

export type TFunction = (key: string, options?: any) => string;

/** IAPIScheduleEntity itself carries no season_id — allocation and booking each declare it, and
 *  the core reads it generically to resolve the season name shown in the title's meta line.
 *  Optional, not required: an event has NO season_id at all (Event.php carries no such column —
 *  events are not scheduled against a season the way allocations/bookings are), so `seasonName`
 *  simply never matches and `seasonDisplay` falls to its existing '—' case, honestly reporting
 *  "no season" rather than a bug being papered over with an invented value. */
interface ManageModalScheduleEntity extends IAPIScheduleEntity {
	season_id?: number;
}

type Step = 'overview' | 'scope' | 'confirm' | 'done';

export type ManageModalCancelScope = 'occurrence' | 'season' | 'until';

export type ManageModalCancelReach = 'slim4' | 'legacy' | 'none';

export interface ManageModalPreviewBase {
	confirm_token: string;
}

export interface ManageModalMutationError {
	message?: string;
	isRequestMode?: boolean;
	isStaleToken?: boolean;
}

export interface ManageModalMutation<TData> {
	mutateAsync: (args: {id: number; body: Record<string, unknown>; secret?: string}) => Promise<TData>;
	isPending: boolean;
	isError: boolean;
	error: ManageModalMutationError | null;
	reset: () => void;
}

export interface ManageModalOccurrenceView {
	key: string;
	when: string;
	cancellable: boolean;
	dotClass: string;
	note: ReactNode;
}

/**
 * Everything that differs between entity types. The step machine, the overview layout, the
 * scope/confirm/done render structure and the `cancelMode` tri-state below are all shared and
 * live in this file, never in an adapter — an adapter supplies WORDING, ENDPOINTS and per-entity
 * INTERPRETATION of its own preview/result shape, never a branch the core itself takes.
 *
 * ONE exception, and it is the ONLY branch the core itself takes on adapter data:
 * `cancelReach`. 'slim4' (allocation, booking) runs the scope→confirm→done wizard below exactly
 * as before. 'legacy' (event) never leaves the overview step — events have no Slim4 cancel
 * endpoint, no preview, no request-vs-delete arm (the config toggle is hard-delete vs
 * soft-deactivate, decided entirely server-side), so there is nothing for a wizard to preview.
 * Its destructive action is a plain deep link to the guarded legacy page, the same pattern as
 * this modal's own edit/register-participants links, gated on `buildCancelHref`/`cancelExtras`
 * rather than the mutation hooks below. A 'legacy' adapter still supplies every slim4-only field
 * (useCancelPreviewMutation, buildRequestBody, mapOccurrences, ...) so every adapter keeps the
 * SAME required shape and the hooks below are still called unconditionally, in the same order,
 * on every render, regardless of adapter — only their return values go unused. See
 * event-manage-modal.tsx's adapter for what it fills those with and why that's safe.
 */
export interface ManageModalAdapter<
	TEntity extends ManageModalScheduleEntity,
	TPreview extends ManageModalPreviewBase,
	TResult
> {
	dialogIdPrefix: string;
	typeTagLangKey: string;
	titleName: (entity: TEntity) => string;
	youAreLangKey: string;
	youAreParams: (entity: TEntity) => Record<string, string>;
	overviewExtraRows?: (entity: TEntity, t: TFunction) => ReactNode;
	newBookingAllocationId: (entity: TEntity) => number | undefined;
	registerParticipantsType: 'allocation' | 'booking' | 'event';
	editMenuaction: string;
	editLabelLangKey: string;
	buildEditParams: (entity: TEntity) => Record<string, string | number | (string | number)[]>;
	deleteFlagKey: 'user_can_delete_allocations' | 'user_can_delete_bookings' | 'user_can_delete_events';
	cancelActionLangKey: string;
	requestModeNoticeLangKey: string;
	seriesChangedLangKey: string;
	datesWithoutLangKey: string;
	cancelReach: ManageModalCancelReach;
	useCancelPreviewMutation: () => ManageModalMutation<TPreview>;
	useCancelMutation: (buildingId?: number) => ManageModalMutation<TResult>;
	initialExtraState: unknown;
	/** The scope step's per-entity slot — booking's delete_allocation checkbox, empty for allocation. */
	scopeExtras?: (extraState: unknown, setExtraState: (next: unknown) => void, t: TFunction) => ReactNode;
	buildRequestBody: (
		common: {scope: ManageModalCancelScope; repeatUntil: string; fieldInterval: string; message: string},
		extraState: unknown
	) => Record<string, unknown>;
	/** Occurrences worth SHOWING, already interpreted — the denominator ("N of M") is this array's
	 *  length, and `emptyCount` below is reported separately as a footnote, never re-derived here. */
	mapOccurrences: (preview: TPreview, cancelMode: CancelMode, t: TFunction) => ManageModalOccurrenceView[];
	emptyCount: (preview: TPreview) => number;
	/** The confirm step's per-entity slot — booking's cascade + split_pool disclosure, empty for
	 *  allocation. Rendered from the preview's STRUCTURED fields only — never server-authored prose. */
	confirmExtras: (preview: TPreview, extraState: unknown, t: TFunction) => ReactNode;
	doneSummary: (result: TResult, t: TFunction) => ReactNode;
	/**
	 * `cancelReach === 'legacy'` only (optional here, not split into a second adapter shape, so
	 * the required fields above stay identical for every existing caller — see this file's other
	 * docblock on cancelReach for why). Builds the client-constructed deep link to the guarded
	 * legacy cancel page — never a server-supplied `cancel_link` field (#21147 trap: that field
	 * exists on the wire but is a stale/legacy artifact, not meant as this client's navigation
	 * source).
	 */
	buildCancelHref?: (entity: TEntity) => string;
	/** `cancelReach === 'legacy'` only. The overview-screen disclosure rendered from the entity's
	 *  own STRUCTURED fields (never server-authored prose) — the equivalent of confirmExtras, but
	 *  shown on the overview itself, since a 'legacy' adapter has no confirm step to show it on. */
	cancelExtras?: (entity: TEntity, t: TFunction) => ReactNode;
}

/**
 * Request mode.
 *
 * Gated on `=== true`, NEVER on truthiness. SerializableTrait::parseStringBoolean coerces only
 * yes|true|1|no|false|''|0 and returns any other stored string UNCHANGED, so an admin value the
 * parser does not know — 'never' is selectable on booking/templates/base/settings.xsl — arrives
 * here as a non-empty, therefore truthy, string while every PHP consumer reads it as off.
 * `if (flag)` would switch the affordance ON under the most restrictive setting available. With
 * cancel gone from the popper card, this modal (for whichever entity — allocation or booking —
 * `adapter.deleteFlagKey` names) is the only remaining Lane-3 reader of this flag.
 *
 * RENDER ONLY. No request or notification flow is built here: the server answers the
 * request-mode branch with 409 rather than porting legacy's case-worker notification, and
 * making that branch reachable from this client would be new behaviour, not a port.
 *
 * NOT PRETENDING AND DISCLOSING ARE NOT ALTERNATIVES (#23430). This shared core used to offer an
 * enabled "send request" control on the confirm step whose only possible outcome, given the 409
 * above, was to re-render the very notice that already told the user this capability does not
 * exist. Two earlier rulings on this modal are both still true and both apply here at once: a
 * control must never claim an ability the system cannot perform, AND the capability gap must
 * still be disclosed rather than silently dropped. The fix is not a choice between them — the
 * confirm step's primary button is simply absent (not disabled — see the footer's own comment)
 * whenever `cancelMode === 'request'`, and the same `requestModeNoticeLangKey` Alert that used to
 * wait for the failed attempt is now shown proactively instead, on the same step.
 *
 * FOUR STATES, NOT TWO — and this is the whole point of the shape below.
 *
 * The flag can be known-TRUE, known-FALSE, IN FLIGHT, or otherwise NOT KNOWN: the settings query
 * is still fetching its first response, or it failed, or it answered without a booking_config at
 * all. `!canDelete` used to collapse all three of the non-TRUE cases into request mode, so a
 * client that had been told NOTHING rendered the confident claim "this municipality does not let
 * you delete". A later fix (#19526) separated the failed/absent case from request mode, but still
 * folded "still fetching" into that same unresolved bucket — so on first open the entry screen's
 * destructive action read "Utilgjengelig", a settled refusal, for a question the client had not
 * been answered yet (#21606). Merely loading is not evidence of absence: it is its own, fourth
 * outcome, and it renders its own honest word — one that says "still working it out", never
 * "this is unavailable" and never "you may proceed".
 *
 * It is the same failure as reading this flag with `if(flag)` — a two-valued expression standing
 * in for a world with more than two values — and it is why `canDelete === false` alone is NOT the
 * fix: that only moves the collapse from one branch to the other. Every user-facing mode must
 * therefore be positively known, and "loading" / "unresolved" are outcomes in their own right,
 * not a shared default for everything that is not yet TRUE.
 *
 * ONE discriminator, read by every surface that names the mode.
 *
 * The step title and the confirm button are never on screen together — the title belongs to the
 * scope step, the button to the confirm step — so nothing on the page makes it visible when the
 * two disagree. They did, on the pre-split allocation modal: the button had already been made
 * three-way while the label stayed two-valued, and in the unresolved state the heading went on
 * announcing the confident "cancel" claim from the branch that is merely NOT request mode.
 * Deriving the mode once and switching every surface on it is the fix; adding a second three-way
 * (now four-way) expression beside this one would only move the collapse one line over — which is
 * exactly what happened to "loading" before that pass: `settingsLoading` is read only here,
 * feeding the ONE discriminator below, never branched on separately downstream.
 */
export type CancelMode = 'loading' | 'unresolved' | 'request' | 'delete';

interface ManageModalProps<
	TEntity extends ManageModalScheduleEntity,
	TPreview extends ManageModalPreviewBase,
	TResult
> {
	entity: TEntity;
	adapter: ManageModalAdapter<TEntity, TPreview, TResult>;
	open: boolean;
	onClose: () => void;
}

function ManageModal<
	TEntity extends ManageModalScheduleEntity,
	TPreview extends ManageModalPreviewBase,
	TResult
>({entity, adapter, open, onClose}: ManageModalProps<TEntity, TPreview, TResult>) {
	const {t, i18n} = useClientTranslation();
	const serverSettings = useServerSettings();
	const currentBuilding = useCurrentBuilding();
	const {data: bookingUser} = useBookingUser();
	const isAdminForEntity = isOrgAdmin(bookingUser, entity as any);

	const buildingId = typeof currentBuilding === 'string'
		? Number(currentBuilding)
		: currentBuilding;

	// `season_id` is on the entity; the season's NAME is not, so it is resolved through the same
	// hook the calendar itself uses. This is a read already cached under ['building_seasons',
	// buildingId] whenever the calendar view is open behind this modal.
	const buildingSeasons = useBuildingSeasons(Number.isFinite(buildingId as number) ? buildingId as number : undefined);
	const seasonName = buildingSeasons.data?.find((season) => season.id === entity.season_id)?.name;
	// Feeds the "#id · season · building" meta line in the modal's title chrome (design
	// :335) — the design never draws season as its own grid row, so that meta line is
	// its only consumer (#21603). Three-state fallback: #19645, resolved / loading / neutral.
	const seasonDisplay = seasonName ? seasonName : buildingSeasons.isLoading ? t('bookingfrontend.loading...') : '—';

	const [step, setStep] = useState<Step>('overview');
	const [scope, setScope] = useState<ManageModalCancelScope>('occurrence');
	const [repeatUntil, setRepeatUntil] = useState<string>('');
	const [fieldInterval, setFieldInterval] = useState<string>('1');
	const [message, setMessage] = useState<string>('');
	const [extraState, setExtraState] = useState<unknown>(adapter.initialExtraState);
	const [preview, setPreview] = useState<TPreview | null>(null);
	const [result, setResult] = useState<TResult | null>(null);
	const [staleRepreviewed, setStaleRepreviewed] = useState<boolean>(false);
	const [requestModeRefusal, setRequestModeRefusal] = useState<boolean>(false);

	const previewMutation = adapter.useCancelPreviewMutation();
	const cancelMutation = adapter.useCancelMutation(Number.isFinite(buildingId as number) ? buildingId as number : undefined);

	const bookingConfig = serverSettings.data?.booking_config;
	const settingsLoading = serverSettings.isPending;
	const settingsUnresolved = !settingsLoading && (serverSettings.isError || bookingConfig == null);
	const canDelete = bookingConfig?.[adapter.deleteFlagKey] === true;
	const isRequestMode = !settingsLoading && !settingsUnresolved && !canDelete;

	// `loading` is checked FIRST and kept as its own branch — see the CancelMode docblock above:
	// it is the same failure as reading this flag with `if(flag)` to fold "still fetching" into
	// "unresolved" (#19526/#21606).
	const cancelMode: CancelMode =
		settingsLoading ? 'loading' : settingsUnresolved ? 'unresolved' : isRequestMode ? 'request' : 'delete';

	// 'legacy' reads its own cancelActionLangKey ALWAYS, ignoring cancelMode entirely — the
	// request/delete split above is computed from `adapter.deleteFlagKey`, which for a 'legacy'
	// adapter names the server's hard-delete-vs-soft-deactivate toggle, NOT a request-mode arm
	// (events have none, see the CancelReach docblock). Were this label to fall through to the
	// generic branch below, an event whose delete flag happened to read false would render "Be om
	// avbestilling" — booking's request-mode wording, for a capability this installation's event
	// flow does not have.
	const cancelLabel = adapter.cancelReach === 'legacy'
		? t(adapter.cancelActionLangKey)
		: cancelMode === 'loading'
			? t('bookingfrontend.loading...')
			: cancelMode === 'unresolved'
				? t('bookingfrontend.cancel_mode_unavailable')
				: cancelMode === 'request'
					? t('bookingfrontend.request_cancellation')
					: t(adapter.cancelActionLangKey);

	const occurrenceLabel = useMemo(() => {
		const from = DateTime.fromISO(entity.from_ as unknown as string);
		return from.isValid ? from.toFormat('cccc d. LLLL yyyy') : String(entity.from_);
	}, [entity.from_]);

	// The overview's period cell: the same date as occurrenceLabel, plus the start-end times.
	const overviewPeriodLabel = useMemo(() => {
		const from = DateTime.fromISO(entity.from_ as unknown as string);
		const to = DateTime.fromISO(entity.to_ as unknown as string);
		if (!from.isValid) {
			return String(entity.from_);
		}
		return to.isValid
			? `${occurrenceLabel}, ${from.toFormat('HH:mm')}–${to.toFormat('HH:mm')}`
			: occurrenceLabel;
	}, [entity.from_, entity.to_, occurrenceLabel]);

	// The overview's "(2 h)" duration note. `from_`/`to_` are both served, so this is
	// arithmetic on served data — never a field the server sends, and never a stand-in
	// for the design's recurrence text (which has no source field at all, see #21181).
	const overviewDurationLabel = useMemo(() => {
		const from = DateTime.fromISO(entity.from_ as unknown as string);
		const to = DateTime.fromISO(entity.to_ as unknown as string);
		if (!from.isValid || !to.isValid) {
			return null;
		}
		const totalHours = to.diff(from, 'hours').hours;
		if (!Number.isFinite(totalHours) || totalHours <= 0) {
			return null;
		}
		const rounded = Math.round(totalHours * 10) / 10;
		// Same technique as the date-picker's month select (CustomHeader.tsx:19,46): pass
		// i18n.language through as the Intl locale tag, which picks the decimal separator
		// (comma for Norwegian, point for English) and drops the ".0" for whole hours the
		// same way `Number.isInteger` used to. 'nn' is remapped to 'no': measured, this
		// browser's ICU has no data for 'nn'/'nn-NO' and Intl silently resolves it to
		// en-US — a wrong decimal POINT, not a missing translation — while 'no' resolves
		// correctly and shares Nynorsk's comma convention.
		const numberLocale = i18n.language === 'nn' ? 'no' : (i18n.language || 'no');
		const display = new Intl.NumberFormat(numberLocale, {maximumFractionDigits: 1}).format(rounded);
		const unit = rounded === 1 ? t('bookingfrontend.hour') : t('bookingfrontend.hours');
		return `${display} ${unit.toLowerCase()}`;
	}, [entity.from_, entity.to_, t, i18n.language]);

	/**
	 * The long-title-name reveal, same technique as the popper card's title (#19569): a
	 * ResizeObserver on the heading itself decides truncation, and the Tooltip node is only
	 * mounted — so only tabbable and only announced — when the name actually overflows. A
	 * short name never gets the affordance at all, not just a closed one.
	 */
	const [isTitleNameTruncated, setIsTitleNameTruncated] = useState(false);
	const titleNameResizeObserver = useRef<ResizeObserver | null>(null);
	const measureTitleNameTruncation = useCallback((el: HTMLHeadingElement) => {
		setIsTitleNameTruncated(el.scrollWidth > el.clientWidth);
	}, []);
	const titleNameRef = useCallback((el: HTMLHeadingElement | null) => {
		titleNameResizeObserver.current?.disconnect();
		titleNameResizeObserver.current = null;
		if (el) {
			measureTitleNameTruncation(el);
			titleNameResizeObserver.current = new ResizeObserver(() => measureTitleNameTruncation(el));
			titleNameResizeObserver.current.observe(el);
		}
	}, [measureTitleNameTruncation]);

	const requestBody = useCallback(
		() => adapter.buildRequestBody({scope, repeatUntil, fieldInterval, message}, extraState),
		[adapter, scope, repeatUntil, fieldInterval, message, extraState]
	);

	const runPreview = useCallback(async () => {
		const fresh = await previewMutation.mutateAsync({id: entity.id, body: requestBody()});
		setPreview(fresh);
		return fresh;
	}, [entity.id, previewMutation, requestBody]);

	const goToConfirm = useCallback(async () => {
		setStaleRepreviewed(false);
		setRequestModeRefusal(false);
		try {
			await runPreview();
			setStep('confirm');
		} catch {
			// The mutation's own error state renders the message; staying on step 1 is correct.
		}
	}, [runPreview]);

	/**
	 * The destructive step, and the TOCTOU recovery.
	 *
	 * The confirm_token is the one the CURRENT preview returned. If a booking was created under
	 * any occurrence between the two steps the server refuses with 409 and the token is stale.
	 * The recovery is to re-run the PREVIEW and show the user the changed series — never to
	 * retry the cancel, which would re-submit a set the user never saw.
	 */
	const confirmCancel = useCallback(async () => {
		if (!preview) {
			return;
		}
		setStaleRepreviewed(false);
		setRequestModeRefusal(false);
		try {
			const cancelled = await cancelMutation.mutateAsync({
				id: entity.id,
				body: {...requestBody(), confirm_token: preview.confirm_token},
			});
			setResult(cancelled);
			setStep('done');
		} catch (error: any) {
			if (error?.isRequestMode === true) {
				setRequestModeRefusal(true);
				return;
			}
			if (error?.isStaleToken === true) {
				try {
					await runPreview();
					setStaleRepreviewed(true);
				} catch {
					// The preview's own error state renders; the stale cancel is not retried.
				}
			}
		}
	}, [entity.id, cancelMutation, preview, requestBody, runPreview]);

	const handleClose = useCallback(() => {
		setStep('overview');
		setPreview(null);
		setResult(null);
		setStaleRepreviewed(false);
		setRequestModeRefusal(false);
		previewMutation.reset();
		cancelMutation.reset();
		onClose();
	}, [cancelMutation, onClose, previewMutation]);

	// The occurrences the series actually has. The adapter's `mapOccurrences` is the one place
	// that walks the preview's raw dates, so the "N of M" denominator below is THIS array's
	// length, never a raw preview field re-derived a second time here.
	const occurrenceViews = preview ? adapter.mapOccurrences(preview, cancelMode, t) : [];
	const cancellableCount = occurrenceViews.filter((o) => o.cancellable).length;

	const renderOverviewStep = () => {
		const fromUnix = Date.parse(entity.from_) / 1000;
		const toUnix = Date.parse(entity.to_) / 1000;
		const newBookingAllocationId = adapter.newBookingAllocationId(entity);
		const newBookingHref = newBookingAllocationId === undefined ? '' : phpGWLink('bookingfrontend/', {
			menuaction: 'bookingfrontend.uibooking.add',
			allocation_id: newBookingAllocationId,
			from_: fromUnix,
			to_: toUnix,
			resource_ids: entity.resources.map((resource) => resource.id),
		}, false);
		const registerParticipantsHref = phpGWLink('bookingfrontend/', {
			menuaction: 'bookingfrontend.uiparticipant.add',
			reservation_type: adapter.registerParticipantsType,
			reservation_id: entity.id,
		}, false);
		const editHref = phpGWLink('bookingfrontend/', {
			menuaction: adapter.editMenuaction,
			...adapter.buildEditParams(entity),
		}, false);
		const isInFuture = isFutureDate(DateTime.fromISO(entity.from_));

		return (
			<div className={styles.step}>
				{/* Design 1c :342 — two columns: content flex:1 + a fixed 300px sidebar
				    (:381). The content column here carries only what's reachable — the
				    design fields each entity's own adapter leaves out (or, for booking's
				    `activity_name`, adds back via `overviewExtraRows`) are catalogued in
				    that adapter's own docblock, not repeated here — all omitted, none invented.
				    Building and season are NOT repeated as grid rows here: the design only
				    ever carries them in the title's meta line (:335, restated at :183-185). */}
				<div className={styles.overviewLayout}>
					<div className={styles.overviewContent}>
						<div className={styles.panel}>
							<div className={styles.overviewGrid}>
								<span className={styles.overviewLabel}>{t('booking.date and time')}</span>
								<span>
									{overviewPeriodLabel}
									{overviewDurationLabel && (
										<span className={styles.overviewDuration}>{` (${overviewDurationLabel})`}</span>
									)}
								</span>

								<span className={styles.overviewLabel}>{t('booking.resources')}</span>
								<span className={styles.resourceChips}>
									{entity.resources.map((resource) => (
										<span key={resource.id} className={styles.resourceChip}>
											<ColourCircle resourceId={resource.id} size="small" className={styles.resourceChipColour}/>
											<span>{resource.name}</span>
											{typeof resource.participant_limit === 'number' && resource.participant_limit > 0 && (
												<span className={styles.resourceChipLimit}>
													{` · ${t('bookingfrontend.max_participants', {count: resource.participant_limit})}`}
												</span>
											)}
										</span>
									))}
								</span>

								{adapter.overviewExtraRows?.(entity, t)}
							</div>
						</div>
					</div>

					{/* Design :381 — a FIXED 300px sidebar: the "You are" card (:383-384)
					    then the vertical action stack (:392-397). "Participants" (:386-387,
					    no REST route) and the cancellation-deadline line (:389, its computed
					    instant is unserved) are both unreachable for either entity, so the
					    card carries "You are" alone — thinner than the mock, same shape; it
					    is not redesigned to fill the space. */}
					<div className={styles.overviewSidebar}>
						{isAdminForEntity && (
							<div className={styles.panel}>
								<span className={styles.eyebrow}>{t('bookingfrontend.you_are')}</span>
								<span>{t(adapter.youAreLangKey, adapter.youAreParams(entity))}</span>
							</div>
						)}

						<div className={styles.overviewActions}>
							{isInFuture && newBookingAllocationId !== undefined && (
								<Button asChild variant="secondary" data-color="accent" className={styles.overviewActionButton}>
									<Link href={newBookingHref} target="_blank">
										<PlusIcon/>
										{t('bookingfrontend.create new booking')}
									</Link>
								</Button>
							)}
							<Button asChild variant="secondary" data-color="accent" className={styles.overviewActionButton}>
								<Link href={registerParticipantsHref} target="_blank">
									{t('booking.register participants')}
								</Link>
							</Button>
							<Button asChild variant="secondary" data-color="accent" className={styles.overviewActionButton}>
								<Link href={editHref} target="_blank">
									{t(adapter.editLabelLangKey)}
								</Link>
							</Button>
							{/* Design :392-397 — a plain flex column of full-width buttons, not a
							    bordered card: THE action stack, not a fourth panel. The
							    destructive action is a peer among these, not a primary "next" —
							    see #21573. It still only NAVIGATES to the scope step; the real
							    mutation stays behind the confirm step untouched. Reuses
							    `cancelLabel`, the one `cancelMode` discriminator, so this control
							    cannot drift out of sync with the confirm step's own label. In
							    'unresolved' that label already reads "Utilgjengelig"/"Unavailable"
							    — disabling here keeps that word honest instead of offering a
							    clickable route into a wizard for an ability we do not know we have
							    (the same failure fixed twice already on this branch, see
							    #19746/#21573). 'loading' disables it too, for the mirrored reason
							    (#21606): this is the entry screen, the FIRST thing painted for
							    whichever entity this modal was opened for, before the settings
							    query has answered, so it is the likeliest place to render
							    mid-fetch — an enabled route into the wizard would assert an
							    ability just as unearned as the disabled button's old false
							    refusal.
							    'request' is deliberately NOT a third disabled case here (#23441).
							    Disabling this control while it still read `cancelLabel`'s "Be om
							    avbestilling" would only move the false claim, not remove it — a
							    disabled button still asserts a request path exists, merely gated
							    shut, which is the same defect the confirm step's send-button was
							    pulled for (#23430): this installation has no request-mode endpoint
							    at all, so no control here can honestly name that action, enabled or
							    disabled. The control is ABSENT in this mode instead, and the same
							    `requestModeNoticeLangKey` Alert the scope/confirm steps show is
							    surfaced here too, in its place — so the honest word ("contact the
							    building") is visible on the entry screen itself, not only reachable
							    by clicking through a route this control no longer offers.

							    `adapter.cancelReach === 'legacy'` is checked FIRST, ahead of
							    `cancelMode`, and is the only place in this file that branches on
							    it. An event's `cancelMode` is still computed above (from
							    `deleteFlagKey`, which for events names the hard-delete-vs-
							    soft-deactivate config toggle, never a request-mode arm — see the
							    CancelReach docblock at the top of this file) but is deliberately
							    NEVER consulted here: checking it first would let a false
							    `user_can_delete_events` fall into the 'request' branch below and
							    render booking's request-mode notice for a capability events do
							    not have. 'legacy' never calls `setStep` — the wizard below
							    (scope/confirm/done) is structurally unreachable for it — its
							    destructive action is a plain deep link to the guarded legacy
							    page instead, the same unconditional pattern as the edit/
							    register-participants links above it: no `cancelMode` gating,
							    since the server enforces ownership and the not-started guard
							    when the link is followed, not this client. */}
							{adapter.cancelReach === 'legacy' ? (
								<>
									{adapter.cancelExtras?.(entity, t)}
									{isInFuture && (
										<Button asChild variant="secondary" data-color="danger" className={styles.overviewActionButton}>
											<Link href={adapter.buildCancelHref!(entity)} target="_blank">
												{cancelLabel}
											</Link>
										</Button>
									)}
								</>
							) : cancelMode === 'request' ? (
								<Alert data-color="warning">
									<Paragraph data-size="sm">{t(adapter.requestModeNoticeLangKey)}</Paragraph>
								</Alert>
							) : (
								<Button
									variant="secondary"
									data-color="danger"
									className={styles.overviewActionButton}
									disabled={cancelMode === 'unresolved' || cancelMode === 'loading'}
									onClick={() => setStep('scope')}
								>
									{cancelMode === 'loading' && <Spinner aria-hidden={true} data-size="xs"/>}
									{cancelLabel}
								</Button>
							)}
						</div>
					</div>
				</div>
			</div>
		);
	};

	const renderScopeStep = () => (
		<div className={styles.step}>
			{cancelMode === 'request' && (
				<Alert data-color="warning">
					<Paragraph data-size="sm">{t(adapter.requestModeNoticeLangKey)}</Paragraph>
				</Alert>
			)}

			<div className={styles.panel}>
				<Fieldset>
					<Fieldset.Legend className={styles.panelTitle}>
						{t('bookingfrontend.what_should_be_cancelled')}
					</Fieldset.Legend>

					<div className={styles.scopeOption}>
						<Radio
							name="manage-modal-cancel-scope"
							value="occurrence"
							checked={scope === 'occurrence'}
							onChange={() => setScope('occurrence')}
							label={t('bookingfrontend.cancel_scope_occurrence', {date: occurrenceLabel})}
						/>
						<span className={styles.scopeDetail}>
							{t('bookingfrontend.cancel_scope_occurrence_detail')}
						</span>
					</div>

					<div className={styles.scopeOption}>
						<Radio
							name="manage-modal-cancel-scope"
							value="season"
							checked={scope === 'season'}
							onChange={() => setScope('season')}
							label={t('bookingfrontend.cancel_scope_season')}
						/>
						<span className={styles.scopeDetail}>
							{t('bookingfrontend.cancel_scope_season_detail')}
						</span>
					</div>

					<div className={styles.scopeOption}>
						<Radio
							name="manage-modal-cancel-scope"
							value="until"
							checked={scope === 'until'}
							onChange={() => setScope('until')}
							label={t('bookingfrontend.cancel_scope_until')}
						/>
						{scope === 'until' && (
							<div className={styles.untilFields}>
								<Textfield
									className={styles.untilField}
									type="date"
									label={t('bookingfrontend.cancel_until_date')}
									value={repeatUntil}
									onChange={(e) => setRepeatUntil(e.target.value)}
								/>
								<Textfield
									className={styles.intervalField}
									type="number"
									min={1}
									label={t('bookingfrontend.cancel_every_n_weeks')}
									value={fieldInterval}
									onChange={(e) => setFieldInterval(e.target.value)}
								/>
							</div>
						)}
					</div>
				</Fieldset>
			</div>

			{adapter.scopeExtras?.(extraState, setExtraState, t)}

			<div className={styles.panel}>
				<Label htmlFor="manage-modal-cancel-message" className={styles.panelTitle}>
					{t('bookingfrontend.message_to_building')}
				</Label>
				<Textarea
					id="manage-modal-cancel-message"
					rows={3}
					value={message}
					onChange={(e) => setMessage(e.target.value)}
				/>
			</div>

			{previewMutation.isError && (
				<Alert data-color="danger">
					<Paragraph data-size="sm">{previewMutation.error?.message}</Paragraph>
				</Alert>
			)}
		</div>
	);

	const renderOccurrenceRow = (occurrence: ManageModalOccurrenceView) => (
		<div className={styles.occurrenceRow} key={occurrence.key}>
			<span className={styles.occurrenceWhen}>
				<span className={`${styles.statusDot} ${occurrence.dotClass}`} aria-hidden={true}/>
				<span>{occurrence.when}</span>
			</span>
			<span className={styles.occurrenceNote}>
				{occurrence.note}
			</span>
		</div>
	);

	const renderConfirmStep = () => {
		if (!preview) {
			return null;
		}

		return (
			<div className={styles.step}>
				{staleRepreviewed && (
					<Alert data-color="warning">
						<Paragraph data-size="sm">{t(adapter.seriesChangedLangKey)}</Paragraph>
					</Alert>
				)}

				{/* Shown proactively in 'request' mode, not only after a failed attempt: the
				    server-side gap (no request-mode endpoint) is already known before the user
				    reaches this step, so there is nothing to wait for. `requestModeRefusal` still
				    covers the TOCTOU case where `cancelMode` locally read 'delete' (the button
				    below was shown) but the server disagreed at submit time. */}
				{(cancelMode === 'request' || requestModeRefusal) && (
					<Alert data-color="warning">
						<Paragraph data-size="sm">{t(adapter.requestModeNoticeLangKey)}</Paragraph>
					</Alert>
				)}

				{adapter.confirmExtras(preview, extraState, t)}

				<div className={styles.occurrenceList}>
					{occurrenceViews.map(renderOccurrenceRow)}
				</div>

				{adapter.emptyCount(preview) > 0 && (
					<span className={styles.mutedFootnote}>
						{t(adapter.datesWithoutLangKey, {count: adapter.emptyCount(preview)})}
					</span>
				)}

				{message.trim() !== '' && (
					<div className={styles.panel}>
						<span className={styles.eyebrow}>{t('bookingfrontend.message_to_building')}</span>
						<span className={styles.messageEcho}>{message}</span>
					</div>
				)}

				{cancelMutation.isError && !requestModeRefusal && !staleRepreviewed && (
					<Alert data-color="danger">
						<Paragraph data-size="sm">{cancelMutation.error?.message}</Paragraph>
					</Alert>
				)}
			</div>
		);
	};

	const renderDoneStep = () => {
		if (!result) {
			return null;
		}
		return (
			<div className={styles.step}>
				{adapter.doneSummary(result, t)}
			</div>
		);
	};

	// Design :332-338 — the modal's chrome carries the tag + "#id · season · building" meta
	// line and the prominent heading. For the overview step that heading is the entity's own
	// name (:337), an H1, replacing the boilerplate "Administrer …" text the chrome used to
	// read here. The other three steps' eyebrow + step heading are untouched below, including
	// the #19526 cancelMode read.
	const titleName = adapter.titleName(entity);
	const titleNameHeading = (
		<h1
			ref={titleNameRef}
			className={styles.overviewOrgName}
			tabIndex={isTitleNameTruncated ? 0 : undefined}
		>
			{titleName}
		</h1>
	);

	const title = step === 'overview' ? (
		<div>
			<div className={styles.titleMetaRow}>
				<Tag data-color="accent" className={styles.overviewTypeTag}>
					{t(adapter.typeTagLangKey)}
				</Tag>
				<span className={styles.eyebrow}>
					{`#${entity.id} · ${seasonDisplay} · ${entity.building_name}`}
				</span>
			</div>
			{isTitleNameTruncated
				? <Tooltip content={titleName}>{titleNameHeading}</Tooltip>
				: titleNameHeading}
		</div>
	) : (
		<div>
			<span className={styles.eyebrow}>
				{step === 'scope' && `${t('bookingfrontend.step_1_of_2')} · #${entity.id}`}
				{step === 'confirm' && `${t('bookingfrontend.step_2_of_2')} · #${entity.id}`}
				{step === 'done' && `#${entity.id}`}
			</span>
			<Heading level={2} data-size="xs" className={styles.stepTitle}>
				{step === 'confirm'
					// #19526/#23430: this heading MUST NOT assert cancellability except in the ONE
					// mode where the confirm button below can actually back that claim up — 'delete'.
					// 'unresolved' and 'loading' fall back to `cancelLabel` for the reason #19526
					// gave (this step is normally unreachable before the setting resolves, but reads
					// the same discriminator anyway rather than assuming that guard always holds);
					// 'request' falls back to the same label for a new reason (#23430): the backend
					// has no request-mode endpoint, so "N occurrences can be cancelled" would be a
					// claim this screen cannot honour any more than the button that used to sit
					// beside it could. Reusing cancelLabel keeps ONE discriminator driving every
					// surface instead of adding a second expression per mode.
					? (cancelMode === 'delete'
						? t('bookingfrontend.occurrences_can_be_cancelled', {
							cancellable: cancellableCount,
							total: occurrenceViews.length,
						})
						: cancelLabel)
					: cancelLabel}
			</Heading>
		</div>
	);

	const footer = (
		<div className={styles.footer}>
			{/* On the result step the only action left is closing, and it is the primary button
			    on the right — a second "Close" on the left would just be the same action twice. */}
			{step !== 'done' ? (
				<Button
					variant="tertiary"
					onClick={
						step === 'confirm' ? () => setStep('scope')
							: step === 'scope' ? () => setStep('overview')
								: handleClose
					}
				>
					{step === 'overview' ? t('bookingfrontend.close') : t('bookingfrontend.back')}
				</Button>
			) : <span/>}
			<div className={styles.footerActions}>
				{/* The overview no longer carries a primary forward button — see #21573. It is the
				    destination screen, not step 1 of a cancellation wizard; its only footer control
				    is the tertiary "Lukk" above. Cancellation now lives in `overviewActions`, in the
				    sidebar's vertical stack (#21603), as a peer alongside new booking / register
				    participants / edit, navigating to the scope step exactly as this control used to. */}
				{step === 'scope' && (
					<Button
						variant="primary"
						data-color="accent"
						// Carried in from #23430 (⑫): this button's two siblings (the overview
						// button above and the confirm step's destructive button below) both
						// disable on `cancelMode === 'unresolved' || cancelMode === 'loading'`;
						// this one didn't. Unreachable today — the overview button that navigates
						// here is itself disabled in both those modes — but a one-term addition
						// for the same reason its siblings state theirs, not a second discriminator.
						disabled={previewMutation.isPending || (scope === 'until' && repeatUntil === '') || cancelMode === 'unresolved' || cancelMode === 'loading'}
						onClick={goToConfirm}
					>
						{previewMutation.isPending && <Spinner aria-hidden={true} data-size="xs"/>}
						{t('bookingfrontend.review_and_confirm')}
					</Button>
				)}
				{/* `cancelMode` gates the affordance itself, not just its wording: this onClick
				    is the real delete in every mode, so the button must not be clickable in a
				    state whose consequences the client cannot describe — 'unresolved' because
				    it never learned them, 'loading' because it has not been told them yet.
				    'request' is absent from this render entirely, not merely disabled (#23430):
				    the server has no request-mode endpoint at all, so a control offering to
				    "send" one would promise an action this installation cannot perform, no
				    matter its enabled state. The Alert above already discloses the gap; the
				    "Back"/"Close" control in the footer's other slot remains the only way
				    forward from here in this mode. */}
				{step === 'confirm' && cancelMode !== 'request' && (
					<Button
						variant="primary"
						data-color="danger"
						disabled={cancelMutation.isPending || cancellableCount === 0 || cancelMode === 'unresolved' || cancelMode === 'loading'}
						onClick={confirmCancel}
					>
						{(cancelMutation.isPending || cancelMode === 'loading') && <Spinner aria-hidden={true} data-size="xs"/>}
						{cancelMode === 'unresolved' || cancelMode === 'loading'
							? cancelLabel
							: t('bookingfrontend.cancel_n_occurrences', {count: cancellableCount})}
					</Button>
				)}
				{step === 'done' && (
					<Button variant="primary" data-color="accent" onClick={handleClose}>
						{t('bookingfrontend.close')}
					</Button>
				)}
			</div>
		</div>
	);

	return (
		<Dialog
			open={open}
			onClose={handleClose}
			dialogId={`${adapter.dialogIdPrefix}-${entity.id}`}
			title={title}
			footer={footer}
			closeOnBackdropClick={false}
		>
			{step === 'overview' && renderOverviewStep()}
			{step === 'scope' && renderScopeStep()}
			{step === 'confirm' && renderConfirmStep()}
			{step === 'done' && renderDoneStep()}
		</Dialog>
	);
}

export default ManageModal;
