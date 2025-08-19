'use client'
import React, {FC, useState, useMemo, useEffect} from 'react';
import CartSection from "./cart-section";
import {useBookingUser, usePartialApplications, useUpdatePartialApplication, useResourceRegulationDocuments} from "@/service/hooks/api-hooks";
import { CheckoutEventDetailsData, checkoutEventDetailsSchema } from './checkout-event-details-schema';
import { BillingFormData } from './billing-form-schema';
import CheckoutEventDetails from "@/components/checkout/checkout-event-details";
import BillingForm from "@/components/checkout/billing-form";
import styles from './checkout.module.scss';
import {Spinner} from "@digdir/designsystemet-react";
import ApplicationCrud from "@/components/building-calendar/modules/event/edit/application-crud";
import {useCheckoutApplications, useVippsPayment, useExternalPaymentEligibility} from "@/components/checkout/hooks/checkout-hooks";
import {useRouter} from "next/navigation";
import { useTrans } from '@/app/i18n/ClientTranslationProvider';
import RegulationDocuments from './regulation-documents';

const CheckoutContent: FC = () => {
	const t = useTrans();
    const {data: applications, isLoading: partialsLoading} = usePartialApplications();
    const {data: user, isLoading: userLoading} = useBookingUser();
    const updateMutation = useUpdatePartialApplication();
    const [eventDetails, setEventDetails] = useState<CheckoutEventDetailsData>();
    const checkoutMutation = useCheckoutApplications();
    const vippsPaymentMutation = useVippsPayment();
    const {data: paymentEligibility, isLoading: eligibilityLoading} = useExternalPaymentEligibility();
    const [billingDetails, setBillingDetails] = useState<BillingFormData>();
    const [selectedParentId, setSelectedParentId] = useState<number>();

    // Separate applications into regular and recurring
    const {regularApplications, recurringApplications} = useMemo(() => {
        const regular: any[] = [];
        const recurring: any[] = [];
        
        applications?.list?.forEach(app => {
            if (app.recurring_info && app.recurring_info.trim() !== '') {
                recurring.push(app);
            } else {
                regular.push(app);
            }
        });
        
        return { regularApplications: regular, recurringApplications: recurring };
    }, [applications?.list]);

    // Preselect the first regular (non-recurring) application as parent when applications load
    useEffect(() => {
        if (regularApplications.length > 0 && !selectedParentId) {
            setSelectedParentId(regularApplications[0].id);
        }
    }, [regularApplications, selectedParentId]);
    const [currentApplication, setCurrentApplication] = useState<{
        application_id: number,
        date_id: number,
        building_id: number
    }>();
    const router = useRouter();

    // Extract all resources from applications with their building IDs
    const resources = useMemo(() => {
        const allApps = [...regularApplications, ...recurringApplications];
        if (allApps.length === 0) return [];

        const resourceMap = new Map<number, { id: number, building_id?: number }>();

        allApps.forEach(app => {
            if (app.resources && Array.isArray(app.resources)) {
                app.resources.forEach(resource => {
                    if (resource.id) {
                        resourceMap.set(resource.id, {
                            id: resource.id,
                            building_id: resource.building_id ?? undefined
                        });
                    }
                });
            }
        });

        return Array.from(resourceMap.values());
    }, [regularApplications, recurringApplications]);

    // Fetch regulation documents for all resources
    const { data: regulationDocuments, isLoading: docsLoading } = useResourceRegulationDocuments(resources);

    // State to track individual document checkboxes
    const [checkedDocuments, setCheckedDocuments] = useState<Record<number, boolean>>({});

    // State to track if we should show document error
    const [showDocumentsError, setShowDocumentsError] = useState(false);

    // State to track if we should show organizer validation error
    const [showOrganizerError, setShowOrganizerError] = useState(false);

    // Reference for the documents section
    const documentsSectionRef = React.useRef<HTMLDivElement>(null);

    // Reference for the organizer field section
    const organizerSectionRef = React.useRef<HTMLDivElement>(null);

    // Custom handler for individual document consent changes
    const handleDocumentConsentChange = (documentId: number, checked: boolean) => {
        setCheckedDocuments(prev => ({
            ...prev,
            [documentId]: checked
        }));

        // If user is checking a document, clear the error state
        if (checked) {
            setShowDocumentsError(false);
        }
    };

    // Check if all documents are checked
    const areAllDocumentsChecked = useMemo(() => {
        if (!regulationDocuments || regulationDocuments.length === 0) return true;

        return regulationDocuments.every(doc => checkedDocuments[doc.id] === true);
    }, [regulationDocuments, checkedDocuments]);

    // Check if external payment should be available based on backend eligibility
    const shouldShowExternalPaymentOptions = useMemo(() => {
        return paymentEligibility?.eligible === true;
    }, [paymentEligibility]);

    // Update billing details when document consent status changes
    useEffect(() => {
        if (billingDetails && billingDetails.documentsRead !== areAllDocumentsChecked) {
            setBillingDetails(prev => {
                if (!prev) return prev;
                return {
                    ...prev,
                    documentsRead: areAllDocumentsChecked
                };
            });
        }
    }, [areAllDocumentsChecked]);

    const handleFormSubmit = async () => {
        if (!eventDetails || !applications || !billingDetails) {
            console.log('missing Data', eventDetails, billingDetails);
            return;
        }

        // Validate organizer field using the schema
        const organizerValidation = checkoutEventDetailsSchema.safeParse(eventDetails);
        if (!organizerValidation.success) {
            // Show error state
            setShowOrganizerError(true);

            // Scroll to organizer section
            if (organizerSectionRef.current) {
                organizerSectionRef.current.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }

            // Don't submit the form
            return;
        }

        // Clear organizer error if validation passes
        setShowOrganizerError(false);

        // Check if documents need to be confirmed
        if (regulationDocuments && regulationDocuments.length > 0 && !areAllDocumentsChecked) {
            // Show error state
            setShowDocumentsError(true);

            // Scroll to documents section
            if (documentsSectionRef.current) {
                documentsSectionRef.current.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }

            // Don't submit the form
            return;
        }

        try {
            checkoutMutation.mutateAsync({
                organizerName: eventDetails.organizerName,
                customerType: billingDetails?.customerType || 'ssn',
                organizationNumber: billingDetails.organizationNumber,
                organizationName: billingDetails.organizationName,
                contactName: billingDetails.contactName,
                contactEmail: billingDetails.contactEmail,
                contactPhone: billingDetails.contactPhone,
                street: billingDetails.street,
                zipCode: billingDetails.zipCode,
                city: billingDetails.city,
                documentsRead: billingDetails.documentsRead,
                parent_id: selectedParentId
            }).then(() => {
                router.push('/user/applications');
            })
        } catch (error) {
            console.error('Error updating applications:', error);
            // TODO: Handle error (show error message to user)
        }
    };

    const handleVippsPayment = async () => {
        console.log('=== VIPPS PAYMENT CLICKED ===');
        console.log('eventDetails:', eventDetails);
        console.log('applications:', applications);
        console.log('billingDetails:', billingDetails);

        if (!eventDetails || !applications || !billingDetails) {
            console.log('missing Data for Vipps payment', eventDetails, billingDetails);
            return;
        }

        // Validate organizer field using the schema
        const organizerValidation = checkoutEventDetailsSchema.safeParse(eventDetails);
        if (!organizerValidation.success) {
            // Show error state
            setShowOrganizerError(true);

            // Scroll to organizer section
            if (organizerSectionRef.current) {
                organizerSectionRef.current.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }

            // Don't proceed with payment
            return;
        }

        // Clear organizer error if validation passes
        setShowOrganizerError(false);

        // Check if documents need to be confirmed
        if (regulationDocuments && regulationDocuments.length > 0 && !areAllDocumentsChecked) {
            // Show error state
            setShowDocumentsError(true);

            // Scroll to documents section
            if (documentsSectionRef.current) {
                documentsSectionRef.current.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }

            // Don't proceed with payment
            return;
        }

        try {
            console.log('=== CALLING VIPPS API ===');
            const paymentData = {
                organizerName: eventDetails.organizerName,
                customerType: billingDetails?.customerType || 'ssn',
                organizationNumber: billingDetails.organizationNumber,
                organizationName: billingDetails.organizationName,
                contactName: billingDetails.contactName,
                contactEmail: billingDetails.contactEmail,
                contactPhone: billingDetails.contactPhone,
                street: billingDetails.street,
                zipCode: billingDetails.zipCode,
                city: billingDetails.city,
                documentsRead: billingDetails.documentsRead
            };
            console.log('Payment data:', paymentData);

            await vippsPaymentMutation.mutateAsync(paymentData);
            console.log('=== VIPPS API CALL COMPLETED ===');
        } catch (error) {
            console.error('Error initiating Vipps payment:', error);
            // TODO: Handle error (show error message to user)
        }
    };

    if(userLoading || partialsLoading || checkoutMutation.isPending || docsLoading || eligibilityLoading) {
        return <Spinner aria-label={t('bookingfrontend.loading_user_info')} />
    }

    if(!user || !applications || applications.list.length === 0) {
        router.push('/');
        return ''
    }

    return (
        <div className={styles.content}>
            <div ref={organizerSectionRef}>
                <CheckoutEventDetails 
                    user={user} 
                    partials={applications.list} 
                    onDetailsChange={setEventDetails}
                    showError={showOrganizerError}
                    onErrorClear={() => setShowOrganizerError(false)}
                />
            </div>
            <CartSection
                applications={applications.list}
                setCurrentApplication={setCurrentApplication}
                selectedParentId={selectedParentId}
                onParentIdChange={setSelectedParentId}
            />

            <BillingForm
                user={user}
                onBillingChange={setBillingDetails}
                onSubmit={handleFormSubmit}
                onVippsPayment={shouldShowExternalPaymentOptions ? handleVippsPayment : undefined}
                paymentEligibility={paymentEligibility}
                vippsLoading={vippsPaymentMutation.isPending}
                documentsValidated={!regulationDocuments?.length || areAllDocumentsChecked}
                documentsSectionRef={documentsSectionRef}
                showDocumentsSection={true}
                documents={regulationDocuments || []}
                checkedDocuments={checkedDocuments}
                onDocumentCheck={handleDocumentConsentChange}
                areAllDocumentsChecked={areAllDocumentsChecked}
                showDocumentsError={showDocumentsError}
                applications={applications?.list || []}
            />

            {currentApplication && (
                <ApplicationCrud
                    onClose={() => setCurrentApplication(undefined)}
                    applicationId={currentApplication.application_id}
                    date_id={currentApplication.date_id}
                    building_id={currentApplication.building_id}
                />
            )}
        </div>
    );
};

export default CheckoutContent;