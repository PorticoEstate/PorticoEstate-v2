'use client'
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import {FC} from "react";

interface AddParticipantsHeaderProps {
    activityName: string;
    maxParticipants: number;
    numberOfParticipants: number;
}

const AddParticipantsHeader: FC<AddParticipantsHeaderProps> = ({ activityName, maxParticipants, numberOfParticipants }: AddParticipantsHeaderProps) => {
    const { t } = useTrans();

    return (
        <>
            <h2>{activityName}</h2>
            <h3>
                {t('bookingfrontend.max_participants_info')}: 
                {maxParticipants}
            </h3>
            <h3>
                {t('booking.participants')}:
                {numberOfParticipants}
            </h3>
            <div>
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
