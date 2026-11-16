'use client';
import React, {FC, useMemo, useState} from 'react';
import {Alert, Button, Heading, Paragraph, Table, Tag} from '@digdir/designsystemet-react';
import {PencilIcon, PlusIcon, TrashIcon} from '@navikt/aksel-icons';
import {useClientTranslation} from '@/app/i18n/ClientTranslationProvider';
import {IApplication} from '@/service/types/api/application.types';
import {
    HospitalityActionReason,
    IHospitality,
    IHospitalityOrder,
} from '@/service/types/api/hospitality.types';
import {
    useAvailableHospitalities,
    useDeleteHospitalityOrder,
    useHospitalityOrders,
} from '@/components/checkout/hooks/hospitality-hooks';
import HospitalityOrderModal from '@/components/checkout/hospitality/hospitality-order-modal';
import {useServerSettings} from '@/service/hooks/api-hooks';
import {
    applicationPriceSummary,
    formatOreOrDash,
    formatQuantity,
    hospitalityLineTotalOre,
    hospitalityOrderTotalOre,
    hospitalityUnitPriceIncl,
} from '@/utils/article-pricing';
import {formatCurrency} from '@/utils/cost-utils';
import {ruleGroups} from '@/utils/catering-deadline-rule';
import {cateringEnding, isCateringClosed, showsCateringSection, showsPriceSummary} from '@/utils/catering-status';
import {groupByServingDay} from '@/utils/catering-venue-time';
import {uniqueKitchenContacts} from '@/utils/kitchen-contacts';
import CateringCancelDialog from './catering-cancel-dialog';
import CateringDeadlineAlert from './catering-deadline-alert';
import KitchenContact from './kitchen-contact';
import CateringPriceSummary from './catering-price-summary';
import styles from './application-details.module.scss';
import cateringStyles from './catering-overview.module.scss';

/** Application statuses in which catering may still be handled from Min side. */
const HANDLEABLE_STATUSES = ['NEW', 'PENDING', 'ACCEPTED'];

/** Refusals that are about a deadline; any other reason (billed, paid, ...) gets the generic text. */
const DEADLINE_REASONS: ReadonlySet<HospitalityActionReason> = new Set<HospitalityActionReason>([
    'order_cutoff_passed',
    'cancellation_deadline_passed',
    'serving_time_passed',
]);

const STATUS_COLOR: Record<IHospitalityOrder['status'], 'warning' | 'success' | 'neutral' | 'danger'> = {
    pending: 'warning',
    confirmed: 'success',
    delivered: 'neutral',
    cancelled: 'danger',
};

const STATUS_LABEL_KEY: Record<IHospitalityOrder['status'], string> = {
    pending: 'booking.pending_status',
    confirmed: 'booking.confirmed',
    delivered: 'booking.delivered',
    cancelled: 'booking.cancelled',
};

interface CateringOverviewProps {
    application: IApplication;
}

/**
 * What was ordered in the way of catering for this application, with change / cancel while the
 * deadlines allow it (GH #1423), laid out after the Min side design A: a section of the application's price
 * surface, each serving on a grey surface under a «<day> kl. <time> · <place>» heading with the offer's name
 * below, the lines as «N × price», the change / cancel rule stated ONCE in an Alert at the top, the kitchen's
 * contact ONCE per kitchen under it, and the section's own total at the end (cateringEnding).
 *
 * The server decides what is allowed (`order.actions`, computed by HospitalityOrderGuard) and
 * enforces it again on every write; this component only offers what the server says is open. The Alert
 * explains the rule from the offers' configuration; it never decides a button. A closed action is gone, and a
 * lock that is not about a deadline (billed, paid, ...) still says so; who to contact is the kitchen's contact
 * at the top of the section. It reuses the cart's order dialog for adding and changing.
 *
 * On a rejected or cancelled application (`readOnly`) the orders already placed stay on view, with
 * a note and the kitchen's contact, but nothing is offered: no add, change or cancel, no deadline
 * rule and no locked-Alert, and the offer list is not even fetched.
 *
 * Renders nothing when catering is switched off, when none is offered for the booked resources and
 * nothing is ordered (on a read-only application: when nothing is ordered), while loading, and when
 * the orders cannot be read (a visitor who holds only the application's secret link is not let into
 * the catering endpoints).
 *
 * The whole application's price summary (room rental, equipment and services, catering, then the sum ex VAT, the
 * VAT and the total) is the block that closes the price surface after this section, and only where the stored
 * order lines are known not to hold a billed catering order too. It is the one place the VAT is broken down; where
 * it is not drawn, this section keeps the catering's own breakdown.
 */
const CateringOverview: FC<CateringOverviewProps> = ({application}) => {
    const {t, i18n} = useClientTranslation();
    const {data: serverSettings} = useServerSettings();
    const enabled = !!serverSettings?.booking_config?.enable_hospitality;
    const readOnly = isCateringClosed(application.status);

    const ordersQuery = useHospitalityOrders(enabled ? application.id : undefined);
    const hospitalitiesQuery = useAvailableHospitalities(enabled && !readOnly ? application.id : undefined);
    const deleteMutation = useDeleteHospitalityOrder(application.id);

    const [modalOpen, setModalOpen] = useState(false);
    const [activeHospitality, setActiveHospitality] = useState<IHospitality | null>(null);
    const [editingOrder, setEditingOrder] = useState<IHospitalityOrder | undefined>(undefined);
    const [cancelTarget, setCancelTarget] = useState<IHospitalityOrder | null>(null);
    const [cancelErrors, setCancelErrors] = useState<Record<number, string>>({});

    // The cart's dialog keeps its form state in effects keyed on this array: it must be stable.
    const applications = useMemo(() => [application], [application]);

    const hospitalities = hospitalitiesQuery.data ?? [];
    // Cancelled orders are not shown (nor counted); the officer's changelog keeps them.
    const orders = useMemo(
        () => (ordersQuery.data ?? []).filter(order => order.status !== 'cancelled'),
        [ordersQuery.data]
    );

    // The offer query is switched off on a read-only application. react-query v5 reports isLoading
    // false for a disabled query (v4 reported true), so do not depend on the version: never wait for it.
    const hospitalitiesLoading = !readOnly && hospitalitiesQuery.isLoading;

    if (!enabled || ordersQuery.isLoading || hospitalitiesLoading || ordersQuery.isError) {
        return null;
    }
    if (!showsCateringSection(application.status, orders.length, hospitalities.length)) {
        return null;
    }

    // A read-only application is never handleable, whatever HANDLEABLE_STATUSES holds: every button,
    // hint and locked-Alert below hangs off this, and the server's `actions` are not consulted.
    const handleable = !readOnly && HANDLEABLE_STATUSES.includes(application.status);
    const now = Date.now();
    const canAdd = handleable
        && hospitalities.length > 0
        && application.dates.some(d => new Date(d.from_).getTime() > now);

    // Everything below is shown incl. VAT, and the summary adds the sum ex VAT and the VAT: the stored lines
    // are ex VAT, so each line is worked out to the øre as billed and the totals are sums of those (see
    // applicationPriceSummary), never the order's ex-VAT total_amount.
    const prices = applicationPriceSummary(application.orders ?? [], orders);
    const showApplicationSummary = showsPriceSummary(orders, prices.total.inclOre);
    const ending = cateringEnding(orders.length, prices.catering.inclOre, showApplicationSummary);

    // The change / cancel rule of the offers these orders are on, derived from the same fields the guard reads.
    // Nothing derivable (an offer that has been switched off sends no configuration) = no Alert, never a guess.
    const rules = handleable ? ruleGroups(orders, hospitalities) : [];

    // Day by day, each day by serving time, in venue time (see catering-venue-time.ts); the orders
    // without a serving time come last. Flattened so that every order keeps its own block below.
    const rows = groupByServingDay(orders, i18n.language, now).flatMap(group =>
        group.items.map(({order, time}) => ({order, time, group}))
    );

    // What may be done with a serving here, and whether it is locked (an action the server closed).
    const servingState = (order: IHospitalityOrder) => {
        const hospitality = hospitalities.find(h => h.id === order.hospitality_id);
        const actions = order.actions;
        // The dialog needs the (active) hospitality it belongs to; an order on a hospitality
        // that has since been switched off can be cancelled but not edited here.
        const changeOpen = handleable && !!actions?.change.allowed && !!hospitality;
        const cancelOpen = handleable && !!actions?.cancel.allowed;

        const lockedReasons: Array<HospitalityActionReason | null> = [];
        if (handleable && actions) {
            if (!changeOpen) lockedReasons.push(actions.change.allowed ? null : actions.change.reason);
            if (!cancelOpen) lockedReasons.push(actions.cancel.reason);
        }
        const locked = lockedReasons.length > 0;
        const lockedByDeadlineOnly = lockedReasons.every(r => r !== null && DEADLINE_REASONS.has(r));
        return {hospitality, changeOpen, cancelOpen, locked, lockedByDeadlineOnly};
    };

    // Who to ask about a serving that can no longer be changed here (a locked one, or any on a closed application)
    // is the kitchen's contact: said once per kitchen at the top of the section, not on every card.
    const contacts = uniqueKitchenContacts(rows.filter(row => readOnly || servingState(row.order).locked).map(row => row.order.contact));

    // «Torsdag 12. november kl. 11:00 · Kjøkkenet»; an order without a serving time says so instead.
    const servingHeading = (order: IHospitalityOrder, dayHeading: string | null, time: string | null): string => {
        const when = dayHeading === null || time === null
            ? t('bookingfrontend.catering_no_serving_time')
            : t('bookingfrontend.catering_overview_serving_heading')
                .replace('%1', () => dayHeading)
                .replace('%2', () => time);
        return [when, order.location_name].filter(Boolean).join(' · ');
    };

    const openAdd = () => {
        setActiveHospitality(hospitalities[0]);
        setEditingOrder(undefined);
        setModalOpen(true);
    };

    const openEdit = (order: IHospitalityOrder, hospitality: IHospitality) => {
        setActiveHospitality(hospitality);
        setEditingOrder(order);
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setActiveHospitality(null);
        setEditingOrder(undefined);
    };

    // The dialog is asked first; the cancellation itself is confirmCancel.
    const askCancel = (order: IHospitalityOrder) => setCancelTarget(order);

    const confirmCancel = async () => {
        const order = cancelTarget;
        if (!order) return;
        setCancelErrors(prev => ({...prev, [order.id]: ''}));
        try {
            await deleteMutation.mutateAsync(order.id);
        } catch (error) {
            // The server refuses a cancellation whose deadline has passed (409) and says why.
            setCancelErrors(prev => ({
                ...prev,
                [order.id]: error instanceof Error ? error.message : String(error),
            }));
        } finally {
            setCancelTarget(null);
        }
    };

    // What the dialog says about the order it is about to cancel (nothing while it is closed or the order is gone).
    const cancelRow = cancelTarget ? rows.find(row => row.order.id === cancelTarget.id) : undefined;
    const cancelTotalOre = cancelRow ? hospitalityOrderTotalOre(cancelRow.order) : null;

    return (
        <>
            <section className={styles.priceSection} data-testid="catering-overview">
                <div className={cateringStyles.sectionHeader}>
                    <Heading level={2} data-size="sm" className={styles.sectionHeading}>{t('bookingfrontend.hospitality')}</Heading>
                    {canAdd && (
                        <Button variant="secondary" data-size="sm" onClick={openAdd}>
                            <PlusIcon aria-hidden="true"/>
                            {t('bookingfrontend.add_hospitality_order')}
                        </Button>
                    )}
                </div>

                {readOnly && (
                    <Paragraph data-size="sm" data-testid="catering-read-only">
                        {t('bookingfrontend.catering_application_closed')}
                    </Paragraph>
                )}

                {orders.length === 0 && (
                    <Paragraph>{t('bookingfrontend.catering_overview_empty')}</Paragraph>
                )}

                {rules.length > 0 && <CateringDeadlineAlert groups={rules}/>}

                {contacts.length > 0 && (
                    <div className={cateringStyles.contacts} data-testid="catering-contacts">
                        {contacts.map((contact, index) => <KitchenContact key={index} contact={contact}/>)}
                    </div>
                )}

                <div className={cateringStyles.orders}>
                    {rows.map(({order, time, group}) => {
                        const heading = servingHeading(order, group.heading, time);
                        const {hospitality, changeOpen, cancelOpen, locked, lockedByDeadlineOnly} = servingState(order);

                        return (
                            <section
                                key={order.id}
                                className={cateringStyles.order}
                                data-testid="catering-order"
                                data-order-id={order.id}
                                data-day={group.dayKey ?? 'none'}
                            >
                                <div className={cateringStyles.orderHeader}>
                                    <div>
                                        <Heading level={3} data-size="xs" className={cateringStyles.orderHeading} data-testid="catering-order-heading">{heading}</Heading>
                                        <Paragraph data-size="sm" className={cateringStyles.offerName}>{order.hospitality_name}</Paragraph>
                                    </div>
                                    <div className={cateringStyles.orderActions}>
                                        {order.status !== 'pending' && (
                                            <Tag data-color={STATUS_COLOR[order.status]} data-size="sm">
                                                {t(STATUS_LABEL_KEY[order.status])}
                                            </Tag>
                                        )}
                                        {changeOpen && hospitality && (
                                            <Button
                                                variant="secondary"
                                                data-size="sm"
                                                onClick={() => openEdit(order, hospitality)}
                                                aria-label={t('bookingfrontend.catering_overview_edit_aria').replace('%1', () => heading)}
                                            >
                                                <PencilIcon aria-hidden="true"/>
                                                {t('bookingfrontend.edit')}
                                            </Button>
                                        )}
                                        {cancelOpen && (
                                            <Button
                                                variant="tertiary"
                                                data-size="sm"
                                                onClick={() => askCancel(order)}
                                                disabled={deleteMutation.isPending}
                                                aria-label={t('bookingfrontend.catering_overview_cancel_aria').replace('%1', () => heading)}
                                            >
                                                <TrashIcon aria-hidden="true"/>
                                                {t('bookingfrontend.catering_cancel_order')}
                                            </Button>
                                        )}
                                    </div>
                                </div>

                                {/* The order-level note to the kitchen, as plain text (React escapes it; line breaks kept). */}
                                {order.special_requirements?.trim() && (
                                    <Paragraph data-size="sm" className={cateringStyles.note} data-testid="catering-special-requirements">
                                        <strong>{t('bookingfrontend.special_requirements')}:</strong> {order.special_requirements}
                                    </Paragraph>
                                )}

                                {/* An order without lines says so, and nothing more: no «Ordretotal 0 kr» under it. */}
                                {order.lines.length === 0 ? (
                                    <Paragraph data-size="sm" className={cateringStyles.noLines} data-testid="catering-order-no-lines">
                                        {t('bookingfrontend.catering_order_no_lines')}
                                    </Paragraph>
                                ) : (
                                    <Table data-size="sm" data-color="neutral" className={cateringStyles.lines}>
                                        <Table.Head>
                                            <Table.Row>
                                                <Table.HeaderCell>{t('bookingfrontend.catering_overview_col_item')}</Table.HeaderCell>
                                                <Table.HeaderCell>{t('bookingfrontend.catering_overview_col_quantity')}</Table.HeaderCell>
                                                <Table.HeaderCell>{t('booking.amount')}</Table.HeaderCell>
                                            </Table.Row>
                                        </Table.Head>
                                        <Table.Body>
                                            {order.lines.map(line => {
                                                // «N × unit price incl. VAT». The price is rounded to the øre for showing, the AMOUNT is the
                                                // line worked out as billed (VAT once per line): the two can differ by an øre, so the
                                                // amount is never N × this price.
                                                const unitIncl = hospitalityUnitPriceIncl({
                                                    effective_price: line.unit_price,
                                                    effective_tax_percent: line.tax_percent,
                                                });
                                                return (
                                                    <Table.Row key={line.id}>
                                                        <Table.Cell>
                                                            {line.article_name}
                                                            {line.comment && <small>{line.comment}</small>}
                                                        </Table.Cell>
                                                        <Table.Cell>
                                                            {formatQuantity(line.quantity)} × {unitIncl === null ? '-' : formatCurrency(unitIncl)}
                                                        </Table.Cell>
                                                        <Table.Cell>{formatOreOrDash(hospitalityLineTotalOre(line))}</Table.Cell>
                                                    </Table.Row>
                                                );
                                            })}
                                        </Table.Body>
                                    </Table>
                                )}
                                {order.lines.length > 0 && (
                                    <Paragraph data-size="sm" className={cateringStyles.orderTotal}>
                                        <span>{t('bookingfrontend.catering_overview_order_total')}</span>
                                        <span data-testid="catering-order-total">{formatOreOrDash(hospitalityOrderTotalOre(order))}</span>
                                    </Paragraph>
                                )}

                                {cancelErrors[order.id] && (
                                    <Alert data-color="danger" data-size="sm" role="alert">
                                        {cancelErrors[order.id]}
                                    </Alert>
                                )}

                                {/* A closed action is gone. A deadline is explained once, by the Alert at the top; any other
                                    lock (billed, paid, ...) says so here. Who to talk to is the kitchen's contact at the top. */}
                                {locked && !lockedByDeadlineOnly && (
                                    <Alert data-color="info" data-size="sm" data-testid="catering-locked">
                                        <Paragraph data-size="sm">
                                            {t('bookingfrontend.catering_order_locked')} {t('bookingfrontend.catering_contact_officer')}
                                        </Paragraph>
                                    </Alert>
                                )}
                            </section>
                        );
                    })}
                </div>

                {/* The section's own total. With the price summary after it, ONE row; without it, the catering's sum ex
                    VAT and VAT too, as nothing else on the page shows them. */}
                {ending === 'total' && (
                    <Paragraph data-size="sm" className={cateringStyles.sectionTotal} data-testid="catering-summary">
                        <span>{t('bookingfrontend.catering_overview_catering_total')}</span>
                        <span data-testid="catering-summary-incl">{formatOreOrDash(prices.catering.inclOre)}</span>
                    </Paragraph>
                )}
                {ending === 'breakdown' && (
                    <div className={cateringStyles.cateringSummary}>
                        <CateringPriceSummary
                            summary={prices.catering}
                            totalLabel={t('bookingfrontend.catering_overview_catering_total')}
                            data-testid="catering-summary"
                        />
                    </div>
                )}

                {!readOnly && (
                    <HospitalityOrderModal
                        open={modalOpen}
                        onClose={closeModal}
                        hospitalities={hospitalities}
                        selectedHospitality={activeHospitality}
                        onHospitalitySelect={setActiveHospitality}
                        applications={applications}
                        existingOrder={editingOrder}
                    />
                )}

                <CateringCancelDialog
                    open={!!cancelRow}
                    heading={cancelRow ? servingHeading(cancelRow.order, cancelRow.group.heading, cancelRow.time) : ''}
                    offerName={cancelRow?.order.hospitality_name ?? ''}
                    lines={(cancelRow?.order.lines ?? []).map(line => ({
                        id: line.id,
                        text: `${formatQuantity(line.quantity)} × ${line.article_name}`,
                        amount: formatOreOrDash(hospitalityLineTotalOre(line)),
                    }))}
                    total={formatOreOrDash(cancelTotalOre)}
                    description={[
                        cancelTotalOre === null
                            ? t('bookingfrontend.catering_overview_cancel_body')
                            : t('bookingfrontend.catering_overview_cancel_body_amount').replace('%1', formatOreOrDash(cancelTotalOre)),
                        orders.length > 1 ? t('bookingfrontend.catering_overview_cancel_others_kept') : null,
                    ].filter(Boolean).join(' ')}
                    pending={deleteMutation.isPending}
                    onConfirm={confirmCancel}
                    onClose={() => setCancelTarget(null)}
                />
            </section>

            {/* The block that closes the price surface (design A): no card and no heading of its own, a rule above,
                the parts, the sum ex VAT and the VAT, and «Totalt» in large type with «Beløp inkl. mva.» under it. */}
            {showApplicationSummary && (
                <section
                    className={cateringStyles.totals}
                    data-testid="catering-application-summary"
                    aria-label={t('bookingfrontend.catering_overview_summary_heading')}
                >
                    <CateringPriceSummary
                        summary={prices.total}
                        totalLabel={t('booking.total')}
                        caption={t('bookingfrontend.catering_overview_summary_caption')}
                        grand
                        parts={[
                            {key: 'rental', label: t('bookingfrontend.catering_overview_total_rental'), ore: prices.rental.inclOre},
                            {key: 'services', label: t('bookingfrontend.catering_overview_total_services'), ore: prices.services.inclOre},
                            {
                                key: 'catering',
                                label: orders.length === 1
                                    ? t('bookingfrontend.catering_overview_total_catering_one')
                                    : t('bookingfrontend.catering_overview_total_catering').replace('%1', String(orders.length)),
                                ore: prices.catering.inclOre,
                            },
                        ]}
                        data-testid="catering-application-total"
                    />
                </section>
            )}
        </>
    );
};

export default CateringOverview;
