import {useMutation, useQuery, useQueryClient} from "@tanstack/react-query";
import {useClientTranslation} from "@/app/i18n/ClientTranslationProvider";
import {phpGWLink} from "@/service/util";
import {IApplication} from "@/service/types/api/application.types";
import {
    initiateVippsPayment, 
    VippsPaymentData, 
    VippsPaymentResponse, 
    fetchExternalPaymentEligibility,
    checkVippsPaymentStatus,
    getVippsPaymentDetails,
    cancelVippsPayment,
    refundVippsPayment,
    VippsPaymentStatusResponse,
    VippsPaymentDetailsResponse,
    VippsCancelPaymentResponse,
    VippsRefundPaymentResponse
} from "@/service/api/api-utils";

export interface CheckoutFormData {
    // Event Details
    organizerName: string;

    // Customer Type
    customerType: 'ssn' | 'organization_number';

    // Organization Details (optional based on customerType)
    organizationId?: number;
    organizationNumber?: string;
    organizationName?: string;

    // Contact Information
    contactName: string;
    contactEmail: string;
    contactPhone: string;

    // Address Information
    street: string;
    zipCode: string;
    city: string;

    // Building-specific parent application IDs
    building_parent_ids?: Record<number, number>;
    
    // Documents consent
    documentsRead: boolean;

    // Applicant's UI language at submit. OPTIONAL and never defaulted -- the column is
    // nullable and NULL means "not known". Declared so the compiler checks the field name.
    language?: string;
}

export interface CheckoutResponse {
    message: string;
    applications: Array<IApplication>;
}


export function useCheckoutApplications() {
    const queryClient = useQueryClient();
    // The applicant's language at submit. DECLARED on CheckoutFormData (optional) so the
    // compiler checks the field name, but SET here rather than by the caller: it is ambient
    // client context, not a form field the citizen filled in, and no caller should have to
    // remember it. Optional-on-the-type + set-in-the-hook gives both properties.
    //
    // ⚠️ CAPTURED AT SUBMIT ON PURPOSE, and it is the only moment it exists. The session
    // language is not stored on the application today, so every application created without
    // it has an applicant language that is PERMANENTLY UNRECOVERABLE -- reading it back later
    // would give the CASEWORKER's language, which is the defect this is groundwork for.
    //
    // 🔴 NO `|| 'no'` FALLBACK HERE, DELIBERATELY -- and do not "restore" it to match the
    // house idiom. `i18n.language || 'no'` IS correct at every other call site, because those
    // RENDER something and must render a language. This value is STORED.
    //
    // The column is nullable, so NULL carries meaning: "we do not know this applicant's
    // language". A fallback converts that unknown into a CLAIM of Norwegian, and once stored
    // the two are indistinguishable forever -- nobody can later tell a Bokmål user from a
    // user whose language we failed to capture.
    //
    // ⇒ a DISPLAY default and a STORED default are the same expression with opposite
    //   consequences. If `i18n.language` is falsy the key is simply absent from the JSON
    //   body (JSON.stringify drops undefined), the server writes nothing, and the column
    //   stays NULL -- which is the honest answer.
    const {i18n} = useClientTranslation();

    return useMutation({
        mutationFn: async (checkoutData: CheckoutFormData) => {
            const url = phpGWLink(['bookingfrontend', 'applications', 'partials', 'checkout']);
            // Typed deliberately: a bare literal inside JSON.stringify() is checked against
            // `any`, so a misspelled key would ship silently. The annotation is the only
            // thing making the field name compiler-verified on this path.
            const payload: CheckoutFormData = {...checkoutData, language: i18n.language};
            const response = await fetch(url, {
                method: 'POST',
                body: JSON.stringify(payload),
                headers: {
                    'Content-Type': 'application/json',
                },
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error || errorData.errors?.join(', ') || 'Checkout failed');
            }

            return response.json() as Promise<CheckoutResponse>;
        },
        onMutate: async () => {
            // Cancel any outgoing refetches
            await queryClient.cancelQueries({queryKey: ['partialApplications']});

            // Snapshot current applications
            const previousApplications = queryClient.getQueryData<{
                list: IApplication[],
                total_sum: number
            }>(['partialApplications']);

            // Optimistically clear the applications list
            if (previousApplications) {
                queryClient.setQueryData(['partialApplications'], {
                    list: [],
                    total_sum: 0
                });
            }

            return { previousApplications };
        },
        onError: (err, variables, context) => {
            // Rollback to previous state on error
            if (context?.previousApplications) {
                queryClient.setQueryData(['partialApplications'], context.previousApplications);
            }
        },
        onSuccess: (data) => {
            // Clear cart query cache on success
            queryClient.setQueryData(['partialApplications'], {
                list: [],
                total_sum: 0
            });

            // Could also update other related queries if needed
            // queryClient.invalidateQueries(['applications']);
        },
        onSettled: () => {
            // Always refetch to ensure data is correct
            queryClient.invalidateQueries({queryKey: ['partialApplications']});
        },
    });
}

export function useVippsPayment() {
    // 🔴 SECOND SUBMIT PATH -- keep in step with useCheckoutApplications above.
    // Vipps is an ALTERNATIVE to the checkout POST, not a step after it:
    // checkout-content.tsx `handleVippsPayment` builds the same billing payload and calls
    // this mutation WITHOUT calling checkoutMutation, from its own button (:434).
    // So a citizen who pays by Vipps never traverses the other hook, and a field attached
    // only there is invisible to them.
    //
    // Same no-fallback rule as above: the column is nullable, NULL means "not known", and a
    // default here would manufacture a language claim for every Vipps applicant.
    const {i18n} = useClientTranslation();

    return useMutation({
        mutationFn: async (paymentData: VippsPaymentData) => {
            return await initiateVippsPayment({...paymentData, language: i18n.language});
        },
        onSuccess: (data: VippsPaymentResponse) => {
            if (data.success && data.redirect_url) {
                // Redirect to Vipps payment page
                window.location.href = data.redirect_url;
            }
        },
        onError: (error: Error) => {
            console.error('Vipps payment error:', error);
            // Error handling will be done by the component
        }
    });
}

export function useExternalPaymentEligibility() {
    const queryClient = useQueryClient();
    const partialApplicationsData = queryClient.getQueryData<{ list: IApplication[], total_sum: number }>(['partialApplications']);
    
    return useQuery({
        queryKey: ['externalPaymentEligibility', partialApplicationsData?.list?.length, partialApplicationsData?.total_sum],
        queryFn: fetchExternalPaymentEligibility,
        retry: false,
        refetchOnWindowFocus: false,
        // Only fetch when we have partial applications
        enabled: !!partialApplicationsData?.list?.length
    });
}

/**
 * Hook for checking Vipps payment status
 * This can be used for polling payment status or checking after returning from Vipps
 */
export function useVippsPaymentStatus() {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: async (payment_order_id: string) => {
            return await checkVippsPaymentStatus(payment_order_id);
        },
        onSuccess: (data: VippsPaymentStatusResponse) => {
            // If payment was completed successfully, invalidate relevant queries
            if (data.status === 'completed' && data.applications_approved) {
                queryClient.invalidateQueries({queryKey: ['partialApplications']});
                queryClient.invalidateQueries({queryKey: ['applications']});
            }
        },
        onError: (error: Error) => {
            console.error('Vipps payment status check error:', error);
        }
    });
}

/**
 * Hook for getting detailed Vipps payment information
 */
export function useVippsPaymentDetails(payment_order_id: string | null, enabled: boolean = true) {
    return useQuery({
        queryKey: ['vippsPaymentDetails', payment_order_id],
        queryFn: () => getVippsPaymentDetails(payment_order_id!),
        enabled: !!payment_order_id && enabled,
        retry: false,
        refetchOnWindowFocus: false,
    });
}

/**
 * Hook for cancelling Vipps payments
 */
export function useVippsCancelPayment() {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: async (payment_order_id: string) => {
            return await cancelVippsPayment(payment_order_id);
        },
        onSuccess: (data: VippsCancelPaymentResponse) => {
            if (data.success) {
                // Refresh applications since cancelled payments might affect partial applications
                queryClient.invalidateQueries({queryKey: ['partialApplications']});
            }
        },
        onError: (error: Error) => {
            console.error('Vipps payment cancellation error:', error);
        }
    });
}

/**
 * Hook for refunding Vipps payments
 */
export function useVippsRefundPayment() {
    return useMutation({
        mutationFn: async ({ payment_order_id, amount }: { payment_order_id: string, amount: number }) => {
            return await refundVippsPayment(payment_order_id, amount);
        },
        onSuccess: (data: VippsRefundPaymentResponse) => {
            console.log('Vipps refund successful:', data);
        },
        onError: (error: Error) => {
            console.error('Vipps refund error:', error);
        }
    });
}