'use client';
import { Details, Link, Button } from '@digdir/designsystemet-react';
import { default as NXLink } from "next/link";
import { Organization } from "@/service/types/api/organization.types";
import { useTrans } from '@/app/i18n/ClientTranslationProvider';
import { LaptopIcon, PlusIcon, PersonPlusIcon, Buildings3Icon } from '@navikt/aksel-icons';
import styles from './styles/organization.view.module.scss';
import MapModal from '../map-modal/map-modal';

interface OrganizationView {
    data: Organization;
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

const OrganizationView = ({ data }: OrganizationView) => {
    const t = useTrans();

    return (
        <main className={styles.main_container}>
            <div className={styles.header}>
                <Buildings3Icon fontSize="1.5rem" />
                <h2>{data.name}</h2>
            </div>
            <div className={styles.position}>
                <div>
                    <p>{data.city}</p>
                    <ul><li>{data.district}</li></ul>
                </div>
                <MapModal 
                    city={data.city} 
                    street={data.street} 
                    zip={data.zip_code} 
                />
            </div>
            <OrganizationContacts email={data.email} phone={data.phone} />
            <div>
                <Details>
                    <Details.Summary>{t('bookingfrontend.buildings')}</Details.Summary>
                    <Details.Content>
                        {data.buildings.map((building) => (
                            <NXLink 
                                key={`short-building-${building.id}`} 
                                href={`/building/${building.id}`}
                            >
                                <Button
                                    key={`building-${building.id}`}
                                    variant='secondary'
                                    style={{ marginBottom: '0.5rem' }}
                                >
                                    <LaptopIcon />
                                    {building.name}
                                </Button>
                            </NXLink>
                            
                        ))}
                    </Details.Content>
                </Details>
                <Details>
                    <Details.Summary>{t('bookingfrontend.delegaters')}</Details.Summary>
                    <Details.Content>
                        {data.delegaters.map((delegate) => (
                            <div key={delegate.id} className={styles.listed_delegate}>
                                <NXLink 
                                    href={`/organization/${data.id}/delegate/${delegate.id}`}
                                >
                                    <Link asChild={true} data-color='info'>
                                        <span>{delegate.name}</span>
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
                        ))}
                        <NXLink href={`/organization/${data.id}/delegate`}>
                            <Button variant='tertiary'>
                                <PersonPlusIcon />
                                {t('bookingfrontend.create_delegate')}
                            </Button>
                        </NXLink>
                    </Details.Content>
                </Details>
                <Details>
                    <Details.Summary>{t('bookingfrontend.groups')}</Details.Summary>
                    <Details.Content>
                        {data.groups.map((group) => (
                            <div key={group.id} className={styles.listed_group}>
                                <NXLink 
                                    href={`/organization/${data.id}/group/${group.id}`}
                                >
                                    <Link asChild={true} data-color='brand1'>
                                        <span>{group.name}</span>
                                    </Link>
                                </NXLink>
                                <span>{group.contact[0].name}</span>
                            </div>
                        ))}
                        <NXLink href={`/organization/${data.id}/group`}>
                            <Button variant='tertiary' data-color='accent'>
                                <PlusIcon />
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