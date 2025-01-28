import {FC} from "react";
import { notFound } from "next/navigation";
import EventPageWrapper from "@/components/event/page/page-wrapper";

interface ResourceParams {
    id: string;
}

interface EventProps {
    params: ResourceParams;
}

export async function generateMetadata(props: EventProps) {
    const eventId = parseInt(props.params.id, 10);
    if (isNaN(eventId)) return notFound();

    return {
        id: eventId
    }
}

const Event: FC<EventProps> = async (props: EventProps) => {
    const eventId = parseInt(props.params.id, 10);
    if (isNaN(eventId)) return notFound();

    return <EventPageWrapper eventId={eventId}/>
}

export default Event
