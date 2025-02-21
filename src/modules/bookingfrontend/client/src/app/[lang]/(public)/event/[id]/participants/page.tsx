import { FC } from "react";
import { notFound } from "next/navigation";
import AddParticipantsWrapper from "@/components/event/add-participants/add-participants-wrapper";

interface ResourceParams {
    id: string;
}

interface EventProps {
    params: ResourceParams;
}

const AddParticipant: FC<EventProps> = async (props: EventProps) => {
    const eventId = parseInt(props.params.id, 10);
    if (isNaN(eventId)) return notFound();

    return (
        <main>
            <AddParticipantsWrapper eventId={eventId} />
        </main>
    )
}

export default AddParticipant
