'use client'
import React, {FC} from 'react';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import styles from './checkout-steps.module.scss';

// The application flow as the design shows it (GH #1478): step 1 is the
// application form, step 2 is this checkout page, step 3 is the confirmation
// after sending. The checkout stays one page, so only the marker is shown.
const CURRENT_STEP = 2;
const STEP_COUNT = 3;

const CheckoutSteps: FC = () => {
    const t = useTrans();
    const labels = [
        t('bookingfrontend.application'),
        t('bookingfrontend.summary'),
        t('bookingfrontend.checkout_step_send'),
    ];

    return (
        <ol
            role="list"
            className={styles.steps}
            aria-label={t('bookingfrontend.checkout_steps_aria')
                .replace('%1', String(CURRENT_STEP))
                .replace('%2', String(STEP_COUNT))}
        >
            {labels.map((label, index) => {
                const step = index + 1;
                const isDone = step < CURRENT_STEP;
                const isCurrent = step === CURRENT_STEP;
                const className = [
                    styles.step,
                    isDone && styles.done,
                    isCurrent && styles.current,
                ].filter(Boolean).join(' ');

                return (
                    <li
                        key={step}
                        className={className}
                        aria-current={isCurrent ? 'step' : undefined}
                    >
                        <span className={styles.number} aria-hidden="true">
                            {isDone ? '✓' : step}
                        </span>
                        {label}
                    </li>
                );
            })}
        </ol>
    );
};

export default CheckoutSteps;
