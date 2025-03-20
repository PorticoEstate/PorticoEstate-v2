import { useState } from 'react';
import OrganizationUpdate from './organization.update';
import { Organization } from '@/service/types/api/organization.types';
import OrganizationView from './organization.view';
import { Button } from '@digdir/designsystemet-react';
import { useTrans } from '@/app/i18n/ClientTranslationProvider';
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faPen } from "@fortawesome/free-solid-svg-icons";

interface OrganizationControllerProps {
    data: Organization;
}

const OrganizatioController = ({ data }: OrganizationControllerProps) => {
    const t = useTrans();
    const [editing, setEditing] = useState(false);
    return (
        <>
        <div>
            <Button variant='secondary' onClick={() => setEditing(!editing)}>
                {
                    !editing
                    ? <FontAwesomeIcon icon={faPen} />
                    : null 
                }
                {
                    editing 
                    ? t('bookingfrontend.cancel')
                    : t('bookingfrontend.edit_organization')}
            </Button>
        </div>
        {
            editing 
            ? <OrganizationUpdate data={data}/>
            : <OrganizationView organization={data}/>
        }
        </>
    )
}

export default OrganizatioController;