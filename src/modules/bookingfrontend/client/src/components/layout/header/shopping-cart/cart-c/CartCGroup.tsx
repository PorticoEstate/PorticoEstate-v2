'use client';
import React from 'react';
import { CartGroup, totalForApp, fmtKr } from './cart-c-utils';
import CartCRow, { Density, PriceDetail, DateTile } from './CartCRow';
import styles from './cart-c.module.scss';

interface CartCGroupProps {
    group: CartGroup;
    showHeader: boolean;
    showSubtotals: boolean;
    density: Density;
    dateTile: DateTile;
    priceDetail: PriceDetail;
    showRecurringPill: boolean;
    onEdit: (id: number) => void;
    onRemove: (id: number) => void;
}

const CartCGroup: React.FC<CartCGroupProps> = ({
    group,
    showHeader,
    showSubtotals,
    density,
    dateTile,
    priceDetail,
    showRecurringPill,
    onEdit,
    onRemove,
}) => {
    const subtotal = group.items.reduce((s, item) => s + totalForApp(item), 0);

    return (
        <div>
            {showHeader && (
                <div className={styles.groupHeader}>
                    <span className={styles.groupName}>{group.name}</span>
                    {showSubtotals && subtotal > 0 && (
                        <span className={styles.groupSubtotal}>{fmtKr(subtotal)}</span>
                    )}
                </div>
            )}
            {group.items.map((item) => (
                <CartCRow
                    key={item.id}
                    item={item}
                    density={density}
                    dateTile={dateTile}
                    priceDetail={priceDetail}
                    showRecurringPill={showRecurringPill}
                    onEdit={onEdit}
                    onRemove={onRemove}
                />
            ))}
        </div>
    );
};

export default CartCGroup;
