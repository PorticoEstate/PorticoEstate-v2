import { FC } from "react";
import { fetchEventData, ActivityData } from "@/service/api/event-info";
import { notFound } from "next/navigation";
import AddParticipantsHeader from "@/components/event/add-participants/header";
import ParticipantForm from "@/components/event/add-participants/participant-form";

interface ResourceParams {
    id: string;
}

interface EventProps {
    params: ResourceParams;
}

const AddParticipant: FC<EventProps> = async (props: EventProps) => {
    const eventId = parseInt(props.params.id, 10);
    if (isNaN(eventId)) return notFound();

    const data: ActivityData | null = await fetchEventData(eventId);
    if (!data) return notFound();

    return (
        <main style={{padding: '0px 5px'}}>
            <AddParticipantsHeader
                activityName={data.activity_name}
                maxParticipants={data.info_participant_limit}
                numberOfParticipants={data.number_of_participants}
            />
            <ParticipantForm 
                maxParticipants={data.info_participant_limit}
            /> 
        </main>
    )
}

export default AddParticipant
