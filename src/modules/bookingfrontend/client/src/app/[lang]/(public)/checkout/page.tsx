'use client'
import {FC} from 'react';
import PageHeader from "@/components/page-header/page-header";
import {faShoppingCart} from "@fortawesome/free-solid-svg-icons";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import CheckoutContent from "@/components/checkout/checkout-content";

const ApplicationCheckout: FC = () => {
    const t = useTrans();
    return (
        <main >
            {/*<PageHeader*/}
            {/*    title={t('bookingfrontend.checkout')}*/}
            {/*    icon={faShoppingCart}*/}
            {/*/>*/}
            <CheckoutContent />
        </main>
    );
};

export default ApplicationCheckout;