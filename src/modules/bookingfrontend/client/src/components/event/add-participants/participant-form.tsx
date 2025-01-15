'use client'
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { Button, Textfield } from "@digdir/designsystemet-react";
import { faCalendarCheck, faUserMinus, faUserPlus } from "@fortawesome/free-solid-svg-icons";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import styles from './add-participants.module.scss';
import { FC, useState } from "react";

interface ParticipantFormProps {
	maxParticipants: number;
}

const ParticipantForm: FC<ParticipantFormProps> = ({ maxParticipants }: ParticipantFormProps) => {
	const t = useTrans();
	const [number, setNumber] = useState();

	const phoneNumberOnChange = ({ target: { value } }: any) => {}

	return (
		<div className={styles.addParticipantsContainer}>
			<div className={styles.addParticipantsInputs}>
				<Textfield 
					label=''
					placeholder={t('bookingfrontend.enter_participants_number')}
					value={maxParticipants}
				/>
				<Textfield 
					label=''
					onChange={phoneNumberOnChange}
					value={number}
					placeholder={t('bookingfrontend.enter_the_mobile_number_of_recipient')}
				/>
			</div>
			<div  className={styles.addParticipantsButtons}>
				<Button variant='secondary'>
					<FontAwesomeIcon icon={faCalendarCheck}/>
					{t('bookingfrontend.pre_register')}
				</Button>
				<Button variant='secondary'>
					<FontAwesomeIcon icon={faUserMinus}/>
					{t('bookingfrontend.unregister')}
				</Button>
				<Button variant='secondary' disabled>
					<FontAwesomeIcon icon={faUserPlus}/>
					{t('bookingfrontend.register')}
				</Button>
			</div>
		</div>
	)
}

export default ParticipantForm;
