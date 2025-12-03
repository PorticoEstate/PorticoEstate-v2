'use client'
import React, { useEffect, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import {Spinner, Alert, Button} from '@digdir/designsystemet-react';
import { useVippsPaymentStatus, useVippsPaymentDetails } from '@/components/checkout/hooks/checkout-hooks';
import { useTrans } from '@/app/i18n/ClientTranslationProvider';
import Link from "next/link";

/**
 * Component to handle Vipps payment completion and status checking
 * This component should be used on the page that Vipps redirects to after payment
 * It expects a 'payment_order_id' URL parameter
 */
const VippsPaymentStatus: React.FC = () => {
    const t = useTrans();
    const router = useRouter();
    const searchParams = useSearchParams();
    const payment_order_id = searchParams.get('payment_order_id');

    const [statusChecked, setStatusChecked] = useState(false);
    const [paymentResult, setPaymentResult] = useState<{
        status: string;
        message: string;
        applications_approved?: boolean;
    } | null>(null);

    // Hooks for Vipps operations
    const paymentStatusMutation = useVippsPaymentStatus();
    const { data: paymentDetails, isLoading: detailsLoading } = useVippsPaymentDetails(payment_order_id);

    // Check payment status when component mounts
    useEffect(() => {
        if (payment_order_id && !statusChecked) {
            setStatusChecked(true);

            // Check payment status with business logic processing
            paymentStatusMutation.mutate(payment_order_id, {
                onSuccess: (result) => {
                    setPaymentResult(result);
                },
                onError: (error) => {
                    setPaymentResult({
                        status: 'error',
                        message: error.message
                    });
                }
            });
        }
    }, [payment_order_id, statusChecked, paymentStatusMutation, router]);

    // If no payment order ID in URL
    if (!payment_order_id) {
        return (
            <Alert data-color="warning">
                {t('bookingfrontend.vipps_no_payment_id')}
            </Alert>
        );
    }

    // Loading state
    if (paymentStatusMutation.isPending || detailsLoading || !paymentResult) {
        return (
            <div className="p-4" style={{ textAlign: 'center' }}>
                <Spinner data-size={'lg'} aria-label={t('bookingfrontend.vipps_checking_payment')} />
                <p className="mt-2">{t('bookingfrontend.vipps_processing_payment')}</p>
            </div>
        );
    }

    // Payment completed successfully
    if (paymentResult.status === 'completed') {
        return (
            <div className="p-4">
                <Alert data-color="success">
                    <h3>{t('bookingfrontend.vipps_payment_successful')}</h3>
                    <div className="mt-2">
                        <h4>{t('bookingfrontend.after_submission_title')}</h4>
                        
                        {/* Show separate confirmations for direct bookings and applications */}
                        <div className="mt-3">
                            <div className="mb-3">
                                <h5>{t('bookingfrontend.direct_bookings_confirmation_title')}</h5>
                                <ul className="mt-2" style={{ paddingLeft: '1.25rem', listStyleType: 'disc' }}>
                                    <li>{t('bookingfrontend.payment_completed_and_confirmed')}</li>
                                    <li>{t('bookingfrontend.bookings_are_completed')}</li>
                                </ul>
                            </div>
                            
                            <div className="mb-3">
                                <h5>{t('bookingfrontend.applications_for_review_title')}</h5>
                                <ul className="mt-2" style={{ paddingLeft: '1.25rem', listStyleType: 'disc' }}>
                                    <li>{t('bookingfrontend.applications_sent_and_will_receive_email')}</li>
                                    <li>{t('bookingfrontend.invoice_will_come_if_approved')}</li>
                                </ul>
                            </div>
                        </div>
                        
                        <p className="mt-3" style={{ fontStyle: 'italic' }}>
                            {t('bookingfrontend.check_spam_filter')}
                        </p>
                    </div>
                </Alert>

                {paymentDetails && (
                    <div className="mt-2" style={{ fontSize: '14px', color: '#6b7280' }}>
                        <p>{t('bookingfrontend.vipps_payment_id')}: {payment_order_id}</p>
                        {paymentDetails.transactionInfo && (
                            <p>{t('bookingfrontend.vipps_amount')}: {(paymentDetails.transactionInfo.amount / 100).toFixed(2)} {t('bookingfrontend.currency')}</p>
                        )}
                    </div>
                )}

                <div className="mt-2">
                    <Button
						asChild
                    >
						<Link href="/user/applications">
							{t('bookingfrontend.view_my_applications')}
						</Link>
                    </Button>
                </div>
            </div>
        );
    }

    // Payment cancelled or failed
    if (paymentResult.status === 'cancelled' || paymentResult.status === 'failed') {
        return (
            <div className="p-4">
                <Alert data-color="warning">
                    <h3>{t('bookingfrontend.vipps_payment_cancelled')}</h3>
                    <p>{paymentResult.message}</p>
                </Alert>

                <div className="mt-2" style={{ display: 'flex', gap: '1rem', justifyContent: 'center' }}>
                    <Button asChild>
                        <Link href="/checkout">
                            {t('bookingfrontend.vipps_try_again')}
                        </Link>
                    </Button>
                    <Button asChild variant="secondary">
                        <Link href="/">
                            {t('bookingfrontend.back_to_start')}
                        </Link>
                    </Button>
                </div>
            </div>
        );
    }

    // Payment still pending
    if (paymentResult.status === 'pending') {
        return (
            <div className="p-4" >
                <Spinner data-size="lg" aria-label={t('bookingfrontend.vipps_payment_pending')} />
                <Alert data-color="info">
                    <h3>{t('bookingfrontend.vipps_payment_pending')}</h3>
                    <p>{paymentResult.message}</p>
                </Alert>

                <Button
                    className="mt-2"
                    onClick={() => {
                        setStatusChecked(false);
                        setPaymentResult(null);
                    }}
                >
                    {t('bookingfrontend.vipps_check_again')}
                </Button>
            </div>
        );
    }

    // Error state
    return (
        <div className="p-4" >
            <Alert data-color="danger">
                <h3>{t('bookingfrontend.vipps_payment_error')}</h3>
                <p>{paymentResult.message}</p>
            </Alert>

            <div className="mt-2" style={{ display: 'flex', gap: '1rem', justifyContent: 'center' }}>
                <Button asChild>
                    <Link href="/checkout">
                        {t('bookingfrontend.vipps_back_to_checkout')}
                    </Link>
                </Button>
                <Button asChild variant="secondary">
                    <Link href="/">
                        {t('bookingfrontend.back_to_start')}
                    </Link>
                </Button>
            </div>
        </div>
    );
};

export default VippsPaymentStatus;