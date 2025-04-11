'use client';
import { useState } from 'react';
import OrganizationUpdate from './organization.update';
import { Organization } from '@/service/types/api/organization.types';
import OrganizationView from './organization.view';
import { Button } from '@digdir/designsystemet-react';
import { useTrans } from '@/app/i18n/ClientTranslationProvider';
import { PencilIcon } from '@navikt/aksel-icons';
import { useDelegateList, useGroupList, useOrganizationData } from '@/service/api/organization';

interface OrganizationControllerProps {
    data: Organization;
}

const OrganizatioController = ({ data }: OrganizationControllerProps) => {
    const t = useTrans();
    const [editing, setEditing] = useState(false);
    const organization = useOrganizationData(data.id, data);
    const delegateList = useDelegateList(data.id);
    const groupList = useGroupList(data.id);
    if (delegateList.isLoading || groupList.isLoading) return null;
    if (!organization.data) return null;

    return (
        <main>
            <div>
                <Button variant='secondary' onClick={() => setEditing(!editing)}>
                    {
                        !editing
                        ? <PencilIcon />
                        : null 
                    }
                    {
                        editing 
                        ? t('bookingfrontend.cancel')
                        : t('bookingfrontend.edit organization')}
                </Button>
            </div>
            {
                editing 
                ? <OrganizationUpdate org={organization.data}/>
                : <OrganizationView 
                    delegates={delegateList.data} 
                    groups={groupList.data}
                    data={organization.data}
                    />
            }
        </main>
    )
}

export default OrganizatioController;