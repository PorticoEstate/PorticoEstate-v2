import {FC, useEffect, useMemo} from 'react';
import {Controller, useForm} from "react-hook-form";
import {zodResolver} from "@hookform/resolvers/zod";
import {Textfield} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import styles from './checkout.module.scss';
import {CheckoutEventDetailsData, createCheckoutEventDetailsSchema} from './checkout-event-details-schema';
import {IBookingUser} from "@/service/types/api.types";
import {IApplication} from "@/service/types/api/application.types";
import {useUpdatePartialApplication} from "@/service/hooks/api-hooks";

interface CheckoutEventDetailsProps {
	onDetailsChange: (data: CheckoutEventDetailsData) => void;
	user: IBookingUser;
	partials: IApplication[];
	showError?: boolean;
	onErrorClear?: () => void;
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

const CheckoutEventDetails: FC<CheckoutEventDetailsProps> = ({onDetailsChange, user, partials, showError = false, onErrorClear}) => {
	const t = useTrans();

	const defaultValues = useMemo(() => ({
		// If all partials have same organizer, use that, otherwise fall back to user name
		organizerName: getCommonValue(partials, 'organizer') || user?.name || '',
	}), [user, partials]);

	const {control, watch, setValue, getValues} = useForm<CheckoutEventDetailsData>({
		resolver: zodResolver(createCheckoutEventDetailsSchema(t)),
		defaultValues: defaultValues
	});

	// Add this effect to handle initial values
	useEffect(() => {
		onDetailsChange(defaultValues);
	}, [defaultValues, onDetailsChange]);

	// Effect to refill blank fields when user data refreshes
	useEffect(() => {
		const currentValues = getValues();
		// Only refill organizerName if it's currently blank and user has a name
		if (!currentValues.organizerName && user?.name) {
			setValue('organizerName', user.name);
		}
	}, [user, setValue, getValues]);

	useEffect(() => {
		const subscription = watch((value) => {
			onDetailsChange(value as CheckoutEventDetailsData);
			// Clear error when user starts typing
			if (showError && onErrorClear && value.organizerName) {
				onErrorClear();
			}
		});
		return () => subscription.unsubscribe();
	}, [watch, onDetailsChange, showError, onErrorClear]);

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
							error={fieldState.error?.message || (showError && !field.value ? t('bookingfrontend.organizer_required') : undefined)}
							required
						/>
					)}
				/>
			</div>
		</section>
	);
};

export default CheckoutEventDetails;