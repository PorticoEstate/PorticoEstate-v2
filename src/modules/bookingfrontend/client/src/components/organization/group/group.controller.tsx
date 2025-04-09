'use client';
import { Group } from '@/service/types/api/organization.types';
import { useState } from 'react';
import GroupUpdateController from './group.update';
import GroupView from './group.view';
import { Button } from '@digdir/designsystemet-react';
import { PencilIcon, ArrowLeftIcon } from '@navikt/aksel-icons';
import { useTrans } from '@/app/i18n/ClientTranslationProvider';
import { useRouter } from 'next/navigation'
import { useGroupData } from '@/service/api/organization';

interface GroupController {
    data: Group;
}

const GroupController = ({ data }: GroupController) => {
    const router = useRouter();
    const [editing, setEditing] = useState(false);
    const group = useGroupData(data.organization.id, data.id, data);
    const t = useTrans();
    const btn = (
        <Button variant='secondary' onClick={() => setEditing(!editing)}>
            { editing
                ? t('bookingfrontend.cancel')
                : (
                    <>
                        <PencilIcon />
                        <span>{t('bookingfrontend.edit')}</span>
                    </>
                )
            }
        </Button>
    );

    return (
        <>
            { 
                editing 
                ? <GroupUpdateController group={group.data} button={btn}/>
                : (
                    <>
                        <div style={{display: 'flex'}}>
                            <Button 
                                style={{marginRight: '0.5rem'}} 
                                variant='secondary' 
                                onClick={() => router.back()}
                            >
                                <ArrowLeftIcon />
                                {t('bookingfrontend.back')}
                            </Button>
                            {btn}
                        </div>
                        <GroupView group={group.data}/>
                    </>
                )
            }
        </>
    )
}

export default GroupController;