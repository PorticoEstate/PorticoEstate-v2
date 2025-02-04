'use client'
import { useEventData } from "@/service/api/event-info";
import { FC } from "react";
import AddParticipantsHeader from "./header";
import ParticipantForm from "./participant-form";
import { Spinner } from "@digdir/designsystemet-react";

interface WrapperProps {
	eventId: number
}

const AddParticipantsWrapper: FC<WrapperProps> = ({ eventId }: WrapperProps) => {
	const {data: eventInfo, isLoading} = useEventData(eventId);
    if (!isLoading && eventInfo) {
		const access = eventInfo.name !== 'PRIVATE EVENT';
		if (!access) return null;
        const pendingEvent = eventInfo.from_ <= new Date();
        return (
			<>
				<AddParticipantsHeader
					activityName={eventInfo.name}
					maxParticipants={eventInfo.participant_limit}
					numberOfParticipants={eventInfo.numberOfParticipants}
				/>
				<ParticipantForm 
					eventId={eventInfo.id}
                    pendingEvent={pendingEvent}
					participantLimit={eventInfo.participant_limit}
				/> 
			</>
		)
    } else if (!isLoading && !eventInfo) {
        return null;
    }
    return <Spinner aria-label='Laster event info'/>
}

export default AddParticipantsWrapper;