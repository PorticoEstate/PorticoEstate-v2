'use client';
import { Details } from '@digdir/designsystemet-react';
import { Organization } from "@/service/types/api/organization.types";
import { useTrans } from '@/app/i18n/ClientTranslationProvider';

interface OrganizationView {
    organization: Organization;
}

const OrganizationView = ({ organization }: OrganizationView) => {
    const t = useTrans();
    return (
        <main>
            <h2>{organization.name}</h2>
            <p>{organization.city}</p>
            <p>{organization.district}</p>
            <p>{organization.street}</p>
            <div>
                <Details>
                    <Details.Summary>{t('bookingfrontend.information')}</Details.Summary>
                    <Details.Content>
                        {organization.name}
                    </Details.Content>
                </Details>
                <Details>
                    <Details.Summary>{t('bookingfrontend.contact_information')}</Details.Summary>
                    <Details.Content>
                        <div>
                            <span>{t('bookingfrontend.name')}</span>
                            <p>TODO: no contact name</p>
                        </div>
                        <div>
                            <span>{t('bookingfrontend.email')}</span>
                            <p>{organization.email}</p>
                        </div>
                        <div>
                            <span>{t('bookingfrontend.phone')}</span>
                            <p>{organization.phone}</p>
                        </div>
                    </Details.Content>
                </Details>
                <Details>
                    <Details.Summary>{t('bookingfrontend.facility')}</Details.Summary>
                    <Details.Content>
                        TODO: No building data
                    </Details.Content>
                </Details>
            </div>
        </main>
    );
} 

export default OrganizationView;