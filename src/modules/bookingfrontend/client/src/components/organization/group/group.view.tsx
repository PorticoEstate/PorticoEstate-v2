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
            <h3>{t('bookingfrontend.group_details')}</h3>
            <div>
                <h5>{t('bookingfrontend.name')}</h5>
                <p>{group.name}</p>
            </div>
            <div>
                <h5>{t('bookingfrontend.organization_shortname')}</h5>
                <p>{group.shortname}</p>
            </div>
            <div>
                <h5>{t('bookingfrontend.organization_company')}</h5>
                <p>{group.organization.name}</p>
            </div>
            <div>
                <h5>{t('bookingfrontend.activity')}</h5>
                <p>{group.activity.name}</p>
            </div>
            <div>
                <h5>{t('bookingfrontend.description')}</h5>
                <p>{group.description}</p>
            </div>
            {
                group.contact.map((contact) => (
                    <div className={styles.group_leader_container}>
                        <h3>{t('bookingfrontend.group_leader')} 1</h3>
                        <div>
                            <h5>{t('bookingfrontend.name')}</h5>
                            <p>{contact.name}</p>
                        </div>
                        <div>
                            <h5>{t('bookingfrontend.organization_company')}</h5>
                            <p>{group.organization.name}</p>
                        </div>
                        <div>
                            <h5>{t('bookingfrontend.contact_email')}</h5>
                            <p>{contact.email}</p>
                        </div>
                        <div>
                            <h5>{t('bookingfrontend.phone')}</h5>
                            <p>{contact.phone}</p>
                        </div>
                    </div>
                ))
            }
        </main>
    )
}

export default GroupView;