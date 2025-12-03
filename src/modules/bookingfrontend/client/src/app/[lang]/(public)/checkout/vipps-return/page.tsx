import VippsPaymentStatus from '@/components/checkout/VippsPaymentStatus';
import { Metadata } from 'next';

export const metadata: Metadata = {
    title: 'Vipps Payment - Aktiv Kommune',
    description: 'Processing your Vipps payment',
};

/**
 * Page for handling Vipps payment completion
 * Users are redirected here after completing payment in Vipps
 * The page expects a 'payment_order_id' URL parameter
 */
export default function VippsReturnPage() {
    return (
        <div className="container mx-auto py-8">
            <div className="max-w-2xl mx-auto">
                <VippsPaymentStatus />
            </div>
        </div>
    );
}