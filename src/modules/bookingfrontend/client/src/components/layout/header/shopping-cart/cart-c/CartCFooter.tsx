'use client';
import React from 'react';
import { ArrowRightIcon } from '@navikt/aksel-icons';
import { useTrans } from '@/app/i18n/ClientTranslationProvider';
import { fmtKr } from './cart-c-utils';
import styles from './cart-c.module.scss';

interface CartCFooterProps {
    total: number;
    /** Navigates to the checkout page. The application is submitted there, not here. */
    onGoToSubmission: () => void;
    submitting?: boolean;
}

const CartCFooter: React.FC<CartCFooterProps> = ({ total, onGoToSubmission, submitting }) => {
    const t = useTrans();

    return (
        <div className={styles.footer}>
            {total > 0 && (
                <div className={styles.footerTotalRow}>
                    <span className={styles.footerTotalLabel}>Totalpris</span>
                    <span className={styles.footerTotalValue}>{fmtKr(total)}</span>
                </div>
            )}
            <button
                className={styles.submitBtn}
                onClick={onGoToSubmission}
                disabled={submitting}
            >
                {t('bookingfrontend.go_to_submission')}
                <ArrowRightIcon fontSize="16px" aria-hidden />
            </button>
        </div>
    );
};

export default CartCFooter;
