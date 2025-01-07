import { FC } from "react";
import { fetchEventData, ActivityData } from "@/service/api/event-info";
import { notFound } from "next/navigation";
import AddParticipantsHeader from "@/components/event/add-participants/header";

interface ResourceParams {
    id: string;
}

interface EventProps {
    params: ResourceParams;
}


const AddParticipant: FC = async (props: EventProps) => {
    const eventId = parseInt(props.params.id, 10);
    if (isNaN(eventId)) return notFound();

    const data: ActivityData | null = await fetchEventData(eventId);
    if (!data) return notFound();

    return (
        <main>
            <AddParticipantsHeader
                activityName={data.activity_name}
                maxParticipants={data.info_participant_limit}
                numberOfParticipants={data.number_of_participants}
            />
        </main>
    )
}

export default AddParticipant
