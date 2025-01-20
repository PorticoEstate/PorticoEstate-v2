"use client"
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { ActivityData } from "@/service/api/event-info";
import { Button, Link } from "@digdir/designsystemet-react";
import { faPen, faUserPlus } from "@fortawesome/free-solid-svg-icons";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { DateTime } from "luxon";
import { FC } from "react";
import styles from "../event.module.scss"
import ResourcesDropdown from "./event-resources-dropdown";

interface EventViewProps {
    event: ActivityData;
    openEditing: () => void;
}

const EventView: FC<EventViewProps> = ({ event, openEditing }: EventViewProps) => {
    const t = useTrans();

    const date = DateTime.fromJSDate(event.from_).toFormat('dd. LLL yyyy'); 
    const fromTime = DateTime.fromJSDate(event.from_).toFormat('HH.mm');
    const toTime = DateTime.fromJSDate(event.to_).toFormat('HH.mm');

    return (
        <main style={{padding: '0px 5px'}}>
            <h2 style={{ marginBottom: '1rem' }}>{event.name}</h2>
            <span>#{event.id}</span>
            <p style={{marginTop: '2rem'}}><b>{t('bookingfrontend.date')}: </b>{date}</p>
            <p><b>Time: </b>{fromTime}-{toTime}</p>
            <p>
                <b>{t('bookingfrontend.place')}: </b>
                <Link 
                    href={`../building/${event.building_id}`}
                >{event.building_name}
                </Link> 
            </p>
            <div className={styles.resourceViewBlock}>
                <b>{t('bookingfrontend.resource')}: </b>
                <ResourcesDropdown resources={event.resources}/>
            </div>
            <p><b>{t('bookingfrontend.organizer')}: </b>{event.organizer}</p>
            <p style={{marginTop: '2rem', marginBottom: '0'}}><b>{t('bookingfrontend.max_participants_info')}: </b>{event.participant_limit}</p>
            <p style={{marginTop: '0.6rem'}}><b>{t('booking.participants')}: </b>{event.number_of_participants}</p>
            <div style={{display: 'flex'}}>
                <Button asChild style={{marginRight: '0.5rem'}} variant='secondary'>
                    <Link 
                        style={{textDecoration: 'none'}}
                        href={`./${event.id}/participants`}
                    >
                        <FontAwesomeIcon icon={faUserPlus} />
                        {t('bookingfrontend.edit')}
                    </Link>
                </Button>
                <Button variant='secondary' onClick={openEditing}>
                    <FontAwesomeIcon icon={faPen} />
                    {t('bookingfrontend.participant_registration')}
                </Button>
            </div>
        </main>
    );
}

export default EventView
