'use client';
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { Group } from "@/service/types/api/organization.types";
import styles from './styles/group.view.module.scss'

interface GroupViewProps {
    group: Group;
}

const GroupView = ({ group }: GroupViewProps) => {
    const t = useTrans();
    return (
        <main className={styles.group_view_container} >
            <h3 style={{ marginBottom: '1rem'}} >
                {t('bookingfrontend.group_details')}
            </h3>
            <div>
                <label className='ds-label'>{t('bookingfrontend.name')}</label>
                <p>{group.name}</p>
            </div>
            <div>
                <label className='ds-label'>
                    {t('bookingfrontend.organization_shortname')}
                </label>
                <p>{group.shortname}</p>
            </div>
            <div>
                <label className='ds-label'>
                    {t('bookingfrontend.organization_company')}
                </label>
                <p>{group.organization.name}</p>
            </div>
            <div>
                <label className='ds-label'>
                    {t('bookingfrontend.activity')}
                </label>
                <p>{group.activity.name}</p>
            </div>
            <div>
                <label className='ds-label'>
                    {t('bookingfrontend.description')}
                </label>
                <p>{group.description}</p>
            </div>
            {
                group.contact.map((contact) => (
                    <div key={contact.id} className={styles.group_leader_container}>
                        <h3>{t('bookingfrontend.group_leader')} 1</h3>
                        <div>
                            <label className='ds-label'>
                                {t('bookingfrontend.name')}
                            </label>
                            <p>{contact.name}</p>
                        </div>
                        <div>
                            <label className='ds-label'>
                                {t('bookingfrontend.organization_company')}
                            </label>
                            <p>{group.organization.name}</p>
                        </div>
                        <div>
                            <label className='ds-label'>
                                {t('bookingfrontend.contact_email')}
                            </label>
                            <p>{contact.email}</p>
                        </div>
                        <div>
                            <label className='ds-label'>
                                {t('bookingfrontend.phone')}
                            </label>
                            <p>{contact.phone}</p>
                        </div>
                    </div>
                ))
            }
        </main>
    )
}

export default GroupView;