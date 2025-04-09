'use client'
import React, {FC, useState} from 'react';
import CartSection from "./cart-section";
import {useBookingUser, usePartialApplications, useUpdatePartialApplication} from "@/service/hooks/api-hooks";
import { CheckoutEventDetailsData } from './checkout-event-details-schema';
import { BillingFormData } from './billing-form-schema';
import CheckoutEventDetails from "@/components/checkout/checkout-event-details";
import BillingForm from "@/components/checkout/billing-form";
import styles from './checkout.module.scss';
import {Spinner} from "@digdir/designsystemet-react";
import ApplicationCrud from "@/components/building-calendar/modules/event/edit/application-crud";
import {useCheckoutApplications} from "@/components/checkout/hooks/checkout-hooks";
import {phpGWLink} from "@/service/util";
import {useRouter} from "next/navigation";

const CheckoutContent: FC = () => {
    const {data: applications, isLoading: partialsLoading} = usePartialApplications();
    const {data: user, isLoading: userLoading} = useBookingUser();
    const updateMutation = useUpdatePartialApplication();
    const [eventDetails, setEventDetails] = useState<CheckoutEventDetailsData>();
    const checkoutMutation = useCheckoutApplications();
    const [billingDetails, setBillingDetails] = useState<BillingFormData>();
    const [currentApplication, setCurrentApplication] = useState<{
        application_id: number,
        date_id: number,
        building_id: number
    }>();
    const router = useRouter();


    const handleSubmit = async () => {
        if (!eventDetails || !applications || !billingDetails) {
            console.log('missing Data', eventDetails, billingDetails);
            return;
        }

        try {
            // First update all applications with event details
            // await Promise.all(applications.list.map(application =>
            //     updateMutation.mutateAsync({
            //         id: application.id,
            //         application: {
            //             id: application.id,
            //             name: eventDetails.title,
            //             organizer: eventDetails.organizerName
            //         }
            //     })
            // ));

            // TODO: Handle billing details submission
            const finalData = {
                ...eventDetails,
                ...billingDetails,
            };

            checkoutMutation.mutateAsync({
                eventTitle: eventDetails.title,
                organizerName: eventDetails.organizerName,
                customerType: billingDetails?.customerType || 'ssn',
                contactName: billingDetails.contactName,
                contactEmail: billingDetails.contactEmail,
                contactPhone: billingDetails.contactPhone,
                street: billingDetails.street,
                zipCode: billingDetails.zipCode,
                city: billingDetails.city
            }).then(() => {
                router.push('/user/applications');
            })
            // const returnUrl = encodeURI(window.location.href.split('bookingfrontend')[1]);
            // const loginUrl = phpGWLink(['bookingfrontend', '/'], { after: returnUrl });
            // console.log(finalData);
        } catch (error) {
            console.error('Error updating applications:', error);
            // TODO: Handle error (show error message to user)
        }
    };

    if(userLoading || partialsLoading || checkoutMutation.isPending) {
        return <Spinner aria-label={'laster brukerinfo'} />
    }
    if(!user || !applications || applications.list.length === 0) {
        router.push('/');

        return ''

    }

    return (
        <div className={styles.content}>
            <CheckoutEventDetails user={user} partials={applications.list} onDetailsChange={setEventDetails} />
            <CartSection applications={applications.list} setCurrentApplication={setCurrentApplication} />
            <BillingForm user={user} onBillingChange={setBillingDetails} onSubmit={handleSubmit} />
            {currentApplication && (
                <ApplicationCrud onClose={() => setCurrentApplication(undefined)} applicationId={currentApplication.application_id} date_id={currentApplication.date_id}
                                 building_id={currentApplication.building_id} />
            )}
        </div>
    );
};

export default CheckoutContent;