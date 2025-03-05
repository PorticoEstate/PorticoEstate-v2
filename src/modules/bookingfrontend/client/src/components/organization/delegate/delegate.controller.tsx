'use client'
import { useState } from 'react';
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { Button } from '@digdir/designsystemet-react';
import DelegateUpdate from './delegate.update';
import { ViewDelegate } from '@/service/types/api/organization.types';
import DelegateView from './delegate.view';

interface DelegateControllerProps {
    data: ViewDelegate;
}

const DelegateController = ({ data }: DelegateControllerProps) => {
    const t = useTrans();
    const [editing, setEditing] = useState(false);

    return (
        <main>
            <h4>{t('bookingfrontend.delegate_details')}</h4>
            {editing 
                ? ( <DelegateUpdate data={data} /> )
                : ( <DelegateView data={data} /> )
            }
            <div>
                <Button onClick={() => setEditing(!editing)}>
                    {editing ? 'Avbryt' : 'Rediger'}
                </Button>
            </div>
        </main>
    )
}

export default DelegateController;