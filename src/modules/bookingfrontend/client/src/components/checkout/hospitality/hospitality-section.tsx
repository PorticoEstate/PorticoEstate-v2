'use client';
import {FC, useMemo, useState} from 'react';
import {Alert, Button, Heading, List, Paragraph, Table} from '@digdir/designsystemet-react';
import {PlusIcon, PencilIcon, TrashIcon} from '@navikt/aksel-icons';
import {useClientTranslation, useTrans} from '@/app/i18n/ClientTranslationProvider';
import {IApplication} from '@/service/types/api/application.types';
import {IHospitality, IHospitalityOrder} from '@/service/types/api/hospitality.types';
import {useApplicationGroupHospitalities, useDeleteHospitalityOrder} from '../hooks/hospitality-hooks';
import {formatOreOrDash, formatQuantity} from '@/utils/article-pricing';
import {formatCurrency} from '@/utils/cost-utils';
import {cancellationNotice, orderAnchor} from '@/utils/catering-cancellation-notice';
import {CheckoutCateringOrder, cateringTotalOre, checkoutCateringDays} from '@/utils/checkout-total';
import {cateringDaySumDate, cateringDaySumOre, cateringOffersText} from '@/utils/catering-day-sum';
import {formatDayMonthTime, formatDeadlineDateTime} from '@/utils/hospitality-deadline';
import HospitalityOrderModal from './hospitality-order-modal';
import styles from './hospitality.module.scss';

interface HospitalitySectionProps {
    applicationIds: number[];
    applications: IApplication[];
    /**
     * Whether a new order may be started here (default true). The recurring applications' section passes false: it
     * only lists the orders those applications already have, so that every order the checkout's total counts is
     * listed, and it renders nothing when there are none.
     */
    allowAdd?: boolean;
}

interface CateringOrderBlockProps {
    item: CheckoutCateringOrder<IHospitalityOrder>;
    /** Absent when the order's offer is not among the available ones: the dialog needs the offer to edit it. */
    onEdit?: (order: IHospitalityOrder) => void;
}

/** Edit and delete of one order. Edit only where the order can be opened in the dialog. */
const HospitalityOrderActions: FC<{order: IHospitalityOrder; onEdit?: (order: IHospitalityOrder) => void}> = ({order, onEdit}) => {
    const t = useTrans();
    const deleteMutation = useDeleteHospitalityOrder(order.application_id);

    const handleDelete = async () => {
        if (window.confirm(t('booking.confirm_delete'))) {
            await deleteMutation.mutateAsync(order.id);
        }
    };

    return (
        <div className={styles.orderActions} style={{display: 'flex', gap: '0.5rem'}}>
            {onEdit && (
                <Button
                    variant="tertiary"
                    data-size="sm"
                    icon={true}
                    onClick={() => onEdit(order)}
                    aria-label={t('bookingfrontend.edit')}
                >
                    <PencilIcon aria-hidden="true"/>
                </Button>
            )}
            <Button
                variant="tertiary"
                data-size="sm"
                icon={true}
                onClick={handleDelete}
                disabled={deleteMutation.isPending}
                aria-label={t('bookingfrontend.delete')}
            >
                <TrashIcon aria-hidden="true"/>
            </Button>
        </div>
    );
};

/**
 * One order under its day: «kl. 12:00 · Kultursalen» with the offer's name and the buttons, then its lines as
 * «Vare | N × enhetspris | Beløp» (incl. VAT), and the order's sum when it has more than one line. The same card at
 * every width; the lines stack at phone width (price-lines.scss, as on Min side).
 *
 * The amount of a line is the line worked out as billed (VAT once per line), and it is what the checkout's total adds;
 * the unit price is rounded to the øre for showing, so the amount is never N × that price.
 */
const CateringOrderBlock: FC<CateringOrderBlockProps> = ({item, onEdit}) => {
    const t = useTrans();
    const {order, time, lines, ore} = item;
    const when = time === null ? null : t('bookingfrontend.catering_checkout_serving_time').replace('%1', () => time);

    return (
        <div className={styles.orderCard} data-testid="checkout-catering-order" data-order-id={order.id}>
            <div className={styles.orderHeader}>
                <div className={styles.orderInfo}>
                    <strong>{[when, order.location_name].filter(Boolean).join(' · ')}</strong>
                    <span className={styles.orderMeta}>{order.hospitality_name}</span>
                </div>
                <HospitalityOrderActions order={order} onEdit={onEdit}/>
            </div>
            {lines.length === 0 ? (
                <Paragraph data-size="sm">{t('bookingfrontend.catering_order_no_lines')}</Paragraph>
            ) : (
                <Table data-size="sm" data-color="neutral" className={styles.cateringLines}>
                    <Table.Head>
                        <Table.Row>
                            <Table.HeaderCell>{t('bookingfrontend.catering_overview_col_item')}</Table.HeaderCell>
                            <Table.HeaderCell>{t('bookingfrontend.catering_overview_col_quantity')}</Table.HeaderCell>
                            <Table.HeaderCell>{t('booking.amount')}</Table.HeaderCell>
                        </Table.Row>
                    </Table.Head>
                    <Table.Body>
                        {lines.map(({line, unitIncl, ore: lineOre}) => (
                            <Table.Row key={line.id} data-testid="checkout-catering-line" data-ore={lineOre ?? ''}>
                                <Table.Cell>
                                    {line.article_name}
                                    {line.comment && <small>{line.comment}</small>}
                                </Table.Cell>
                                <Table.Cell>
                                    {formatQuantity(line.quantity)} × {unitIncl === null ? '-' : formatCurrency(unitIncl)}
                                </Table.Cell>
                                <Table.Cell>{formatOreOrDash(lineOre)}</Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table>
            )}
            {lines.length > 1 && (
                <Paragraph data-size="sm" className={styles.cateringOrderTotal}>
                    <span>{t('bookingfrontend.catering_overview_order_total')}</span>
                    <span>{formatOreOrDash(ore)}</span>
                </Paragraph>
            )}
        </div>
    );
};

const HospitalitySection: FC<HospitalitySectionProps> = ({applicationIds, applications, allowAdd = true}) => {
    const {t, i18n} = useClientTranslation();
    const lang = i18n.language;
    const {hospitalities, orders, isLoading} = useApplicationGroupHospitalities(applicationIds);

    const [activeHospitality, setActiveHospitality] = useState<IHospitality | null>(null);
    const [modalOpen, setModalOpen] = useState(false);
    const [editingOrder, setEditingOrder] = useState<IHospitalityOrder | undefined>(undefined);

    // What is listed, per venue day, earliest first (GH #1478): the orders that are not cancelled, line by line incl.
    // VAT. The cart's total adds exactly these lines (utils/checkout-total), so it is always the sum of what is listed.
    const days = useMemo(() => checkoutCateringDays(orders, lang, Date.now()), [orders, lang]);
    const listedCount = days.reduce((count, day) => count + day.orders.length, 0);

    // Incl. VAT in øre, from the lines (each line's VAT rounded once, as billed), never from the order's
    // ex-VAT total_amount; null when a line's VAT % is unknown
    const hospitalityTotalOre = useMemo(() => cateringTotalOre(orders), [orders]);

    // Each order's cancellation deadline counts back from ITS OWN serving time (or its own application's earliest
    // date), as the server's lock does; the other applications in the building group play no part.
    const cancellationInfo = useMemo(
        () => cancellationNotice(orders, hospitalities, applications, Date.now()),
        [orders, hospitalities, applications]
    );

    const canAdd = allowAdd && hospitalities.length > 0;

    // Shown for something to add, or for an order: an order whose offer is no longer available is still counted in
    // the total, so it is still listed.
    if (isLoading || (!canAdd && listedCount === 0)) {
        return null;
    }

    const handleAddOrder = () => {
        setActiveHospitality(hospitalities[0]);
        setEditingOrder(undefined);
        setModalOpen(true);
    };

    const handleEditOrder = (order: IHospitalityOrder) => {
        const hosp = hospitalities.find(h => h.id === order.hospitality_id);
        if (!hosp) return;
        setActiveHospitality(hosp);
        setEditingOrder(order);
        setModalOpen(true);
    };

    const handleModalClose = () => {
        setModalOpen(false);
        setActiveHospitality(null);
        setEditingOrder(undefined);
    };

    const handleHospitalitySelected = (hospitality: IHospitality) => {
        setActiveHospitality(hospitality);
    };

    // The dialog needs the offer an order is on; an order on an offer that is not available here can only be removed.
    const editHandlerFor = (order: IHospitalityOrder) =>
        hospitalities.some(h => h.id === order.hospitality_id) ? handleEditOrder : undefined;

    /** How a notice names an order: when it is served and where, «3. des. 12:00, Idrettshall Sal 1». */
    const orderIdentity = (order: IHospitalityOrder) => {
        const anchor = orderAnchor(order, applications);
        return [anchor ? formatDayMonthTime(anchor, lang) : null, order.location_name].filter(Boolean).join(', ');
    };

    /** «Bevertning 3. des. 12:00, Idrettshall Sal 1 kan avbestilles innen 01. des. 2026, 12:00» (replacer functions: a venue name may hold a «$»). */
    const openOrderLine = (order: IHospitalityOrder, cancelBy: Date) => t('bookingfrontend.cancellation_deadline_info_order')
        .replace('%1', () => orderIdentity(order))
        .replace('%2', () => formatDeadlineDateTime(cancelBy, lang));

    /** «Sum bevertning 22. april» for a venue day; «Sum bevertning» for the orders without a serving time. */
    const daySumLabel = (dayKey: string | null): string => {
        if (dayKey === null) return t('bookingfrontend.catering_day_sum_undated');
        const date = cateringDaySumDate(dayKey, lang);
        return t('bookingfrontend.catering_day_sum').replace('%1', () => date);
    };

    return (
        <div className={styles.hospitalitySection}>
            <div className={styles.sectionHeader}>
                <Heading level={3} data-size="xs">{t('bookingfrontend.hospitality')}</Heading>
                {canAdd && (
                    <Button
                        variant="secondary"
                        data-size="sm"
                        onClick={handleAddOrder}
                    >
                        <PlusIcon aria-hidden="true"/>
                        {t('bookingfrontend.add_hospitality_order')}
                    </Button>
                )}
            </div>

            {/* A warning naming EVERY order whose cancellation deadline has passed; information naming EVERY order
                that is still open with its own deadline, soonest first. Both can show at once: a past order never
                hides an open order's date. */}
            {cancellationInfo && cancellationInfo.past.length > 0 && (
                <Alert data-color="warning" data-size="sm" style={{marginBottom: '0.75rem'}}>
                    <Paragraph data-size="sm">{t('bookingfrontend.cancellation_deadline_passed_warning')}</Paragraph>
                    <Paragraph data-size="sm">
                        {t('bookingfrontend.cancellation_deadline_passed_orders')}
                        {cancellationInfo.past.length === 1 && ` ${orderIdentity(cancellationInfo.past[0])}`}
                    </Paragraph>
                    {cancellationInfo.past.length > 1 && (
                        <List.Unordered data-size="sm">
                            {cancellationInfo.past.map(order => (
                                <List.Item key={order.id}>{orderIdentity(order)}</List.Item>
                            ))}
                        </List.Unordered>
                    )}
                </Alert>
            )}
            {cancellationInfo && cancellationInfo.open.length > 0 && (
                <Alert data-color="info" data-size="sm" style={{marginBottom: '0.75rem'}}>
                    {cancellationInfo.open.length === 1 ? (
                        <Paragraph data-size="sm">
                            {openOrderLine(cancellationInfo.open[0].order, cancellationInfo.open[0].cancelBy)}
                        </Paragraph>
                    ) : (
                        <List.Unordered data-size="sm">
                            {cancellationInfo.open.map(({order, cancelBy}) => (
                                <List.Item key={order.id}>{openOrderLine(order, cancelBy)}</List.Item>
                            ))}
                        </List.Unordered>
                    )}
                </Alert>
            )}

            {/* Before the first order: one line naming the building's offers. The per-order deadlines above need an
                order, so they are not shown yet. */}
            {canAdd && listedCount === 0 && (
                <Alert data-color="info" data-size="sm" style={{marginBottom: '0.75rem'}}>
                    <Paragraph data-size="sm">
                        {t('bookingfrontend.catering_offers_in_building')
                            .replace('%1', () => cateringOffersText(hospitalities.map(hospitality => hospitality.name), lang))}
                    </Paragraph>
                </Alert>
            )}
            {listedCount === 0 && (
                <Paragraph data-size="sm">{t('bookingfrontend.no_hospitality_added')}</Paragraph>
            )}

            {days.map(day => (
                <section
                    key={day.dayKey ?? 'none'}
                    className={styles.cateringDay}
                    data-testid="checkout-catering-day"
                    data-day={day.dayKey ?? 'none'}
                >
                    <Heading level={4} data-size="2xs" className={styles.cateringDayHeading}>
                        {day.heading ?? t('bookingfrontend.catering_no_serving_time')}
                    </Heading>
                    <div className={styles.ordersList}>
                        {day.orders.map(item => (
                            <CateringOrderBlock key={item.order.id} item={item} onEdit={editHandlerFor(item.order)}/>
                        ))}
                    </div>
                    <Paragraph data-size="sm" className={styles.cateringOrderTotal} data-testid="checkout-catering-day-sum">
                        <span>{daySumLabel(day.dayKey)}</span>
                        <span>{formatOreOrDash(cateringDaySumOre(day))}</span>
                    </Paragraph>
                </section>
            ))}

            {(hospitalityTotalOre === null || hospitalityTotalOre > 0) && listedCount > 1 && (
                <div className={styles.hospitalityTotal}>
                    <span>{t('bookingfrontend.hospitality')}: {t('bookingfrontend.total_incl_vat')}</span>
                    <span>{formatOreOrDash(hospitalityTotalOre)}</span>
                </div>
            )}

            <HospitalityOrderModal
                open={modalOpen}
                onClose={handleModalClose}
                hospitalities={hospitalities}
                selectedHospitality={activeHospitality}
                onHospitalitySelect={handleHospitalitySelected}
                applications={applications}
                existingOrder={editingOrder}
            />
        </div>
    );
};

export default HospitalitySection;
