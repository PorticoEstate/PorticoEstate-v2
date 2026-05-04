'use client';
import React from 'react';
import { ShoppingBasketIcon } from '@navikt/aksel-icons';
import styles from './cart-c.module.scss';

const CartCEmpty: React.FC = () => (
    <div className={styles.emptyState}>
        <ShoppingBasketIcon fontSize="48px" className={styles.emptyIcon} aria-hidden />
        <span className={styles.emptyText}>Handlekurven er tom</span>
    </div>
);

export default CartCEmpty;
