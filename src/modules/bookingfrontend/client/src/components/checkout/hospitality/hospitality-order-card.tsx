'use client';
import {FC, useState} from 'react';
import {Button} from '@digdir/designsystemet-react';
import {PencilIcon, TrashIcon, ChevronDownIcon, ChevronUpIcon} from '@navikt/aksel-icons';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {IHospitalityOrder} from '@/service/types/api/hospitality.types';
import {useDeleteHospitalityOrder} from '../hooks/hospitality-hooks';
import {formatCurrency} from '@/utils/cost-utils';
import styles from './hospitality.module.scss';

interface HospitalityOrderCardProps {
    order: IHospitalityOrder;
    applicationId: number;
    onEdit: (order: IHospitalityOrder) => void;
}

const HospitalityOrderCard: FC<HospitalityOrderCardProps> = ({order, applicationId, onEdit}) => {
    const t = useTrans();
    const deleteMutation = useDeleteHospitalityOrder(applicationId);
    const [expanded, setExpanded] = useState(false);

    const handleDelete = async () => {
        if (window.confirm(t('common.confirm_delete'))) {
            await deleteMutation.mutateAsync(order.id);
        }
    };

    const servingTimeFormatted = order.serving_time_iso
        ? new Date(order.serving_time_iso).toLocaleString('nb-NO', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
        })
        : null;

    return (
        <div className={styles.orderCard}>
            <div className={styles.orderHeader}>
                <div className={styles.orderInfo}>
                    <strong>{order.hospitality_name}</strong>
                    <div className={styles.orderMeta}>
                        {servingTimeFormatted && (
                            <span>{t('bookingfrontend.serving_time')}: {servingTimeFormatted}</span>
                        )}
                        <span>{t('bookingfrontend.delivery_location')}: {order.location_name}</span>
                    </div>
                </div>
                <div className={styles.orderActions}>
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
            </div>

            {/* Expandable line items */}
            {order.lines.length > 0 && (
                <>
                    <Button
                        variant="tertiary"
                        data-size="sm"
                        onClick={() => setExpanded(!expanded)}
                        style={{padding: 0}}
                    >
                        {order.lines.length} {order.lines.length === 1 ? t('bookingfrontend.article') : t('bookingfrontend.articles')}
                        {expanded ? <ChevronUpIcon aria-hidden="true"/> : <ChevronDownIcon aria-hidden="true"/>}
                    </Button>

                    {expanded && (
                        <div className={styles.orderLines}>
                            {order.lines.map(line => (
                                <div key={line.id} className={styles.lineItem}>
                                    <div className={styles.lineInfo}>
                                        <span>{line.article_name}</span>
                                        <span>{line.quantity} {line.unit}</span>
                                    </div>
                                    <span>{formatCurrency(parseFloat(line.amount))}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </>
            )}

            <div className={styles.orderTotal}>
                <span>{t('bookingfrontend.order_total')}</span>
                <span>{formatCurrency(order.total_amount)}</span>
            </div>
        </div>
    );
};

export default HospitalityOrderCard;
