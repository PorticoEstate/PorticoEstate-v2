'use client'
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { Button, Textfield } from "@digdir/designsystemet-react";
import { faCalendarCheck, faUserMinus, faUserPlus } from "@fortawesome/free-solid-svg-icons";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import styles from './add-participants.module.scss';
import { FC, useState } from "react";
import { inRegistration, outRegistration, preRegistration } from "@/service/api/event-info";

interface ParticipantFormProps {
    eventId: number;
    pendingEvent: boolean;
}

const ParticipantForm: FC<ParticipantFormProps> = ({ eventId, pendingEvent }: ParticipantFormProps) => {
	const t = useTrans();
	const [phone, setPhone] = useState('');
    const [quantity, setQuantity] = useState(0);

	const phoneNumberOnChange = ({ target: { value } }: any) => {
        setPhone(value);
    }
    
	return (
		<div className={styles.addParticipantsContainer}>
			<div className={styles.addParticipantsInputs}>
				<Textfield 
                    type="number"
					label=''
					placeholder={t('bookingfrontend.enter_participants_number')}
                    onChange={({ target }) => setQuantity(parseInt(target.value))}
					value={quantity}
				/>
				<Textfield 
					label=''
					onChange={phoneNumberOnChange}
					value={phone}
					placeholder={t('bookingfrontend.enter_the_mobile_number_of_recipient')}
				/>
			</div>
			<div className={styles.addParticipantsButtons}>
				<Button 
                    variant='secondary' 
                    disabled={pendingEvent}
                    onClick={() => preRegistration(eventId, phone, quantity)} 
                >
					<FontAwesomeIcon icon={faCalendarCheck}/>
					{t('bookingfrontend.pre_register')}
				</Button>
				<Button 
                    variant='secondary' 
                    disabled={!pendingEvent}
                    onClick={() => outRegistration(eventId, phone)} 
                >
					<FontAwesomeIcon icon={faUserMinus}/>
					{t('bookingfrontend.unregister')}
				</Button>
				<Button 
                    variant='secondary' 
                    disabled={!pendingEvent}
                    onClick={() => inRegistration(eventId, phone, quantity)} 
                >
					<FontAwesomeIcon icon={faUserPlus}/>
					{t('bookingfrontend.register')}
				</Button>
			</div>
		</div>
	)
}

export default ParticipantForm;
