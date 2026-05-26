'use client';
import React from 'react';
import { ArrowRightIcon } from '@navikt/aksel-icons';
import { fmtKr } from './cart-c-utils';
import styles from './cart-c.module.scss';

interface CartCFooterProps {
    total: number;
    onSubmit: () => void;
    submitting?: boolean;
}

const CartCFooter: React.FC<CartCFooterProps> = ({ total, onSubmit, submitting }) => (
    <div className={styles.footer}>
        {total > 0 && (
            <div className={styles.footerTotalRow}>
                <span className={styles.footerTotalLabel}>Totalpris</span>
                <span className={styles.footerTotalValue}>{fmtKr(total)}</span>
            </div>
        )}
        <button
            className={styles.submitBtn}
            onClick={onSubmit}
            disabled={submitting}
        >
            Send inn søknad
            <ArrowRightIcon fontSize="16px" aria-hidden />
        </button>
    </div>
);

export default CartCFooter;
