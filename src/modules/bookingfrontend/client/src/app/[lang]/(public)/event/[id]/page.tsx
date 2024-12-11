'use client'
import Link from "next/link";
import {Button} from "@digdir/designsystemet-react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faUserPlus, faPen} from "@fortawesome/free-solid-svg-icons";
import {FC, useState} from "react";
import {DateTime} from "luxon";
import { FilteredEventInfo, useEventPopperData } from "@/service/api/event-info";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import EventEditing from "@/components/event/event-editing-controller";

interface ResourceParams {
    id: string;
}

interface EventProps {
    params: ResourceParams;
}

interface EventProps {
    event: FilteredEventInfo
}
interface EventViewProps {
    event: FilteredEventInfo;
    openEditing: () => void;
}

const Event: FC<EventProps> = (props: EventProps) => {
    const eventId = parseInt(props.params.id, 10);
    // TODO: how to proceed in case if event id isnt number
    if (isNaN(eventId)) return null;

    const {data: event, isLoading}: { data: FilteredEventInfo, isLoading: boolean } = useEventPopperData(eventId);
    if (isLoading) return null;
    if (!isLoading && !event.id) return null;

    return <EventPage event={event}/>
}

const EventPage: FC<EventProps> = ({ event }: EventProps) => {
    const [isEditing, setEditingMode] = useState(false);
    const [eventState, setEventState] = useState(event);

    const cancelEditing = () => setEditingMode(false);
    const openEditing = () => setEditingMode(true);
    const saveChanges = (newEventObject: FilteredEventInfo) => {
        //TODO: Send update request
        setEventState(newEventObject);
        cancelEditing();
    }

    return isEditing ? 
        <EventEditing 
            saveChanges={saveChanges}
            cancelEditing={cancelEditing}
            eventBase={eventState}
        /> : 
        <EventView 
            event={eventState} 
            openEditing={openEditing}
        />;
}

const EventView: FC<EventViewProps> = ({ event, openEditing }: EventViewProps) => {
     //TODO: Where to find translations
     const t = useTrans();

    const whenTime = DateTime.fromJSDate(new Date(event.from_)).toFormat('dd. LLL yyyy kl HH:mm')
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
                    <Link href={`/event/${event.id}/participants`}>{t('participants_')}</Link>
                </Button>
                <Button variant='secondary' onClick={openEditing}>
                    <FontAwesomeIcon icon={faPen} />
                    {t('edit_')}
                </Button>
            </div>
        </main>
    );
}

export default Event
