'use client';
import React from 'react';
import { PencilIcon, TrashIcon } from '@navikt/aksel-icons';
import ColourCircle from '@/components/building-calendar/modules/colour-circle/colour-circle';
import { CartItem, totalForApp, fmtKr } from './cart-c-utils';
import styles from './cart-c.module.scss';

interface CartCBreakdownProps {
    item: CartItem;
    open: boolean;
    dateTile: 'block' | 'dot' | 'hidden';
    onEdit: (id: number) => void;
    onRemove: (id: number) => void;
}

const CartCBreakdown: React.FC<CartCBreakdownProps> = ({
    item,
    open,
    dateTile,
    onEdit,
    onRemove,
}) => {
    const isRecurring = !!item.recurring;
    const itemTotal = totalForApp(item);
    const insetClass = dateTile === 'block' ? styles.breakdownInsetBlock : styles.breakdownInsetOther;
    const hasCosts = item.resourceCosts.some((rc) => rc.total > 0) || item.articles.some((a) => a.price > 0);

    return (
        <div className={`${styles.breakdownWrap} ${open ? styles.breakdownWrapOpen : ''}`}>
            <div className={styles.breakdownInner}>
                <div className={insetClass}>
                    <div className={styles.breakdownCard}>
                        {hasCosts ? (
                            <>
                                {item.resourceCosts.map((rc, i) => (
                                    <div key={i} className={styles.breakdownLine}>
                                        <span className={styles.breakdownLabel}>
                                            <ColourCircle resourceId={rc.resourceId} size={8} />
                                            {rc.name}
                                        </span>
                                        {rc.total > 0 && (
                                            <span className={styles.breakdownValue}>{fmtKr(rc.total)}</span>
                                        )}
                                    </div>
                                ))}
                                {item.articles.map((a, i) => (
                                    <div key={`a${i}`} className={styles.breakdownLine}>
                                        <span className={styles.breakdownArticleLabel}>{a.name}</span>
                                        <span className={styles.breakdownValue}>{fmtKr(a.price)}</span>
                                    </div>
                                ))}
                                {isRecurring && (
                                    <div className={styles.breakdownSummary}>
                                        <span>{item.recurring!.occurrences} hendelser</span>
                                        <span className={styles.breakdownSummaryValue}>= {fmtKr(itemTotal)}</span>
                                    </div>
                                )}
                            </>
                        ) : (
                            item.resources.map((r) => (
                                <div key={r.id} className={styles.breakdownLine}>
                                    <span className={styles.breakdownLabel}>
                                        <ColourCircle resourceId={r.id} size={8} />
                                        {r.name}
                                    </span>
                                </div>
                            ))
                        )}
                    </div>
                    <div className={styles.breakdownActions}>
                        <button
                            className={`${styles.actionBtn} ${styles.actionBtnEdit}`}
                            onClick={(e) => { e.stopPropagation(); onEdit(item.id); }}
                        >
                            <PencilIcon fontSize="16px" aria-hidden /> Endre
                        </button>
                        <button
                            className={`${styles.actionBtn} ${styles.actionBtnRemove}`}
                            onClick={(e) => { e.stopPropagation(); onRemove(item.id); }}
                        >
                            <TrashIcon fontSize="16px" aria-hidden /> Fjern
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default CartCBreakdown;
