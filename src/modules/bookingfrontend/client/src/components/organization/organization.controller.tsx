import { useState } from 'react';
import OrganizationUpdate from './organization.update';
import { Organization } from '@/service/types/api/organization.types';
import OrganizationView from './organization.view';
import { Button } from '@digdir/designsystemet-react';
import { useTrans } from '@/app/i18n/ClientTranslationProvider';

interface OrganizationControllerProps {
    data: Organization;
}

const OrganizatioController = ({ data }: OrganizationControllerProps) => {
    const t = useTrans();
    const [editing, setEditing] = useState(false);
    return (
        <>
        {
            editing 
            ? <OrganizationUpdate data={data}/>
            : <OrganizationView organization={data}/>
        }
        <div>
            <Button onClick={() => setEditing(!editing)}>
                {
                    editing 
                    ? t('bookingfrontend.cancel')
                    : t('bookingfrontend.edit')}
            </Button>
        </div>
        </>
    )
}

export default OrganizatioController;