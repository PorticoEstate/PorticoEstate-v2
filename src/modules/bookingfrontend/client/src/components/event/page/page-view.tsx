"use client"
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { FilteredEventInfo } from "@/service/api/event-info";
import { Button, Link } from "@digdir/designsystemet-react";
import { faPen, faUserPlus } from "@fortawesome/free-solid-svg-icons";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { DateTime } from "luxon";
import { FC } from "react";
import styles from "../event.module.scss"
import ResourcesDropdown from "./event-resources-dropdown";

interface EventViewProps {
    event: FilteredEventInfo;
    openEditing: () => void;
}

const EventView: FC<EventViewProps> = ({ event, openEditing }: EventViewProps) => {
    const t = useTrans();

    const whenTime = DateTime.fromJSDate(event.info_when).toFormat('dd. LLL yyyy'); 
    const fromTime = DateTime.fromJSDate(new Date(event.from_)).toFormat('HH.mm');
    const toTime = DateTime.fromJSDate(new Date(event.to_)).toFormat('HH.mm');
    return (
        <main>
            <h2 style={{ marginBottom: '1rem' }}>{event.activity_name}</h2>
            <span>#{event.id}</span>
            <p style={{marginTop: '2rem'}}><b>{t('bookingfrontend.date')}: </b>{whenTime}</p>
            <p><b>Time: </b>{fromTime}-{toTime}</p>
            <p><b>{t('bookingfrontend.place')}: </b>{event.building_name}</p>
            <div className={styles.resourceViewBlock}>
                <b>{t('bookingfrontend.resource')}: </b>
                <ResourcesDropdown resources={event.info_resource_info.split(', ')}/>
            </div>
            <p><b>{t('bookingfrontend.organizer')}: </b>{event.organizer}</p>
            <p style={{marginTop: '2rem', marginBottom: '0'}}><b>{t('bookingfrontend.max_participants_info')}: </b>{event.info_participant_limit}</p>
            <p style={{marginTop: '0.6rem'}}><b>{t('booking.participants')}: </b>TODO: where to get the count?</p>
            <div style={{display: 'flex'}}>
                <Button variant='secondary'>
                    <FontAwesomeIcon icon={faUserPlus} />
                    <Link href={`./${event.id}/participants`}>{t('bookingfrontend.edit')}</Link>
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
