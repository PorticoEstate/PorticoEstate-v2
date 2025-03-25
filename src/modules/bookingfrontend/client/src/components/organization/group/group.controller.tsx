'use client';
import { Group } from '@/service/types/api/organization.types';
import { useState } from 'react';
import GroupUpdateController from './group.update';
import GroupView from './group.view';
import { Button } from '@digdir/designsystemet-react';
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faPen, faArrowLeft } from "@fortawesome/free-solid-svg-icons";
import { useTrans } from '@/app/i18n/ClientTranslationProvider';
import { useRouter } from 'next/navigation'

interface GroupController {
    data: Group;
}

const GroupController = ({ data }: GroupController) => {
    const router = useRouter();
    const [editing, setEditing] = useState(false);
    const t = useTrans();

    const btn = (
        <Button variant='secondary' onClick={() => setEditing(!editing)}>
            { editing
                ? t('bookingfrontend.cancel')
                : (
                    <>
                        <FontAwesomeIcon icon={faPen} />
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
                ? <GroupUpdateController group={data} button={btn}/>
                : (
                    <>
                        <div style={{display: 'flex'}}>
                            <Button style={{marginRight: '0.5rem'}} variant='secondary' onClick={() => router.back()}>
                                <FontAwesomeIcon icon={faArrowLeft} />
                                {t('bookingfrontend.back')}
                            </Button>
                            {btn}
                        </div>
                        <GroupView group={data}/>
                    </>
                )
            }
        </>
    )
}

export default GroupController;