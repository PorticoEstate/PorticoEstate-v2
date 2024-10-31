'use client'
import {FC} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";

interface ApplicationsProps {
}

const Applications: FC<ApplicationsProps> = (props) => {
    const t = useTrans();
    return (
        <main>
            {/*<PageHeader title={t('bookingfrontend.my page')}/>*/}
        </main>
    );
}

export default Applications


