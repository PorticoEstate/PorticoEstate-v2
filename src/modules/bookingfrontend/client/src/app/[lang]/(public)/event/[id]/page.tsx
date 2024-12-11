'use client'
import {Spinner, Button} from "@digdir/designsystemet-react";
import {FC} from "react";
import {DateTime} from 'luxon';
import { FilteredEventInfo, useEventPopperData } from "@/service/api/event-info";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";

interface ResourceParams {
    id: string;
}

interface ResourceProps {
    params: ResourceParams;
}

const Event: FC<ResourceProps> = (props: ResourceProps) => {
    const eventId = parseInt(props.params.id, 10);
    // TODO: how to proceed in case if event id isnt number
    if (isNaN(eventId)) return null;

    const {data: event, isLoading}: { data: FilteredEventInfo, isLoading: boolean } = useEventPopperData(eventId);

    if (isLoading) return <Spinner aria-label={'Loading event'}/>

    // TODO: how to proceed in case if event not found
    if (!isLoading && !event.id) return null;

    //TODO: Where to find translations
    const t = useTrans();

    const whenTime = DateTime.fromJSDate(new Date(event.from_)).toFormat('dd. LLL yyyy kl HH:mm')
    const fromTime = DateTime.fromJSDate(new Date(event.from_)).toFormat('HH.mm');
    const toTime = DateTime.fromJSDate(new Date(event.to_)).toFormat('HH.mm');
    return (
        <main>
            <h1 style={{ marginBottom: '1rem' }}>{event.activity_name}</h1>
            <span>#{event.id}</span>
            <p><b>Date: </b>{whenTime}</p>
            <p><b>Time: </b>{fromTime}-{toTime}</p>
            <p><b>{t('place_')}: </b>{event.building_name}</p>
            <p><b>{t('resource_')}: </b>{event.resources[0]}</p>
            <p><b>{t('organizer_')}: </b>{event.organizer}</p>
            <p><b>{t('max_participants_')}: </b>{event.info_participant_limit}</p>
            <p><b>{t('participants_')}: </b>TODO: where to get the count?</p>
            <div style={{display: 'flex'}}>
                <Button variant='secondary'>{t('participants_')}</Button>
                <Button variant='secondary'>{t('edit_')}</Button>
            </div>
        </main>
    );
}

export default Event
