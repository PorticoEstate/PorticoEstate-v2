'use client';
import {FC, useMemo, useState} from 'react';
import {Button, Heading} from '@digdir/designsystemet-react';
import {PlusIcon} from '@navikt/aksel-icons';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {IApplication} from '@/service/types/api/application.types';
import {IHospitality, IHospitalityOrder} from '@/service/types/api/hospitality.types';
import {useApplicationGroupHospitalities} from '../hooks/hospitality-hooks';
import {formatCurrency} from '@/utils/cost-utils';
import HospitalityOrderModal from './hospitality-order-modal';
import HospitalityOrderCard from './hospitality-order-card';
import styles from './hospitality.module.scss';

interface HospitalitySectionProps {
    applicationIds: number[];
    applications: IApplication[];
}

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

    if (isLoading || hospitalities.length === 0) {
        return null;
    }

    const handleAddOrder = (hospitality: IHospitality) => {
        setActiveHospitality(hospitality);
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

    const ordersForHospitality = (hospitalityId: number) =>
        orders.filter(o => o.hospitality_id === hospitalityId);

    return (
        <div className={styles.hospitalitySection}>
            <div className={styles.sectionHeader}>
                <Heading level={3} data-size="xs">{t('bookingfrontend.hospitality')}</Heading>
            </div>

            {hospitalities.map(hosp => {
                const hospOrders = ordersForHospitality(hosp.id);
                return (
                    <div key={hosp.id}>
                        <div className={styles.hospitalityItem}>
                            <span className={styles.hospitalityName}>{hosp.name}</span>
                            <Button
                                variant="secondary"
                                data-size="sm"
                                onClick={() => handleAddOrder(hosp)}
                            >
                                <PlusIcon aria-hidden="true"/>
                                {t('bookingfrontend.add_hospitality_order')}
                            </Button>
                        </div>

                        {hospOrders.length > 0 && (
                            <div className={styles.ordersList}>
                                {hospOrders.map(order => (
                                    <HospitalityOrderCard
                                        key={order.id}
                                        order={order}
                                        applicationId={order.application_id}
                                        onEdit={handleEditOrder}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                );
            })}

            {hospitalityTotal > 0 && (
                <div className={styles.hospitalityTotal}>
                    <span>{t('bookingfrontend.hospitality')} {t('bookingfrontend.total').toLowerCase()}:</span>
                    <span>{formatCurrency(hospitalityTotal)}</span>
                </div>
            )}

            {activeHospitality && (
                <HospitalityOrderModal
                    open={modalOpen}
                    onClose={handleModalClose}
                    hospitality={activeHospitality}
                    applicationId={getApplicationIdForHospitality(activeHospitality.id) || applicationIds[0]}
                    applications={applications}
                    existingOrder={editingOrder}
                />
            )}
        </div>
    );
};

export default HospitalitySection;
