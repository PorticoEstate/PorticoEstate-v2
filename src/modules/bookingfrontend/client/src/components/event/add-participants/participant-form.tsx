'use client'
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { Button, Textfield } from "@digdir/designsystemet-react";
import { faCalendarCheck, faUserMinus, faUserPlus } from "@fortawesome/free-solid-svg-icons";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import styles from './add-participants.module.scss';
import { FC } from "react";
import { inRegistration, outRegistration, preRegistration } from "@/service/api/event-info";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import {z} from 'zod';

const phoneRegex = new RegExp(
  /^([+]?[\s0-9]+)?(\d{3}|[(]?[0-9]+[)])?([-]?[\s]?[0-9])+$/
);
const participantFormSchema: z.ZodType<{ phone: string, quantity: number }> = z.object({
    phone: z.string().regex(phoneRegex, 'Invalid phone number!'),
    quantity: z.coerce.number().gt(0)
})

interface ParticipantFormProps {
    eventId: number;
    pendingEvent: boolean;
    participantLimit: number;
}

const ParticipantForm: FC<ParticipantFormProps> = ({ eventId, pendingEvent, participantLimit }: ParticipantFormProps) => {
    const t = useTrans();

    const {
        control,
        handleSubmit,
        formState: {errors},
        setError
    } = useForm({
        resolver: zodResolver(participantFormSchema),
        defaultValues: {
            quantity: 0,
            phone: '',
        }
    })
    
    const onSubmit = (type: string) => {
        return (data: { phone: string, quantity: number }) => {
            if (data.quantity > participantLimit) {
                setError(
                    'quantity', 
                    { message: 'Too many participant' }, 
                    { shouldFocus: true }
                );
                return;
            }
            switch (type) {
                case 'pre':
                    preRegistration(eventId, data)
                    break;
                case 'in':
                    inRegistration(eventId, data);
                    break;
                case 'out':
                    outRegistration(eventId, data.phone);
                    break;
            }
        }
    }
    const formatPhoneNumber = (value: string) => {
        let formatted = value.replace(/[^\d+]/g, '');
        if (formatted.startsWith('47') && formatted.length > 2) {
            formatted = '+' + formatted;
        }
        if (formatted.startsWith('+47') && formatted.length > 3) {
            formatted = formatted.slice(0, 3) + formatted.slice(3);
        }
        return formatted;
    };

	return (
		<div className={styles.addParticipantsContainer}>
            <div className={styles.addParticipantsInputs}>
                <Controller 
                    name="phone"
                    control={control}
                    render={({field: {onChange, value}})=> (
                        <Textfield 
                            onChange={(e) => {
                                const newVal = formatPhoneNumber(e.target.value);
                                onChange(newVal);
                            }}
                            value={value}
                            label=''
                            error={errors.phone?.message ? t(errors.phone.message) : undefined}
                            placeholder={t('bookingfrontend.enter_participants_number')}
				        />
                    )}
                />
				<Controller 
                    name="quantity"
                    control={control}
                    render={({ field }) => (
                        <Textfield 
                            type="number"
                            {...field}
                            label=''
                            error={errors.quantity?.message ? t(errors.quantity.message) : undefined}
                            placeholder={t('bookingfrontend.enter_participants_number')}
				        />
                    )}
                />
			</div>
			<div className={styles.addParticipantsButtons}>
				<Button 
                    type="submit"
                    variant='secondary' 
                    disabled={pendingEvent}
                    onClick={(e) => {
                        handleSubmit(onSubmit('pre'))(e);
                    }}
                >
					<FontAwesomeIcon icon={faCalendarCheck}/>
					{t('bookingfrontend.pre_register')}
				</Button>
				<Button 
                    type="submit"
                    variant='secondary' 
                    disabled={!pendingEvent}
                    onClick={(e) => {
                        handleSubmit(onSubmit('out'))(e);
                    }}
                >
					<FontAwesomeIcon icon={faUserMinus}/>
					{t('bookingfrontend.unregister')}
				</Button>
				<Button 
                    type="submit"
                    variant='secondary' 
                    disabled={!pendingEvent}
                    onClick={(e) => {
                        handleSubmit(onSubmit('in'))(e);
                    }}
                >
					<FontAwesomeIcon icon={faUserPlus}/>
					{t('bookingfrontend.register')}
				</Button>
			</div>
			
		</div>
	)
}

export default ParticipantForm;
