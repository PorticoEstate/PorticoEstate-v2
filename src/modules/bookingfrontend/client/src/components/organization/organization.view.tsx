'use client';
import { Details, Link, Icon, Button} from '@digdir/designsystemet-react';
import {default as NXLink} from "next/link";
import { Organization } from "@/service/types/api/organization.types";
import { useTrans } from '@/app/i18n/ClientTranslationProvider';
import { faSitemap, faUserPlus, faPlus } from "@fortawesome/free-solid-svg-icons";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";

import styles from './styles/organization.view.module.scss';

interface OrganizationView {
    organization: Organization;
}

const OrganizationView = ({ organization }: OrganizationView) => {
    const t = useTrans();
    console.log(organization);
    return (
        <main>
            <div className={styles.header}>
                <FontAwesomeIcon icon={faSitemap} /> 
                <h2>{organization.name}</h2>
            </div>
            <div className={styles.position}>
                <div>
                    <p>{organization.district}</p>
                    <ul><li>{organization.city}</li></ul>
                </div>
                <p>{organization.street}</p>
            </div>
            <div className={styles.contact}>
                <h3>{t('bookingfrontend.contact information')}</h3>
                <div>
                    <span><b>{t('bookingfrontend.name')}:</b></span>
                    <p>TODO: no contact name</p>
                </div>
                <div>
                    <span><b>{t('bookingfrontend.contact_email')}:</b></span>
                    <p>{organization.email}</p>
                </div>
                <div>
                    <span><b>{t('bookingfrontend.phone')}:</b></span>
                    <p>{organization.phone}</p>
                </div>
            </div>
            <div>
                <Details>
                    <Details.Summary>{t('bookingfrontend.information')}</Details.Summary>
                    <Details.Content>
                        {organization.name}
                    </Details.Content>
                </Details>
                <Details>
                    <Details.Summary>{t('bookingfrontend.Used buildings (2018)')}</Details.Summary>
                    <Details.Content>
                        TODO: No building data
                    </Details.Content>
                </Details>
                <Details>
                    <Details.Summary>{t('bookingfrontend.delegaters')}</Details.Summary>
                    <Details.Content>
                        { organization.delegaters.map((delegate) => (
                            <div key={delegate.id} className={styles.listed_delegate}>
                                <NXLink href={`/organization/delegate/${delegate.id}`}>
                                    <Link data-color='info'>
                                        {delegate.name}
                                    </Link>
                                </NXLink>
                                <div>
                                    <span><b>{t('bookingfrontend.contact_email')}:</b></span>
                                    <p>{delegate.email}</p>
                                </div>
                                <div>
                                    <span><b>{t('bookingfrontend.phone')}:</b></span>
                                    <p>{delegate.phone}</p>
                                </div>
                            </div>
                        )) }
                        <NXLink href={`/organization/${organization.id}/delegate`}>
                            <Button variant='tertiary'>
                                <FontAwesomeIcon icon={faUserPlus} />
                                {t('bookingfrontend.create_delegate')}
                            </Button>
                        </NXLink>
                    </Details.Content>
                </Details>
                <Details>
                    <Details.Summary>{t('bookingfrontend.groups')}</Details.Summary>
                    <Details.Content>
                        { organization.groups.map((group) => (
                            <div key={group.id} className={styles.listed_group}>
                                <NXLink href={`/organization/group/${group.id}`}>
                                    <Link data-color='brand1'>
                                        {group.name}
                                    </Link>
                                </NXLink>
                                <span>{group.contact[0].name}</span>
                            </div>
                        )) }
                        <NXLink href={`/organization/${organization.id}/group`}>
                            <Button variant='tertiary' data-color='accent'>
                                <FontAwesomeIcon icon={faPlus} />   
                                {t('bookingfrontend.create_group')}
                            </Button>                 
                        </NXLink>
                    </Details.Content>
                </Details>
            </div>
        </main>
    );
} 

export default OrganizationView;