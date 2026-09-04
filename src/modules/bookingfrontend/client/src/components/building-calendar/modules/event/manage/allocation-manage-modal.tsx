'use client'
import React, {FC, useCallback, useMemo, useRef, useState} from 'react';
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
import {IAPIAllocation} from "@/service/pecalendar.types";
import {isFutureDate, phpGWLink} from "@/service/util";
import styles from "./allocation-manage-modal.module.scss";
import {
	AllocationCancelScope,
	IAllocationCancelOccurrence,
	IAllocationCancelPreview,
	IAllocationCancelResult,
	isDeadBlocked,
	realOccurrences,
} from "@/service/api/allocation-cancellation";
import {useAllocationCancel, useAllocationCancelPreview} from "@/service/hooks/allocation-cancellation-hooks";

interface AllocationManageModalProps {
	allocation: IAPIAllocation;
	open: boolean;
	onClose: () => void;
}

type Step = 'overview' | 'scope' | 'confirm' | 'done';

/**
 * Design 1c — the allocation management modal, now opening on the OVERVIEW screen 1c draws
 * before its two-step cancellation.
 *
 * WHAT THE OVERVIEW STILL DOES NOT RENDER, and why. The design's overview also carries the
 * organisation's contact person, the owning application number and its approval date, the full
 * "3 bookings under it" list, the comment thread, the participant count and the computed
 * cancellation deadline. The design→backend contract measured those one by one: the contact
 * person and phone are the legacy `contacts[0]` entity and are not on the served Organization;
 * `application_id` is deliberately not exposed on the allocation payload (present in the TS type,
 * `nullable` + no `@Expose`, so it is `undefined` at runtime and invisible to `tsc`); there is no
 * bb_allocation_comment table at all; and the deadline's computed instant is not served for an
 * allocation. Blocking-booking names ARE reachable — `blocking_bookings[].group_name` is already
 * rendered by this same file, on the confirm step, once `cancel-preview` has run — so that list is
 * deferred one screen later than the design draws it, not unbuildable. None of the rest is
 * reachable, so none of it is drawn. What IS reachable — organisation, building, resources (with
 * their per-resource participant limits and colours), the occurrence's period and duration, the
 * season's name, the allocation's type and id, and whether the viewer administers the owning
 * organisation — comes straight off the `allocation` prop and the booking user already available
 * on open (season name resolved via `useBuildingSeasons`); no `cancel-preview` call is made to
 * build this screen, since that mutation only fires once the user has chosen to cancel.
 *
 * The recipient recap the design draws in step 2 ("To: case worker · 6 user organisations …") is
 * likewise absent: nothing in the shipped endpoint computes or returns a recipient set.
 */
const AllocationManageModal: FC<AllocationManageModalProps> = ({allocation, open, onClose}) => {
	const {t, i18n} = useClientTranslation();
	const serverSettings = useServerSettings();
	const currentBuilding = useCurrentBuilding();
	const {data: bookingUser} = useBookingUser();
	const isAdminForAllocation = isOrgAdmin(bookingUser, allocation);

	const buildingId = typeof currentBuilding === 'string'
		? Number(currentBuilding)
		: currentBuilding;

	// `season_id` is on the prop; the season's NAME is not, so it is resolved through the same
	// hook the calendar itself uses. This is a read already cached under ['building_seasons',
	// buildingId] whenever the calendar view is open behind this modal.
	const buildingSeasons = useBuildingSeasons(Number.isFinite(buildingId as number) ? buildingId as number : undefined);
	const seasonName = buildingSeasons.data?.find((season) => season.id === allocation.season_id)?.name;
	// Feeds the "#id · season · building" meta line in the modal's title chrome (design
	// :335) — the design never draws season as its own grid row, so that meta line is
	// its only consumer (#21603). Three-state fallback: #19645, resolved / loading / neutral.
	const seasonDisplay = seasonName ? seasonName : buildingSeasons.isLoading ? t('bookingfrontend.loading...') : '—';

	const [step, setStep] = useState<Step>('overview');
	const [scope, setScope] = useState<AllocationCancelScope>('occurrence');
	const [repeatUntil, setRepeatUntil] = useState<string>('');
	const [fieldInterval, setFieldInterval] = useState<string>('1');
	const [message, setMessage] = useState<string>('');
	const [preview, setPreview] = useState<IAllocationCancelPreview | null>(null);
	const [result, setResult] = useState<IAllocationCancelResult | null>(null);
	const [staleRepreviewed, setStaleRepreviewed] = useState<boolean>(false);
	const [requestModeRefusal, setRequestModeRefusal] = useState<boolean>(false);

	const previewMutation = useAllocationCancelPreview();
	const cancelMutation = useAllocationCancel(Number.isFinite(buildingId as number) ? buildingId as number : undefined);

	/**
	 * Request mode.
	 *
	 * Gated on `=== true`, NEVER on truthiness. SerializableTrait::parseStringBoolean coerces
	 * only yes|true|1|no|false|''|0 and returns any other stored string UNCHANGED, so an admin
	 * value the parser does not know — 'never' is selectable for allocations on
	 * booking/templates/base/settings.xsl — arrives here as a non-empty, therefore truthy,
	 * string while every PHP consumer reads it as off. `if (flag)` would switch the affordance
	 * ON under the most restrictive setting available. With cancel gone from the popper card,
	 * this modal is the only remaining Lane-3 reader of this flag.
	 *
	 * RENDER ONLY. No request or notification flow is built here: the server answers the
	 * request-mode branch with 409 rather than porting legacy's case-worker notification, and
	 * making that branch reachable from this client would be new behaviour, not a port.
	 *
	 * THREE STATES, NOT TWO — and this is the whole point of the shape below.
	 *
	 * The flag can be known-TRUE, known-FALSE, or NOT KNOWN: the settings query is in flight,
	 * or it failed, or it answered without a booking_config at all. `!canDelete` collapsed the
	 * last two of those into request mode, so a client that had been told NOTHING rendered the
	 * confident claim "this municipality does not let you delete" and offered a button reading
	 * "send a request" whose onClick has always been the real, immediate delete. Label and
	 * action were driven by two different expressions and only one of them was honest.
	 *
	 * It is the same failure as reading this flag with `if (flag)` — a two-valued expression
	 * standing in for a world with more than two values — and it is why `canDelete === false`
	 * alone is NOT the fix: that only moves the collapse from one branch to the other. Both
	 * user-facing modes must therefore be positively known, and the unresolved state is a
	 * THIRD outcome that asserts nothing and offers nothing.
	 */
	const bookingConfig = serverSettings.data?.booking_config;
	const settingsUnresolved = serverSettings.isPending || serverSettings.isError || bookingConfig == null;
	const canDelete = bookingConfig?.user_can_delete_allocations === true;
	const isRequestMode = !settingsUnresolved && !canDelete;

	/**
	 * ONE discriminator, read by every surface that names the mode.
	 *
	 * The step title and the confirm button are never on screen together — the title belongs to
	 * the scope step, the button to the confirm step — so nothing on the page makes it visible
	 * when the two disagree. They did: the button had already been made three-way while this
	 * label stayed two-valued, and in the unresolved state the heading went on announcing
	 * "Avbestill tildeling" — the confident claim, from the branch that is merely NOT request
	 * mode. Deriving the mode once and switching both on it is the fix; adding a second
	 * three-way expression beside this one would only move the collapse one line over.
	 */
	const cancelMode: 'unresolved' | 'request' | 'delete' =
		settingsUnresolved ? 'unresolved' : isRequestMode ? 'request' : 'delete';

	const cancelLabel = cancelMode === 'unresolved'
		? t('bookingfrontend.cancel_mode_unavailable')
		: cancelMode === 'request'
			? t('bookingfrontend.request_cancellation')
			: t('bookingfrontend.cancel_allocation');

	const occurrenceLabel = useMemo(() => {
		const from = DateTime.fromISO(allocation.from_ as unknown as string);
		return from.isValid ? from.toFormat('cccc d. LLLL yyyy') : String(allocation.from_);
	}, [allocation.from_]);

	// The overview's period cell: the same date as occurrenceLabel, plus the start-end times.
	const overviewPeriodLabel = useMemo(() => {
		const from = DateTime.fromISO(allocation.from_ as unknown as string);
		const to = DateTime.fromISO(allocation.to_ as unknown as string);
		if (!from.isValid) {
			return String(allocation.from_);
		}
		return to.isValid
			? `${occurrenceLabel}, ${from.toFormat('HH:mm')}–${to.toFormat('HH:mm')}`
			: occurrenceLabel;
	}, [allocation.from_, allocation.to_, occurrenceLabel]);

	// The overview's "(2 h)" duration note. `from_`/`to_` are both served, so this is
	// arithmetic on served data — never a field the server sends, and never a stand-in
	// for the design's recurrence text (which has no source field at all, see 21181).
	const overviewDurationLabel = useMemo(() => {
		const from = DateTime.fromISO(allocation.from_ as unknown as string);
		const to = DateTime.fromISO(allocation.to_ as unknown as string);
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
	}, [allocation.from_, allocation.to_, t, i18n.language]);

	/**
	 * The long-organisation-name reveal, same technique as the popper card's title (#19569): a
	 * ResizeObserver on the heading itself decides truncation, and the Tooltip node is only
	 * mounted — so only tabbable and only announced — when the name actually overflows. A
	 * short name never gets the affordance at all, not just a closed one.
	 */
	const [isOrgNameTruncated, setIsOrgNameTruncated] = useState(false);
	const orgNameResizeObserver = useRef<ResizeObserver | null>(null);
	const measureOrgNameTruncation = useCallback((el: HTMLHeadingElement) => {
		setIsOrgNameTruncated(el.scrollWidth > el.clientWidth);
	}, []);
	const orgNameRef = useCallback((el: HTMLHeadingElement | null) => {
		orgNameResizeObserver.current?.disconnect();
		orgNameResizeObserver.current = null;
		if (el) {
			measureOrgNameTruncation(el);
			orgNameResizeObserver.current = new ResizeObserver(() => measureOrgNameTruncation(el));
			orgNameResizeObserver.current.observe(el);
		}
	}, [measureOrgNameTruncation]);

	const formatOccurrence = useCallback((occurrence: IAllocationCancelOccurrence) => {
		const from = DateTime.fromSQL(occurrence.from_);
		const to = DateTime.fromSQL(occurrence.to_);
		if (!from.isValid) {
			return occurrence.from_;
		}
		return to.isValid
			? `${from.toFormat('ccc d. LLL yyyy')}, ${from.toFormat('HH:mm')} – ${to.toFormat('HH:mm')}`
			: from.toFormat('ccc d. LLL yyyy HH:mm');
	}, []);

	const requestBody = useCallback(() => ({
		scope,
		...(scope === 'until' ? {repeat_until: repeatUntil} : {}),
		field_interval: Number(fieldInterval) || 1,
		message,
	}), [scope, repeatUntil, fieldInterval, message]);

	const runPreview = useCallback(async () => {
		const fresh = await previewMutation.mutateAsync({
			allocationId: allocation.id,
			body: requestBody(),
		});
		setPreview(fresh);
		return fresh;
	}, [allocation.id, previewMutation, requestBody]);

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
				allocationId: allocation.id,
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
	}, [allocation.id, cancelMutation, preview, requestBody, runPreview]);

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

	// The occurrences the series actually has. `preview.total` counts every date the walk
	// visited, including weeks with no allocation at all, so it is not the denominator the
	// design's "N of M" means.
	const existing = preview ? realOccurrences(preview) : [];
	const cancellableCount = existing.filter((o) => o.cancellable).length;

	const renderOverviewStep = () => {
		// Same shape as allocation-popper-actions.tsx's own "+ New booking" link — this
		// modal is a SECOND consumer of that route, not a replacement for the card's button.
		const fromUnix = Date.parse(allocation.from_) / 1000;
		const toUnix = Date.parse(allocation.to_) / 1000;
		const newBookingHref = phpGWLink('bookingfrontend/', {
			menuaction: 'bookingfrontend.uibooking.add',
			allocation_id: allocation.id,
			from_: fromUnix,
			to_: toUnix,
			resource_ids: allocation.resources.map((resource) => resource.id),
		}, false);
		// Same menuaction uiallocation.inc.php:860-863 already builds server-side.
		const registerParticipantsHref = phpGWLink('bookingfrontend/', {
			menuaction: 'bookingfrontend.uiparticipant.add',
			reservation_type: 'allocation',
			reservation_id: allocation.id,
		}, false);
		// ONE edit control, to the one edit form that exists — never a per-field deep-link,
		// and never worded as a request: uiallocation.edit performs a direct edit.
		const editHref = phpGWLink('bookingfrontend/', {
			menuaction: 'bookingfrontend.uiallocation.edit',
			allocation_id: allocation.id,
		}, false);
		const isInFuture = isFutureDate(DateTime.fromISO(allocation.from_));

		return (
			<div className={styles.step}>
				{/* Design 1c :343 — two columns: content flex:1 + a fixed 300px sidebar
				    (:382). The content column here carries only what's reachable: the
				    design also draws "Series" (:347-348, no source field — see #21181),
				    "Organisation" contact (:359-360, legacy contacts[0]), "Application"
				    (:361-362, no @Expose on the allocation payload), "Bookings under it"
				    (:363-364, deferred to the confirm step where
				    blocking_bookings[].group_name is actually served) and the comment
				    thread (:367-378, no bb_allocation_comment table) — all omitted, none
				    invented. Building and season are NOT repeated as grid rows here: the
				    design only ever carries them in the title's meta line (:335). */}
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
									{allocation.resources.map((resource) => (
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
							</div>
						</div>
					</div>

					{/* Design :382 — a FIXED 300px sidebar: the "You are" card (:383-384)
					    then the vertical action stack (:392-397). "Participants" (:386-387,
					    no REST route) and the cancellation-deadline line (:389, its computed
					    instant is unserved) are both unreachable, so the card carries "You
					    are" alone — thinner than the mock, same shape; it is not redesigned
					    to fill the space. */}
					<div className={styles.overviewSidebar}>
						{isAdminForAllocation && (
							<div className={styles.panel}>
								<span className={styles.eyebrow}>{t('bookingfrontend.you_are')}</span>
								<span>{t('bookingfrontend.admin_for_organization', {organization: allocation.organization_name})}</span>
							</div>
						)}

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
						    #19746/#21573). */}
						<div className={styles.overviewActions}>
							{isInFuture && (
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
									{t('bookingfrontend.edit allocation')}
								</Link>
							</Button>
							<Button
								variant="secondary"
								data-color="danger"
								className={styles.overviewActionButton}
								disabled={cancelMode === 'unresolved'}
								onClick={() => setStep('scope')}
							>
								{cancelLabel}
							</Button>
						</div>
					</div>
				</div>
			</div>
		);
	};

	const renderScopeStep = () => (
		<div className={styles.step}>
			{isRequestMode && (
				<Alert data-color="warning">
					<Paragraph data-size="sm">{t('bookingfrontend.allocation_request_mode_notice')}</Paragraph>
				</Alert>
			)}

			<div className={styles.panel}>
				<Fieldset>
					<Fieldset.Legend className={styles.panelTitle}>
						{t('bookingfrontend.what_should_be_cancelled')}
					</Fieldset.Legend>

					<div className={styles.scopeOption}>
						<Radio
							name="allocation-cancel-scope"
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
							name="allocation-cancel-scope"
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
							name="allocation-cancel-scope"
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

			<div className={styles.panel}>
				<Label htmlFor="allocation-cancel-message" className={styles.panelTitle}>
					{t('bookingfrontend.message_to_building')}
				</Label>
				<Textarea
					id="allocation-cancel-message"
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

	const renderOccurrenceRow = (occurrence: IAllocationCancelOccurrence) => {
		const dead = isDeadBlocked(occurrence);
		const blocker = occurrence.blocking_bookings[0];
		// #19645: `occurrence.cancellable` answers "is this date blocked by a booking
		// underneath", not "may you cancel" — the same distinction the confirm
		// heading (:526) already draws on `cancelMode`. Reusing that ONE
		// discriminator here — instead of a second expression — means an
		// unresolved setting can no longer show a green dot and "Kan avbestilles"
		// beside a button reading "Utilgjengelig". Blocked rows are untouched:
		// they answer a question that has nothing to do with the setting.
		const assertsCancellable = occurrence.cancellable && cancelMode !== 'unresolved';
		const dotClass = !occurrence.cancellable
			? (dead ? styles.blockedDead : styles.blockedLive)
			: assertsCancellable
				? styles.cancellable
				: '';

		return (
			<div className={styles.occurrenceRow} key={`${occurrence.index}-${occurrence.from_}`}>
				<span className={styles.occurrenceWhen}>
					<span className={`${styles.statusDot} ${dotClass}`} aria-hidden={true}/>
					<span>{formatOccurrence(occurrence)}</span>
				</span>
				<span className={styles.occurrenceNote}>
					{assertsCancellable && t('bookingfrontend.free_to_cancel')}
					{!occurrence.cancellable && blocker && (
						<>
							<span className={styles.blockerDetail}>
								{dead
									? t('bookingfrontend.blocked_by_inactive_booking', {
										group: blocker.group_name ?? '',
										id: blocker.id,
									})
									: t('bookingfrontend.blocked_by_live_booking', {
										group: blocker.group_name ?? '',
										id: blocker.id,
									})}
							</span>
							{dead && (
								<span className={styles.blockerDetail}>
									{t('bookingfrontend.blocked_by_inactive_booking_help')}
								</span>
							)}
						</>
					)}
				</span>
			</div>
		);
	};

	const renderConfirmStep = () => {
		if (!preview) {
			return null;
		}

		return (
			<div className={styles.step}>
				{staleRepreviewed && (
					<Alert data-color="warning">
						<Paragraph data-size="sm">{t('bookingfrontend.allocation_series_changed_re_previewed')}</Paragraph>
					</Alert>
				)}

				{requestModeRefusal && (
					<Alert data-color="warning">
						<Paragraph data-size="sm">{t('bookingfrontend.allocation_request_mode_notice')}</Paragraph>
					</Alert>
				)}

				<div className={styles.occurrenceList}>
					{existing.map(renderOccurrenceRow)}
				</div>

				{preview.no_allocation > 0 && (
					<span className={styles.mutedFootnote}>
						{t('bookingfrontend.dates_without_allocation', {count: preview.no_allocation})}
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
		// `skipped_count` counts every date the walk visited and did not delete, which includes
		// the weeks that never had an allocation at all. Reporting that number back as
		// "N occurrences were not cancelled" would tell the user the flow spared occurrences
		// that do not exist. Only genuinely blocked occurrences are counted here, for the same
		// reason the step-2 denominator excludes them.
		const skippedReal = result.skipped.filter((s) => s.status !== 'no_allocation').length;

		return (
			<div className={styles.step}>
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
			</div>
		);
	};

	// Design :332-338 — the modal's chrome carries the tag + "#id · season · building"
	// meta line and the prominent heading. For the overview step that heading is the
	// ORG NAME (:337), an H1, replacing the boilerplate "Administrer tildeling" text the
	// chrome used to read here. The other three steps' eyebrow + step heading are
	// untouched below, including the #19526 cancelMode read.
	const orgNameHeading = (
		<h1
			ref={orgNameRef}
			className={styles.overviewOrgName}
			tabIndex={isOrgNameTruncated ? 0 : undefined}
		>
			{allocation.organization_name}
		</h1>
	);

	const title = step === 'overview' ? (
		<div>
			<div className={styles.titleMetaRow}>
				<Tag data-color="accent" className={styles.overviewTypeTag}>
					{t('bookingfrontend.allocation')}
				</Tag>
				<span className={styles.eyebrow}>
					{`#${allocation.id} · ${seasonDisplay} · ${allocation.building_name}`}
				</span>
			</div>
			{isOrgNameTruncated
				? <Tooltip content={allocation.organization_name}>{orgNameHeading}</Tooltip>
				: orgNameHeading}
		</div>
	) : (
		<div>
			<span className={styles.eyebrow}>
				{step === 'scope' && `${t('bookingfrontend.step_1_of_2')} · #${allocation.id}`}
				{step === 'confirm' && `${t('bookingfrontend.step_2_of_2')} · #${allocation.id}`}
				{step === 'done' && `#${allocation.id}`}
			</span>
			<Heading level={2} data-size="xs" className={styles.stepTitle}>
				{step === 'confirm'
					// #19526: this heading MUST NOT assert cancellability while the setting that
					// decides it is unresolved — reusing the ONE `cancelMode` discriminator
					// (bookingfrontend.cancel_mode_unavailable, the same "Utilgjengelig" text the
					// confirm button already shows in this state) rather than adding a second one.
					? (cancelMode === 'unresolved'
						? cancelLabel
						: t('bookingfrontend.occurrences_can_be_cancelled', {
							cancellable: cancellableCount,
							total: existing.length,
						}))
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
						disabled={previewMutation.isPending || (scope === 'until' && repeatUntil === '')}
						onClick={goToConfirm}
					>
						{previewMutation.isPending && <Spinner aria-hidden={true} data-size="xs"/>}
						{t('bookingfrontend.review_and_confirm')}
					</Button>
				)}
				{/* `settingsUnresolved` gates the affordance itself, not just its wording: this
				    onClick is the real delete in every mode, so the button must not be clickable
				    in a state whose consequences the client cannot describe. */}
				{step === 'confirm' && (
					<Button
						variant="primary"
						data-color="danger"
						disabled={cancelMutation.isPending || cancellableCount === 0 || settingsUnresolved}
						onClick={confirmCancel}
					>
						{cancelMutation.isPending && <Spinner aria-hidden={true} data-size="xs"/>}
						{cancelMode === 'unresolved'
							? t('bookingfrontend.cancel_mode_unavailable')
							: cancelMode === 'request'
								? t('bookingfrontend.send_request_for_n_occurrences', {count: cancellableCount})
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
			dialogId={`allocation-manage-${allocation.id}`}
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
};

export default AllocationManageModal;
