'use client';
import {FC, useMemo, useState} from 'react';
import {Alert, Button, Heading, List, Paragraph, Table} from '@digdir/designsystemet-react';
import {PlusIcon, PencilIcon, TrashIcon} from '@navikt/aksel-icons';
import {useClientTranslation, useTrans} from '@/app/i18n/ClientTranslationProvider';
import {useIsMobile} from '@/service/hooks/is-mobile';
import {IApplication} from '@/service/types/api/application.types';
import {IHospitality, IHospitalityOrder} from '@/service/types/api/hospitality.types';
import {useApplicationGroupHospitalities, useDeleteHospitalityOrder} from '../hooks/hospitality-hooks';
import {formatOreOrDash, hospitalityOrderTotalOre, sumOreOrNull} from '@/utils/article-pricing';
import {cancellationNotice, orderAnchor} from '@/utils/catering-cancellation-notice';
import {formatDayMonth, formatDayMonthTime, formatDeadlineDateTime} from '@/utils/hospitality-deadline';
import HospitalityOrderModal from './hospitality-order-modal';
import styles from './hospitality.module.scss';

interface HospitalitySectionProps {
    applicationIds: number[];
    applications: IApplication[];
}

interface HospitalityOrderRowProps {
    order: IHospitalityOrder;
    onEdit: (order: IHospitalityOrder) => void;
    formatServingTime: (iso: string) => string;
}

/** Edit and delete of one order: the same buttons in the table's last cell and in the phone-width card. */
const HospitalityOrderActions: FC<{order: IHospitalityOrder; onEdit: (order: IHospitalityOrder) => void}> = ({order, onEdit}) => {
    const t = useTrans();
    const deleteMutation = useDeleteHospitalityOrder(order.application_id);

    const handleDelete = async () => {
        if (window.confirm(t('booking.confirm_delete'))) {
            await deleteMutation.mutateAsync(order.id);
        }
    };

    return (
        <div className={styles.orderActions} style={{display: 'flex', gap: '0.5rem'}}>
            <Button
                variant="tertiary"
                data-size="sm"
                icon={true}
                onClick={() => onEdit(order)}
                aria-label={t('bookingfrontend.edit')}
            >
                <PencilIcon aria-hidden="true"/>
            </Button>
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

const HospitalityOrderRow: FC<HospitalityOrderRowProps> = ({order, onEdit, formatServingTime}) => (
    <Table.Row>
        <Table.Cell>
            {order.serving_time_iso ? formatServingTime(order.serving_time_iso) : '-'}
        </Table.Cell>
        <Table.Cell>{order.location_name}</Table.Cell>
        <Table.Cell>{order.hospitality_name}</Table.Cell>
        <Table.Cell>{formatOreOrDash(hospitalityOrderTotalOre(order))}</Table.Cell>
        <Table.Cell>
            <HospitalityOrderActions order={order} onEdit={onEdit}/>
        </Table.Cell>
    </Table.Row>
);

/**
 * One order as a card, for a phone: the table's five columns do not fit a 375 px screen (the page scrolled sideways),
 * so below the cart's own breakpoint (useIsMobile, as shopping-cart-table.tsx) each order is a card built from the
 * same texts as the table's headers.
 */
const HospitalityOrderCardItem: FC<HospitalityOrderRowProps> = ({order, onEdit, formatServingTime}) => {
    const t = useTrans();

    return (
        <div className={styles.orderCard}>
            <div className={styles.orderHeader}>
                <div className={styles.orderInfo}>
                    <strong>{order.hospitality_name}</strong>
                    <div className={styles.orderMeta}>
                        {order.serving_time_iso && (
                            <span>{t('bookingfrontend.serving_time')}: {formatServingTime(order.serving_time_iso)}</span>
                        )}
                        <span>{t('bookingfrontend.where')}: {order.location_name}</span>
                    </div>
                </div>
                <HospitalityOrderActions order={order} onEdit={onEdit}/>
            </div>
            <div className={styles.orderTotal}>
                <span>{t('bookingfrontend.price')}</span>
                <span>{formatOreOrDash(hospitalityOrderTotalOre(order))}</span>
            </div>
        </div>
    );
};

const HospitalitySection: FC<HospitalitySectionProps> = ({applicationIds, applications}) => {
    const {t, i18n} = useClientTranslation();
    const lang = i18n.language;
    const isMobile = useIsMobile();
    const {hospitalities, orders, isLoading} = useApplicationGroupHospitalities(applicationIds);

    const [activeHospitality, setActiveHospitality] = useState<IHospitality | null>(null);
    const [modalOpen, setModalOpen] = useState(false);
    const [editingOrder, setEditingOrder] = useState<IHospitalityOrder | undefined>(undefined);

    // Incl. VAT in øre, from the lines (each line's VAT rounded once, as billed), never from the order's
    // ex-VAT total_amount; null when a line's VAT % is unknown
    const hospitalityTotalOre = useMemo(() => {
        return sumOreOrNull(orders.map(order => hospitalityOrderTotalOre(order)));
    }, [orders]);

    // Each order's cancellation deadline counts back from ITS OWN serving time (or its own application's earliest
    // date), as the server's lock does; the other applications in the building group play no part.
    const cancellationInfo = useMemo(
        () => cancellationNotice(orders, hospitalities, applications, Date.now()),
        [orders, hospitalities, applications]
    );

    if (isLoading || hospitalities.length === 0) {
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

    const formatServingTime = (iso: string) => {
        const date = new Date(iso);
        const dateStr = formatDayMonth(date, lang);
        const timeStr = `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
        return `${dateStr} | ${timeStr}`;
    };

    /** How a notice names an order: when it is served and where, «3. des. 12:00, Idrettshall Sal 1». */
    const orderIdentity = (order: IHospitalityOrder) => {
        const anchor = orderAnchor(order, applications);
        return [anchor ? formatDayMonthTime(anchor, lang) : null, order.location_name].filter(Boolean).join(', ');
    };

    /** «Bevertning 3. des. 12:00, Idrettshall Sal 1 kan avbestilles innen 01. des. 2026, 12:00» (replacer functions: a venue name may hold a «$»). */
    const openOrderLine = (order: IHospitalityOrder, cancelBy: Date) => t('bookingfrontend.cancellation_deadline_info_order')
        .replace('%1', () => orderIdentity(order))
        .replace('%2', () => formatDeadlineDateTime(cancelBy, lang));

    return (
        <div className={styles.hospitalitySection}>
            <div className={styles.sectionHeader}>
                <Heading level={3} data-size="xs">{t('bookingfrontend.hospitality')}</Heading>
                <Button
                    variant="secondary"
                    data-size="sm"
                    onClick={handleAddOrder}
                >
                    <PlusIcon aria-hidden="true"/>
                    {t('bookingfrontend.add_hospitality_order')}
                </Button>
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

            {orders.length === 0 && (
                <Paragraph data-size="sm">{t('bookingfrontend.no_hospitality_added')}</Paragraph>
            )}

            {orders.length > 0 && isMobile && (
                <div className={styles.ordersList}>
                    {orders.map(order => (
                        <HospitalityOrderCardItem
                            key={order.id}
                            order={order}
                            onEdit={handleEditOrder}
                            formatServingTime={formatServingTime}
                        />
                    ))}
                </div>
            )}

            {orders.length > 0 && !isMobile && (
                <Table hover data-size="md" zebra>
                    <Table.Head>
                        <Table.Row>
                            <Table.HeaderCell>{t('bookingfrontend.serving_time')}</Table.HeaderCell>
                            <Table.HeaderCell>{t('bookingfrontend.where')}</Table.HeaderCell>
                            <Table.HeaderCell>{t('bookingfrontend.hospitality')}</Table.HeaderCell>
                            <Table.HeaderCell>{t('bookingfrontend.price')}</Table.HeaderCell>
                            <Table.HeaderCell></Table.HeaderCell>
                        </Table.Row>
                    </Table.Head>
                    <Table.Body>
                        {orders.map(order => (
                            <HospitalityOrderRow
                                key={order.id}
                                order={order}
                                onEdit={handleEditOrder}
                                formatServingTime={formatServingTime}
                            />
                        ))}
                    </Table.Body>
                </Table>
            )}

            {(hospitalityTotalOre === null || hospitalityTotalOre > 0) && orders.length > 1 && (
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
