'use client';
import React, { useMemo } from 'react';
import { XMarkIcon } from '@navikt/aksel-icons';
import { IApplication } from '@/service/types/api/application.types';
import { useBuildingSeasons } from '@/service/hooks/api-hooks';
import {
    CartItem,
    mapApplicationToCartItem,
    cartTotal,
    groupByBuilding as groupByBuildingFn,
} from './cart-c-utils';
import CartCGroup from './CartCGroup';
import CartCFooter from './CartCFooter';
import CartCEmpty from './CartCEmpty';
import { Density, PriceDetail, DateTile } from './CartCRow';
import styles from './cart-c.module.scss';

interface CartCProps {
    applications: IApplication[];
    onEditApplication: (id: number) => void;
    onRemoveApplication: (id: number) => void;
    onClose: () => void;
    onSubmit: () => void;
    submitting?: boolean;
    density?: Density;
    priceDetail?: PriceDetail;
}

const CartC: React.FC<CartCProps> = ({
    applications,
    onEditApplication,
    onRemoveApplication,
    onClose,
    onSubmit,
    submitting,
    density = 'comfortable',
    priceDetail = 'expandable',
}) => {
    const items = useMemo(
        () => applications.map((app) => mapApplicationToCartItem(app)),
        [applications]
    );

    const groups = useMemo(() => groupByBuildingFn(items), [items]);
    const total = useMemo(() => cartTotal(items), [items]);
    const showGroupHeaders = groups.length > 1;

    if (applications.length === 0) {
        return (
            <div className={styles.cartC}>
                <div className={styles.header}>
                    <span className={styles.headerTitle}>Handlekurv</span>
                    <button className={styles.closeButton} onClick={onClose} aria-label="Lukk">
                        <XMarkIcon fontSize="22px" />
                    </button>
                </div>
                <CartCEmpty />
            </div>
        );
    }

    return (
        <div className={styles.cartC}>
            <div className={styles.header}>
                <span className={styles.headerTitle}>
                    Handlekurv
                    <span className={styles.headerCount}>
                        {applications.length} {applications.length === 1 ? 'søknad' : 'søknader'}
                    </span>
                </span>
                <button className={styles.closeButton} onClick={onClose} aria-label="Lukk">
                    <XMarkIcon fontSize="22px" />
                </button>
            </div>

            <div className={styles.body}>
                {groups.map((group) => (
                    <CartCGroup
                        key={group.id}
                        group={group}
                        showHeader={showGroupHeaders}
                        showSubtotals={true}
                        density={density}
                        dateTile="block"
                        priceDetail={priceDetail}
                        showRecurringPill={true}
                        onEdit={onEditApplication}
                        onRemove={onRemoveApplication}
                    />
                ))}
            </div>

            <CartCFooter total={total} onSubmit={onSubmit} submitting={submitting} />
        </div>
    );
};

export default CartC;
