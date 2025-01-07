'use client'
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import {FC} from "react";

interface AddParticipantsHeaderProps {
    activityName: string;
    maxParticipants: number;
    numberOfParticipants: number;
}

const AddParticipantsHeader: FC<AddParticipantsHeaderProps> = ({ activityName, maxParticipants, numberOfParticipants }: AddParticipantsHeaderProps) => {
    const t = useTrans();

    return (
        <>
            <h2>{activityName}</h2>
            <p>
                <b>{t('bookingfrontend.max_participants_info')}</b>: 
                {maxParticipants}
            </p>
            <p>
                <b>{t('booking.participants')}</b>: 
                {numberOfParticipants}
            </p>
            <div style={{marginBottom: '0.7rem'}}>
                <h2>{t('bookingfrontend.participant_registration')}</h2>
                <ol>
                    <li>{t('bookingfrontend.enter_number_of_participant')}</li>
                    <li>{t('bookingfrontend.enter_the_mobile_number_of_recipient')}</li>
                    <li>{t('bookingfrontend.register_unregister_pre-register')}</li>
                </ol>
            </div>
        </>
    )
}

export default AddParticipantsHeader;
