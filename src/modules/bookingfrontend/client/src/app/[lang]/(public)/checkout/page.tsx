'use client'
import {FC} from 'react';
import PageHeader from "@/components/page-header/page-header";
import {faShoppingCart} from "@fortawesome/free-solid-svg-icons";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import CheckoutContent from "@/components/checkout/checkout-content";
import AuthGuard from "@/components/user/auth-guard";

const ApplicationCheckout: FC = () => {
    const t = useTrans();
    return (
        <main>
            <AuthGuard>
                {/*<PageHeader*/}
                {/*    title={t('bookingfrontend.checkout')}*/}
                {/*    icon={faShoppingCart}*/}
                {/*/>*/}
                <CheckoutContent/>
            </AuthGuard>
        </main>
    );
};

export default ApplicationCheckout;