'use client';

import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { Group } from "@/service/types/api/organization.types";

interface GroupViewProps {
    group: Group;
}

const GroupView = ({ group }: GroupViewProps) => {
    const t = useTrans();
    return (
        <main>
            <h3>{t('bookingfrontend.groupe_details')}</h3>
            <div>
                <h5>{t('bookingfrontend.name')}</h5>
                <p>{group.name}</p>
            </div>
            <div>
                <h5>{t('bookingfrontend.shortname')}</h5>
                <p>{group.shortname}</p>
            </div>
            <div>
                <h5>{t('bookingfrontend.organization-bedrift')}</h5>
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
                    <div>
                        <h3>{t('bookingfrontend.group_leader')} 1</h3>
                        <div>
                            <h5>{t('bookingfrontend.name')}</h5>
                            <p>{contact.name}</p>
                        </div>
                        <div>
                            <h5>{t('bookingfrontend.organization-bedrift')}</h5>
                            <p>{group.organization.name}</p>
                        </div>
                        <div>
                            <h5>{t('bookingfrontend.email')}</h5>
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