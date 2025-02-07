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


function getCommonValue<T extends { [key: string]: any }>(arr?: T[], key: keyof T): string | undefined {
    if (!arr || arr.length === 0) return '';

    const firstValue = arr[0][key];
    return arr.every(item => item[key] === firstValue) ? firstValue : '';
}

const CheckoutEventDetails: FC<CheckoutEventDetailsProps> = ({onDetailsChange, user, partials}) => {
    const t = useTrans();

    const defaultValues = useMemo(() => ({
        title: getCommonValue(partials, 'name'),
        // If all partials have same organizer, use that, otherwise fall back to user name
        organizerName: getCommonValue(partials, 'organizer') || user?.name || '',
    }), [user, partials]);

    const {control, watch} = useForm<CheckoutEventDetailsData>({
        resolver: zodResolver(checkoutEventDetailsSchema),
        defaultValues: defaultValues
    });

    useEffect(() => {
        const subscription = watch((value) => onDetailsChange(value as CheckoutEventDetailsData));
        return () => subscription.unsubscribe();
    }, [watch, onDetailsChange]);

    return (
        <section className={styles.eventDetails}>
            <div className={styles.formFields}>
                <Controller
                    name="title"
                    control={control}
                    render={({field, fieldState}) => (
                        <Textfield
                            label={t('bookingfrontend.event_title')}
                            {...field}
                            error={fieldState.error?.message}
                        />
                    )}
                />
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