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
    const button = (
        <Button variant='secondary' onClick={() => setEditing(!editing)}>
            {
                editing 
                ? t('bookingfrontend.cancel')
                : t('bookingfrontend.edit')
            }
        </Button>
    )
    return (
        <main className={styles.delegate_create}>
            { 
                !editing ? button : null
            }
            {editing 
                ? ( <DelegateUpdate button={button} data={data} /> )
                : ( <DelegateView data={data} /> )
            }
        </main>
    )
}

export default DelegateController;