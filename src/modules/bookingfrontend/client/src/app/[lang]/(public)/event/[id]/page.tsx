import {FC} from "react";
import { fetchEventData, ActivityData } from "@/service/api/event-info";
import { notFound } from "next/navigation";
import EventPageController from "@/components/event/page/page-controller";

interface ResourceParams {
    id: string;
}

interface EventProps {
    params: ResourceParams;
}

export async function generateMetadata(props: EventProps) {
    const eventId = parseInt(props.params.id, 10);
    if (isNaN(eventId)) return notFound();

    const data: ActivityData | null = await fetchEventData(eventId);
    if (!data) return notFound();

    return {
        title: data.activity_name
    }
}

const Event: FC<EventProps> = async (props: EventProps) => {
    const eventId = parseInt(props.params.id, 10);
    if (isNaN(eventId)) return notFound();

    const data: ActivityData | null = await fetchEventData(eventId);
    if (!data) return notFound();

    return <EventPageController event={data}/>
}

export default Event
