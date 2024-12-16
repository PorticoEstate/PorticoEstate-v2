"use client"
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { FilteredEventInfo } from "@/service/api/event-info";
import { Button, Link } from "@digdir/designsystemet-react";
import { faPen, faUserPlus } from "@fortawesome/free-solid-svg-icons";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { DateTime } from "luxon";
import { FC } from "react";

interface EventViewProps {
    event: FilteredEventInfo;
    openEditing: () => void;
}

const EventView: FC<EventViewProps> = ({ event, openEditing }: EventViewProps) => {
    //TODO: Where to find translations
    const t = useTrans();

    const whenTime = DateTime.fromJSDate(new Date(event.info_when.split(" ")[0])).toFormat('dd. LLL yyyy'); 
    const fromTime = DateTime.fromJSDate(new Date(event.from_)).toFormat('HH.mm');
    const toTime = DateTime.fromJSDate(new Date(event.to_)).toFormat('HH.mm');
    return (
        <main>
            <h2 style={{ marginBottom: '1rem' }}>{event.activity_name}</h2>
            <span>#{event.id}</span>
            <p style={{marginTop: '2rem'}}><b>Date: </b>{whenTime}</p>
            <p><b>Time: </b>{fromTime}-{toTime}</p>
            <p><b>{t('place_')}: </b>{event.building_name}</p>
            <p><b>{t('resource_')}: </b>{event.info_resource_info}</p>
            <p><b>{t('organizer_')}: </b>{event.organizer}</p>
            <p style={{marginTop: '2rem', marginBottom: '0'}}><b>{t('max_participants_')}: </b>{event.info_participant_limit}</p>
            <p style={{marginTop: '0.6rem'}}><b>{t('participants_')}: </b>TODO: where to get the count?</p>
            <div style={{display: 'flex'}}>
                <Button variant='secondary'>
                    <FontAwesomeIcon icon={faUserPlus} />
                    <Link href={`./${event.id}/participants`}>{t('participants_')}</Link>
                </Button>
                <Button variant='secondary' onClick={openEditing}>
                    <FontAwesomeIcon icon={faPen} />
                    {t('edit_')}
                </Button>
            </div>
        </main>
    );
}

export default EventView
