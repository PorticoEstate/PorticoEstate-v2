'use client';
import {FC} from 'react';
import {Alert, Heading, Paragraph} from '@digdir/designsystemet-react';
import {CoffeeIcon} from '@navikt/aksel-icons';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {useServerSettings} from '@/service/hooks/api-hooks';
import styles from './hospitality-notice.module.scss';

/**
 * The marking that a room has food and drink («Mat og drikke kan bestilles») and the info box that says
 * where it is ordered. One place for the text and the gate, so every screen says the same thing.
 *
 * Both render nothing unless an admin has switched hospitality on (booking_config.enable_hospitality, the
 * gate the checkout uses) AND the caller says the room has it. `hasHospitality` is the API's has_hospitality,
 * which is the checkout's own predicate: the marking promises exactly what the checkout offers.
 */

/** True once the server settings have loaded and hospitality is switched on. */
export const useHospitalityEnabled = (): boolean => {
	const {data: serverSettings} = useServerSettings();
	return !!serverSettings?.booking_config?.enable_hospitality;
};

interface HospitalityLineProps {
	/** has_hospitality of the room. Anything but true renders nothing, and takes no space. */
	hasHospitality?: boolean | null;
	className?: string;
}

/** Cup icon and «Mat og drikke kan bestilles», on a row of its own. The text carries the meaning; the icon is only support. */
export const HospitalityLine: FC<HospitalityLineProps> = ({hasHospitality, className}) => {
	const t = useTrans();
	const enabled = useHospitalityEnabled();
	if (!enabled || hasHospitality !== true) {
		return null;
	}
	return (
		<Paragraph data-size="xs" data-color="accent" className={`${styles.line}${className ? ` ${className}` : ''}`}>
			<CoffeeIcon aria-hidden className={styles.icon}/>
			{t('bookingfrontend.hospitality_available')}
		</Paragraph>
	);
};

interface HospitalityAlertProps {
	/** has_hospitality of the room, or, in the form, whether any chosen room has it. Anything but true renders nothing. */
	hasHospitality?: boolean | null;
	/** `room`: about one room (resource page, info dialog). `form`: about the rooms chosen in the application. */
	variant: 'room' | 'form';
	/** The level of the box's own title, one below the heading the box sits under. */
	headingLevel?: 2 | 3 | 4 | 5 | 6;
	className?: string;
}

/** The blue info box «Mat og drikke»: it can be ordered, and it is done in the last step. No button, no link. */
export const HospitalityAlert: FC<HospitalityAlertProps> = ({hasHospitality, variant, headingLevel = 3, className}) => {
	const t = useTrans();
	const enabled = useHospitalityEnabled();
	if (!enabled || hasHospitality !== true) {
		return null;
	}
	return (
		<Alert data-color="info" data-size="sm" className={`${styles.alert}${className ? ` ${className}` : ''}`}>
			<Heading level={headingLevel} data-size="2xs">{t('bookingfrontend.hospitality_available_title')}</Heading>
			<Paragraph data-size="sm">
				{t(variant === 'form'
					? 'bookingfrontend.hospitality_available_form_info'
					: 'bookingfrontend.hospitality_available_room_info')}
			</Paragraph>
		</Alert>
	);
};
