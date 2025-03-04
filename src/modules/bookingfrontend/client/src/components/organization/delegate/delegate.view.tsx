'use client'
import { useState } from 'react';
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { ViewDelegate } from "@/service/types/api/organization.types";
import { Button } from '@digdir/designsystemet-react';

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
                <h3>{t('bookingfrontend.email')}</h3>
                <p>{data.email}</p>
            </div>
            <div>
                <h3>{t('bookingfrontend.phone')}</h3>
                <p>{data.phone}</p>
            </div>
        </main>
    );
}

const DelegateController = ({ data }: DelegateViewProps) => {
    const t = useTrans();
    const [editing, setEditing] = useState(false);

    return (
        <main>
            <h4>{t('bookingfrontend.delegate_details')}</h4>
            {editing ? null : (
                <DelegateView data={data} />
            )}
            <div>
                <Button onClick={() => setEditing(!editing)}>
                    {editing ? 'Avbryt' : 'Rediger'}
                </Button>
            </div>
        </main>
    )
}

export default DelegateController;