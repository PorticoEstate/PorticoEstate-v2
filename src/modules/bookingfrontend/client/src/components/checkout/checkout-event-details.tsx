import {FC, useEffect, useMemo} from 'react';
import {Controller, useForm} from "react-hook-form";
import {zodResolver} from "@hookform/resolvers/zod";
import {Textfield} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import styles from './checkout.module.scss';
import {CheckoutEventDetailsData, checkoutEventDetailsSchema} from './checkout-event-details-schema';
import {IBookingUser} from "@/service/types/api.types";
import {IApplication} from "@/service/types/api/application.types";
import {useUpdatePartialApplication} from "@/service/hooks/api-hooks";

interface CheckoutEventDetailsProps {
	onDetailsChange: (data: CheckoutEventDetailsData) => void;
	user: IBookingUser;
	partials: IApplication[];
}


function getCommonValue<T extends { [key: string]: any }>(arr: (T[] | undefined), key: keyof T): string {
	if (!arr || arr.length === 0) return '';

	const firstValue = arr[0][key];
	const res = arr.every(item => item[key] === firstValue) ? firstValue : '';
	if (res === 'dummy') {
		return '';
	}
	return res;
}

const CheckoutEventDetails: FC<CheckoutEventDetailsProps> = ({onDetailsChange, user, partials}) => {
	const t = useTrans();

	const defaultValues = useMemo(() => ({
		// If all partials have same organizer, use that, otherwise fall back to user name
		organizerName: getCommonValue(partials, 'organizer') || user?.name || '',
	}), [user, partials]);

	const {control, watch} = useForm<CheckoutEventDetailsData>({
		resolver: zodResolver(checkoutEventDetailsSchema),
		defaultValues: defaultValues
	});

	// Add this effect to handle initial values
	useEffect(() => {
		onDetailsChange(defaultValues);
	}, [defaultValues, onDetailsChange]);

	useEffect(() => {
		const subscription = watch((value) => onDetailsChange(value as CheckoutEventDetailsData));
		return () => subscription.unsubscribe();
	}, [watch, onDetailsChange]);

	return (
		<section className={styles.eventDetails}>
			<div className={styles.formFields}>
				<Controller
					name="organizerName"
					control={control}
					render={({field, fieldState}) => (
						<Textfield
							label={t('bookingfrontend.organizer')}
							{...field}
							error={fieldState.error?.message}
						/>
					)}
				/>
			</div>
		</section>
	);
};

export default CheckoutEventDetails;