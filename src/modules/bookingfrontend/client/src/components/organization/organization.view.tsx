'use client';
import { Details, Link } from '@digdir/designsystemet-react';
import {default as NXLink} from "next/link";
import { Organization } from "@/service/types/api/organization.types";
import { useTrans } from '@/app/i18n/ClientTranslationProvider';

interface OrganizationView {
    organization: Organization;
}

const OrganizationView = ({ organization }: OrganizationView) => {
    const t = useTrans();
    console.log(organization);
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
                <Details>
                    <Details.Summary>{t('bookingfrontend.delegaters')}</Details.Summary>
                    <Details.Content>
                        { organization.delegaters.map((delegate) => (
                            <div key={delegate.id}>
                                <Link>
                                    <NXLink href={`/organization/delegate/${delegate.id}`}>
                                        {delegate.name}
                                    </NXLink>
                                </Link>
                                <span>{delegate.email}</span>
                                <hr />
                            </div>
                        )) }
                        <Link>
                            <NXLink href={`/organization/${organization.id}/delegate`}>
                                {t('bookingfrontend.create_delegate')}
                            </NXLink>
                        </Link>
                    </Details.Content>
                </Details>
                <Details>
                    <Details.Summary>{t('bookingfrontend.groups')}</Details.Summary>
                    <Details.Content>
                        { organization.groups.map((group) => (
                            <div key={group.id}>
                                <Link>
                                    <NXLink href={`/organization/group/${group.id}`}>
                                        {group.name}
                                    </NXLink>
                                </Link>
                                <span>{group.contact[0].name}</span>
                                <hr />
                            </div>
                        )) }
                        <Link>
                            <NXLink href={`/organization/${organization.id}/group`}>
                                {t('bookingfrontend.create_group')}
                            </NXLink>
                        </Link>
                    </Details.Content>
                </Details>
            </div>
        </main>
    );
} 

export default OrganizationView;