'use client';
import { Details, Link, Button} from '@digdir/designsystemet-react';
import {default as NXLink} from "next/link";
import { Organization } from "@/service/types/api/organization.types";
import { useTrans } from '@/app/i18n/ClientTranslationProvider';
import { faSitemap, faUserPlus, faPlus, faHouse } from "@fortawesome/free-solid-svg-icons";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";

import styles from './styles/organization.view.module.scss';

interface OrganizationView {
    organization: Organization;
}

const OrganizationContacts = ({ email, phone }) => {
    const t = useTrans();
    const emailBlock = email ? (
        <div>
            <span><b>{t('bookingfrontend.contact_email')}:</b></span>
            <p>{email}</p>
        </div> 
    ) : null;
    const phoneBlock = phone ? (
        <div>
            <span><b>{t('bookingfrontend.phone')}:</b></span>
            <p>{phone}</p>
        </div>
    ) : null;

    return (
        <div className={styles.contact}>
            <h3>{t('bookingfrontend.contact information')}</h3>
            { 
                !emailBlock && !phoneBlock 
                ? <div>{t('bookingfrontend.no Data.')}</div> 
                : <>{emailBlock}{phoneBlock}</>
            }
        </div>
    )
}

const OrganizationView = ({ organization }: OrganizationView) => {
    const t = useTrans();
    const mapsLink = `https://www.google.com/maps/search/${organization.street}, ${organization.zip_code} ${organization.district}`

    return (
        <main className={styles.main_container}>
            <div className={styles.header}>
                <FontAwesomeIcon icon={faSitemap} /> 
                <h2>{organization.name}</h2>
            </div>
            <div className={styles.position}>
                <div> 
                    <p>{organization.city}</p>
                    <ul><li>{organization.district}</li></ul>
                </div>
                <ul>
                    <li>
                        <Link target="_blank" rel="noopener noreferrer" href={mapsLink} data-color="brand1">
                            {organization.street}, {organization.zip_code} {organization.district}
                        </Link>
                    </li>
                </ul>
            </div>
            <OrganizationContacts email={organization.email} phone={organization.phone}/>
            <div>
                <Details>
                    <Details.Summary>{t('bookingfrontend.buildings')}</Details.Summary>
                    <Details.Content>
                        { organization.buildings.map((building) => (
                            <Button 
                                key={`building-${building.id}`} 
                                variant='secondary'
                                style={{ marginBottom: '0.5rem' }}
                            >
                                <FontAwesomeIcon icon={faHouse} />
                                {building.name}
                            </Button>
                        )) }
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