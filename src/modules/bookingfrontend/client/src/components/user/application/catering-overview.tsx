'use client';
import React, {FC, useMemo, useState} from 'react';
import parse from 'html-react-parser';
import {Alert, Button, Heading, Paragraph, Table, Tag} from '@digdir/designsystemet-react';
import {PencilIcon, PlusIcon, TrashIcon} from '@navikt/aksel-icons';
import {useClientTranslation, useTrans} from '@/app/i18n/ClientTranslationProvider';
import {IApplication} from '@/service/types/api/application.types';
import {
    HospitalityActionReason,
    IHospitality,
    IHospitalityOrder,
    IHospitalityOrderContact,
} from '@/service/types/api/hospitality.types';
import {
    useAvailableHospitalities,
    useDeleteHospitalityOrder,
    useHospitalityOrders,
} from '@/components/checkout/hooks/hospitality-hooks';
import HospitalityOrderModal from '@/components/checkout/hospitality/hospitality-order-modal';
import {useServerSettings} from '@/service/hooks/api-hooks';
import {formatCurrency} from '@/utils/cost-utils';
import {isCateringClosed, showsCateringSection} from '@/utils/catering-status';
import {formatVenueDeadline, groupByServingDay} from '@/utils/catering-venue-time';
import {unescapeHTML} from '@/components/building-page/util/building-text-util';
import styles from './application-details.module.scss';

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

/** "3.00" -> "3", "2.50" -> "2.5": a quantity reads the way it was typed. */
const formatQuantity = (quantity: string): string => String(Number(quantity));

const KitchenContact: FC<{ contact: IHospitalityOrderContact }> = ({contact}) => {
    const t = useTrans();
    const hasBuildingContact = !!(contact.building_email || contact.building_phone);
    if (!contact.info && !hasBuildingContact) return null;

    return (
        <div data-testid="catering-contact">
            <Paragraph data-size="sm"><strong>{t('bookingfrontend.catering_kitchen_contact')}</strong></Paragraph>
            {contact.info ? (
                // The kitchen's contact_info is admin-authored rich text, rendered the way the
                // resource page renders it.
                <div>{parse(unescapeHTML(contact.info))}</div>
            ) : (
                <Paragraph data-size="sm">
                    {contact.building_name && <>{contact.building_name}<br/></>}
                    {contact.building_email && <>{t('common.email')}: {contact.building_email}<br/></>}
                    {contact.building_phone && <>{t('booking.phone')}: {contact.building_phone}</>}
                </Paragraph>
            )}
        </div>
    );
};

interface CateringOverviewProps {
    application: IApplication;
}

/**
 * What was ordered in the way of catering for this application, with change / cancel while the
 * deadlines allow it (GH #1423).
 *
 * The server decides what is allowed (`order.actions`, computed by HospitalityOrderGuard) and
 * enforces it again on every write; this component only offers what the server says is open and,
 * once a deadline has closed an action, says so and who to contact. It reuses the cart's order
 * dialog for adding and changing.
 *
 * On a rejected or cancelled application (`readOnly`) the orders already placed stay on view, with
 * a note and the kitchen's contact, but nothing is offered: no add, change or cancel, no deadline
 * hints and no locked-Alert, and the offer list is not even fetched.
 *
 * Renders nothing when catering is switched off, when none is offered for the booked resources and
 * nothing is ordered (on a read-only application: when nothing is ordered), while loading, and when
 * the orders cannot be read (a visitor who holds only the application's secret link is not let into
 * the catering endpoints).
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
    const total = orders.reduce((sum, order) => sum + (order.total_amount || 0), 0);

    // Day by day, each day by serving time, in venue time (see catering-venue-time.ts); the orders
    // without a serving time come last. Flattened so that every order keeps its own block below.
    const rows = groupByServingDay(orders, i18n.language, now).flatMap((group, groupIndex) =>
        group.items.map(({order, time}, indexInGroup) => ({order, time, group, groupIndex, indexInGroup}))
    );

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

    const handleCancel = async (order: IHospitalityOrder) => {
        if (!window.confirm(t('bookingfrontend.catering_confirm_cancel_order'))) return;
        setCancelErrors(prev => ({...prev, [order.id]: ''}));
        try {
            await deleteMutation.mutateAsync(order.id);
        } catch (error) {
            // The server refuses a cancellation whose deadline has passed (409) and says why.
            setCancelErrors(prev => ({
                ...prev,
                [order.id]: error instanceof Error ? error.message : String(error),
            }));
        }
    };

    return (
        <div className={styles.card} data-testid="catering-overview">
            <div className={styles.cardHeader}>
                <Heading level={2} data-size="sm">{t('bookingfrontend.hospitality')}</Heading>
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

            {rows.map(({order, time, group, groupIndex, indexInGroup}) => {
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
                const lockedText = !lockedByDeadlineOnly
                    ? t('bookingfrontend.catering_order_locked')
                    : !changeOpen && !cancelOpen
                        ? t('bookingfrontend.catering_change_cancel_locked')
                        : !changeOpen
                            ? t('bookingfrontend.catering_change_locked')
                            : t('bookingfrontend.catering_cancel_locked');

                return (
                    <React.Fragment key={order.id}>
                        {indexInGroup === 0 ? (
                            <>
                                {groupIndex > 0 && <div className={styles.divider} style={{margin: '16px 0'}}/>}
                                <Heading level={3} data-size="sm" data-testid="catering-day-heading" data-day={group.dayKey ?? 'none'}>
                                    {group.heading ?? t('bookingfrontend.catering_no_serving_time')}
                                </Heading>
                            </>
                        ) : (
                            <div className={styles.divider} style={{margin: '16px 0'}}/>
                        )}
                        <section data-testid="catering-order" data-order-id={order.id}>
                            <div style={{display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, flexWrap: 'wrap'}}>
                                <div>
                                    <Heading level={4} data-size="xs">{order.hospitality_name}</Heading>
                                    <Paragraph data-size="sm">
                                        {t('bookingfrontend.serving_time')}: {time ?? '-'}
                                        <br/>
                                        {t('bookingfrontend.delivery_location')}: {order.location_name}
                                    </Paragraph>
                                </div>
                                <div style={{display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap'}}>
                                    {order.status !== 'pending' && (
                                        <Tag data-color={STATUS_COLOR[order.status]} data-size="sm">
                                            {t(STATUS_LABEL_KEY[order.status])}
                                        </Tag>
                                    )}
                                    {changeOpen && hospitality && (
                                        <Button variant="secondary" data-size="sm" onClick={() => openEdit(order, hospitality)}>
                                            <PencilIcon aria-hidden="true"/>
                                            {t('bookingfrontend.edit')}
                                        </Button>
                                    )}
                                    {cancelOpen && (
                                        <Button
                                            variant="tertiary"
                                            data-color="danger"
                                            data-size="sm"
                                            onClick={() => handleCancel(order)}
                                            disabled={deleteMutation.isPending}
                                        >
                                            <TrashIcon aria-hidden="true"/>
                                            {t('bookingfrontend.catering_cancel_order')}
                                        </Button>
                                    )}
                                </div>
                            </div>

                            {/* The order-level note to the kitchen, as plain text (React escapes it; line breaks kept). */}
                            {order.special_requirements?.trim() && (
                                <Paragraph data-size="sm" data-testid="catering-special-requirements" style={{marginTop: 8, whiteSpace: 'pre-line'}}>
                                    <strong>{t('bookingfrontend.special_requirements')}:</strong> {order.special_requirements}
                                </Paragraph>
                            )}

                            {order.lines.length === 0 ? (
                                <Paragraph data-size="sm">{t('bookingfrontend.catering_order_no_lines')}</Paragraph>
                            ) : (
                                <Table data-size="sm">
                                    <Table.Head>
                                        <Table.Row>
                                            <Table.HeaderCell>{t('bookingfrontend.catering_col_article')}</Table.HeaderCell>
                                            <Table.HeaderCell>{t('booking.quantity')}</Table.HeaderCell>
                                            <Table.HeaderCell>{t('booking.amount')}</Table.HeaderCell>
                                        </Table.Row>
                                    </Table.Head>
                                    <Table.Body>
                                        {order.lines.map(line => (
                                            <Table.Row key={line.id}>
                                                <Table.Cell>
                                                    {line.article_name}
                                                    {line.comment && <><br/><small>{line.comment}</small></>}
                                                </Table.Cell>
                                                <Table.Cell>{formatQuantity(line.quantity)} {line.unit}</Table.Cell>
                                                <Table.Cell>{formatCurrency(parseFloat(line.amount))}</Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table>
                            )}
                            <Paragraph data-size="sm" style={{display: 'flex', justifyContent: 'space-between', gap: 12, marginTop: 8}}>
                                <strong>{t('bookingfrontend.order_total')}</strong>
                                <strong data-testid="catering-order-total">{formatCurrency(order.total_amount)}</strong>
                            </Paragraph>

                            {/* Which deadline governs each action that is still open, and until when. */}
                            {changeOpen && actions?.change.until && (
                                <Paragraph data-size="sm" style={{marginTop: 4}}>
                                    {t('bookingfrontend.catering_change_until').replace('%1', formatVenueDeadline(actions.change.until))}
                                </Paragraph>
                            )}
                            {cancelOpen && actions?.cancel.until && (
                                <Paragraph data-size="sm">
                                    {t('bookingfrontend.cancellation_deadline_info').replace('%1', formatVenueDeadline(actions.cancel.until))}
                                </Paragraph>
                            )}

                            {/* Read-only: no actions, but who to ask about the order is information, not an action. */}
                            {readOnly && order.contact && (
                                <div style={{marginTop: 8}}>
                                    <KitchenContact contact={order.contact}/>
                                </div>
                            )}

                            {cancelErrors[order.id] && (
                                <Alert data-color="danger" data-size="sm" role="alert" style={{marginTop: 8}}>
                                    {cancelErrors[order.id]}
                                </Alert>
                            )}

                            {/* A closed action is gone; say why and who to talk to. */}
                            {locked && (
                                <Alert data-color="info" data-size="sm" style={{marginTop: 8}} data-testid="catering-locked">
                                    <Paragraph data-size="sm">
                                        {lockedText} {t('bookingfrontend.catering_contact_officer')}
                                    </Paragraph>
                                    {order.contact && <KitchenContact contact={order.contact}/>}
                                </Alert>
                            )}
                        </section>
                    </React.Fragment>
                );
            })}

            {orders.length > 1 && total > 0 && (
                <Paragraph data-size="sm" style={{display: 'flex', justifyContent: 'space-between', gap: 12, marginTop: 16}}>
                    <strong>{t('bookingfrontend.hospitality')} {t('bookingfrontend.total').toLowerCase()}</strong>
                    <strong>{formatCurrency(total)}</strong>
                </Paragraph>
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
        </div>
    );
};

export default CateringOverview;
