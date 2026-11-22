'use client';
import {FC, useMemo, useState} from 'react';
import {Alert, Button, Heading, Paragraph, Table} from '@digdir/designsystemet-react';
import {PlusIcon, PencilIcon, TrashIcon} from '@navikt/aksel-icons';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {IApplication} from '@/service/types/api/application.types';
import {IHospitality, IHospitalityOrder} from '@/service/types/api/hospitality.types';
import {useApplicationGroupHospitalities, useDeleteHospitalityOrder} from '../hooks/hospitality-hooks';
import {formatOreOrDash, hospitalityOrderTotalOre, sumOreOrNull} from '@/utils/article-pricing';
import {cancellationNotice} from '@/utils/catering-cancellation-notice';
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

const HospitalityOrderRow: FC<HospitalityOrderRowProps> = ({order, onEdit, formatServingTime}) => {
    const t = useTrans();
    const deleteMutation = useDeleteHospitalityOrder(order.application_id);

    const handleDelete = async () => {
        if (window.confirm(t('booking.confirm_delete'))) {
            await deleteMutation.mutateAsync(order.id);
        }
    };

    return (
        <Table.Row>
            <Table.Cell>
                {order.serving_time_iso ? formatServingTime(order.serving_time_iso) : '-'}
            </Table.Cell>
            <Table.Cell>{order.location_name}</Table.Cell>
            <Table.Cell>{order.hospitality_name}</Table.Cell>
            <Table.Cell>{formatOreOrDash(hospitalityOrderTotalOre(order))}</Table.Cell>
            <Table.Cell>
                <div style={{display: 'flex', gap: '0.5rem'}}>
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
            </Table.Cell>
        </Table.Row>
    );
};

const HospitalitySection: FC<HospitalitySectionProps> = ({applicationIds, applications}) => {
    const t = useTrans();
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
        const dateStr = date.toLocaleDateString('nb-NO', {day: '2-digit', month: 'short'});
        const timeStr = `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
        return `${dateStr} | ${timeStr}`;
    };

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

            {/* A warning when some order's cancellation deadline has passed; information with the soonest deadline
                that is still open. Both can show at once: a past order never hides an open order's date. */}
            {cancellationInfo?.somePast && (
                <Alert data-color="warning" data-size="sm" style={{marginBottom: '0.75rem'}}>
                    {t('bookingfrontend.cancellation_deadline_passed_warning')}
                </Alert>
            )}
            {cancellationInfo?.soonestOpen && (
                <Alert data-color="info" data-size="sm" style={{marginBottom: '0.75rem'}}>
                    {t('bookingfrontend.cancellation_deadline_info')
                        .replace('%1', cancellationInfo.soonestOpen.toLocaleDateString('nb-NO', {
                            day: '2-digit', month: 'short', year: 'numeric',
                            hour: '2-digit', minute: '2-digit'
                        }))
                    }
                </Alert>
            )}

            {orders.length === 0 && (
                <Paragraph data-size="sm">{t('bookingfrontend.no_hospitality_added')}</Paragraph>
            )}

            {orders.length > 0 && (
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
