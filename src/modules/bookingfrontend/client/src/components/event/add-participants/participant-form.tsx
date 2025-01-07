'use client'
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { Button, Textfield } from "@digdir/designsystemet-react";
import { faCalendarCheck, faUserMinus, faUserPlus } from "@fortawesome/free-solid-svg-icons";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { FC, useState } from "react";


interface ParticipantFormProps {
	maxParticipants: number;
}

const ParticipantForm: FC<ParticipantFormProps> = ({ maxParticipants }: ParticipantFormProps) => {
	const t = useTrans();
	const [number, setNumber] = useState();

	const phoneNumberOnChange = ({ target: { value } }: any) => {}

	return (
		<>
			
			<Textfield 
				label=''
				placeholder={t('bookingfrontend.enter_participants_number')}
				value={maxParticipants}
				style={{marginBottom: '0.5rem'}}
			/>
			<Textfield 
				label=''
				onChange={phoneNumberOnChange}
				value={number}
				placeholder={t('bookingfrontend.enter_the_mobile_number_of_recipient')}
			/>
			<div style={{display: 'flex', marginTop: '1rem', flexWrap: 'wrap'}}>
				<Button style={{marginRight: '0.5rem'}} variant='secondary'>
					<FontAwesomeIcon icon={faCalendarCheck}/>
					{t('bookingfrontend.pre_register')}
				</Button>
				<Button style={{marginRight: '0.5rem'}} variant='secondary'>
					<FontAwesomeIcon icon={faUserMinus}/>
					{t('bookingfrontend.unregister')}
				</Button>
				<Button variant='secondary' disabled>
					<FontAwesomeIcon icon={faUserPlus}/>
					{t('bookingfrontend.register')}
				</Button>
			</div>
		</>
	)
}

export default ParticipantForm;
