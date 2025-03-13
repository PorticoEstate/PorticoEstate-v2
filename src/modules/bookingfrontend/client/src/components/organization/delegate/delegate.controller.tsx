'use client'
import { useState } from 'react';
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { Button } from '@digdir/designsystemet-react';
import DelegateUpdate from './delegate.update';
import { ViewDelegate } from '@/service/types/api/organization.types';
import DelegateView from './delegate.view';

import styles from './styles/delegater.form.module.scss';

interface DelegateControllerProps {
    data: ViewDelegate;
}
 
const DelegateController = ({ data }: DelegateControllerProps) => {
    const t = useTrans();
    const [editing, setEditing] = useState(false);

    return (
        <main className={styles.delegate_create}>
            <Button varian='secondary' onClick={() => setEditing(!editing)}>
                {
                    editing 
                    ? t('bookingfrontend.cancel')
                    : t('bookingfrontend.edit')
                }
            </Button>
            <h2>{t('booki ngfrontend.delegate_details')}</h2>
            {editing 
                ? ( <DelegateUpdate data={data} /> )
                : ( <DelegateView data={data} /> )
            }
        </main>
    )
}

export default DelegateController;