'use client';
import React, { useState, useEffect } from 'react';
import { ChevronDownIcon, ArrowsCirclepathIcon } from '@navikt/aksel-icons';
import ColourCircle from '@/components/building-calendar/modules/colour-circle/colour-circle';
import { CartItem, totalForApp, fmtKr, formatRange } from './cart-c-utils';
import CartCBreakdown from './CartCBreakdown';
import styles from './cart-c.module.scss';

export type Density = 'compact' | 'comfortable' | 'spacious';
export type PriceDetail = 'hidden' | 'expandable' | 'always';
export type DateTile = 'block' | 'dot' | 'hidden';

interface CartCRowProps {
    item: CartItem;
    density: Density;
    dateTile: DateTile;
    priceDetail: PriceDetail;
    showRecurringPill: boolean;
    onEdit: (id: number) => void;
    onRemove: (id: number) => void;
}

const densityClass: Record<Density, string> = {
    compact: styles.rowCompact,
    comfortable: styles.rowComfortable,
    spacious: styles.rowSpacious,
};

const CartCRow: React.FC<CartCRowProps> = ({
    item,
    density,
    dateTile,
    priceDetail,
    showRecurringPill,
    onEdit,
    onRemove,
}) => {
    const startOpen = priceDetail === 'always';
    const [open, setOpen] = useState(startOpen);

    useEffect(() => {
        if (priceDetail === 'always') setOpen(true);
        else setOpen(false);
    }, [priceDetail]);

    const expandable = priceDetail !== 'hidden';
    const itemTotal = totalForApp(item);
    const isRecurring = !!item.recurring;

    const firstDate = item.dates[0];
    const [dayStr, timeStr] = firstDate
        ? formatRange(firstDate.from_, firstDate.to_)
        : ['', ''];
    const dayNum = firstDate ? new Date(firstDate.from_).getDate() : 0;
    const monthAbbr = firstDate ? dayStr.split(' ')[1]?.replace('.', '') : '';

    const canToggle = expandable && priceDetail === 'expandable';
    const handleClick = () => {
        if (canToggle) setOpen((o) => !o);
    };

    return (
        <div className={styles.rowWrapper}>
            <div
                className={`${styles.row} ${densityClass[density]} ${canToggle ? styles.rowClickable : ''}`}
                onClick={handleClick}
            >
                {dateTile === 'block' && firstDate && (
                    <div className={styles.dateTile}>
                        <span className={styles.dateTileDay}>{dayNum}</span>
                        <span className={styles.dateTileMonth}>{monthAbbr}</span>
                    </div>
                )}
                {dateTile === 'dot' && (
                    <div className={styles.dateDotWrap}>
                        <ColourCircle
                            resourceId={item.resources[0]?.id ?? 0}
                            size={10}
                        />
                    </div>
                )}

                <div className={styles.rowContent}>
                    <div className={styles.rowTitleLine}>
                        <span className={styles.rowTitle}>{item.name}</span>
                        {itemTotal > 0 && (
                            <span className={styles.rowPrice}>{fmtKr(itemTotal)}</span>
                        )}
                    </div>
                    <div className={styles.metaLine}>
                        {dateTile === 'hidden' && firstDate && (
                            <>
                                <span>{dayStr}</span>
                                <span className={styles.metaSep}>&middot;</span>
                            </>
                        )}
                        {timeStr && <span>{timeStr}</span>}
                        {item.resources.length > 0 && (
                            <>
                                <span className={styles.metaSep}>&middot;</span>
                                <span className={styles.resourceInline}>
                                    {item.resources.slice(0, 2).map((r) => (
                                        <span key={r.id} className={styles.resourceInline}>
                                            <ColourCircle resourceId={r.id} size={7} />
                                            <span>{r.name}</span>
                                        </span>
                                    ))}
                                    {item.resources.length > 2 && (
                                        <span className={styles.resourceOverflow}>
                                            +{item.resources.length - 2}
                                        </span>
                                    )}
                                </span>
                            </>
                        )}
                        {isRecurring && showRecurringPill && (
                            <>
                                <span className={styles.metaSep}>&middot;</span>
                                <span className={styles.recurringPill}>
                                    <ArrowsCirclepathIcon fontSize="11px" aria-hidden />
                                    {item.recurring!.occurrences}&times; ukentlig
                                </span>
                            </>
                        )}
                    </div>
                </div>

                {canToggle && (
                    <ChevronDownIcon
                        fontSize="18px"
                        className={`${styles.chevron} ${open ? styles.chevronOpen : ''}`}
                        aria-hidden
                    />
                )}
            </div>

            {expandable && (
                <CartCBreakdown
                    item={item}
                    open={open}
                    dateTile={dateTile}
                    onEdit={onEdit}
                    onRemove={onRemove}
                />
            )}
        </div>
    );
};

export default CartCRow;
