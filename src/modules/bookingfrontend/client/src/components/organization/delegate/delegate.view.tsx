'use client'
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { ViewDelegate } from "@/service/types/api/organization.types";

interface DelegateViewProps {
    data: ViewDelegate;
}

const DelegateView = ({ data }: DelegateViewProps) => {
    const t = useTrans();
    
    return (
        <main>
            <div>
                <h3>{t('bookingfrontend.name')}</h3>
                <p>{data.name}</p>
            </div>
            <div>
                <h3>{t('bookingfrontend.organization')}</h3>
                <p>{data.organization}</p>
            </div>
            <div>
                <h3>{t('bookingfrontend.contact_email')}</h3>
                <p>{data.email}</p>
            </div>
            <div>
                <h3>{t('bookingfrontend.phone')}</h3>
                <p>{data.phone}</p>
            </div>
        </main>
    );
}

export default DelegateView;