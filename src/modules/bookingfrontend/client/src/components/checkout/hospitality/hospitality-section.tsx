'use client';
import {FC, useMemo, useState} from 'react';
import {Alert, Button, Heading, Paragraph, Table} from '@digdir/designsystemet-react';
import {PlusIcon, PencilIcon, TrashIcon} from '@navikt/aksel-icons';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {IApplication} from '@/service/types/api/application.types';
import {IHospitality, IHospitalityOrder} from '@/service/types/api/hospitality.types';
import {useApplicationGroupHospitalities, useDeleteHospitalityOrder} from '../hooks/hospitality-hooks';
import {formatCurrency} from '@/utils/cost-utils';
import HospitalityOrderModal from './hospitality-order-modal';
import styles from './hospitality.module.scss';

function getCancellationCutoffMs(hospitality: IHospitality): number | null {
    if (!hospitality.resource_cancellation_deadline_value || !hospitality.resource_cancellation_deadline_unit) return null;
    const value = hospitality.resource_cancellation_deadline_value;
    switch (hospitality.resource_cancellation_deadline_unit) {
        case 'hours': return value * 3600000;
        case 'days': return value * 86400000;
        case 'weeks': return value * 604800000;
        default: return null;
    }
}

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
        if (window.confirm(t('common.confirm_delete'))) {
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
            <Table.Cell>{formatCurrency(order.total_amount)}</Table.Cell>
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
    const {hospitalities, orders, isLoading, applicationHospitalityMap} = useApplicationGroupHospitalities(applicationIds);

    const [activeHospitality, setActiveHospitality] = useState<IHospitality | null>(null);
    const [modalOpen, setModalOpen] = useState(false);
    const [editingOrder, setEditingOrder] = useState<IHospitalityOrder | undefined>(undefined);

    const getApplicationIdForHospitality = (hospitalityId: number): number | undefined => {
        for (const [appId, hIds] of applicationHospitalityMap) {
            if (hIds.includes(hospitalityId)) {
                return appId;
            }
        }
        return applicationIds[0];
    };

    const hospitalityTotal = useMemo(() => {
        return orders.reduce((sum, order) => sum + (order.total_amount || 0), 0);
    }, [orders]);

    // Calculate cancellation deadline info only for ordered hospitalities
    const cancellationInfo = useMemo(() => {
        if (orders.length === 0 || applications.length === 0) return null;

        // Find the earliest booking date across all applications
        let earliestFrom: Date | null = null;
        for (const app of applications) {
            for (const d of (app.dates || [])) {
                const from = new Date(d.from_);
                if (!earliestFrom || from < earliestFrom) earliestFrom = from;
            }
        }
        if (!earliestFrom) return null;

        // Only consider hospitalities that have been ordered
        const orderedHospitalityIds = new Set(orders.map(o => o.hospitality_id));
        const orderedHospitalities = hospitalities.filter(h => orderedHospitalityIds.has(h.id));

        // Find the strictest cancellation deadline across ordered hospitalities
        let strictestCutoffMs: number | null = null;
        let strictestValue: number | null = null;
        let strictestUnit: string | null = null;
        for (const h of orderedHospitalities) {
            const cutoffMs = getCancellationCutoffMs(h);
            if (cutoffMs && (strictestCutoffMs === null || cutoffMs > strictestCutoffMs)) {
                strictestCutoffMs = cutoffMs;
                strictestValue = h.resource_cancellation_deadline_value;
                strictestUnit = h.resource_cancellation_deadline_unit;
            }
        }
        if (!strictestCutoffMs) return null;

        const cancelBy = new Date(earliestFrom.getTime() - strictestCutoffMs);
        const isPastDeadline = Date.now() > cancelBy.getTime();

        return {cancelBy, value: strictestValue, unit: strictestUnit, isPastDeadline};
    }, [orders, hospitalities, applications]);

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

            {cancellationInfo && (
                <Alert data-color="warning" data-size="sm" style={{marginBottom: '0.75rem'}}>
                    {cancellationInfo.isPastDeadline
                        ? t('bookingfrontend.cancellation_deadline_passed_warning')
                        : t('bookingfrontend.cancellation_deadline_info')
                            .replace('%1', cancellationInfo.cancelBy.toLocaleDateString('nb-NO', {
                                day: '2-digit', month: 'short', year: 'numeric',
                                hour: '2-digit', minute: '2-digit'
                            }))
                    }
                </Alert>
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

            {hospitalityTotal > 0 && orders.length > 1 && (
                <div className={styles.hospitalityTotal}>
                    <span>{t('bookingfrontend.hospitality')} {t('bookingfrontend.total').toLowerCase()}:</span>
                    <span>{formatCurrency(hospitalityTotal)}</span>
                </div>
            )}

            <HospitalityOrderModal
                open={modalOpen}
                onClose={handleModalClose}
                hospitalities={hospitalities}
                selectedHospitality={activeHospitality}
                onHospitalitySelect={handleHospitalitySelected}
                applicationId={activeHospitality ? (getApplicationIdForHospitality(activeHospitality.id) || applicationIds[0]) : applicationIds[0]}
                applications={applications}
                existingOrder={editingOrder}
            />
        </div>
    );
};

export default HospitalitySection;
