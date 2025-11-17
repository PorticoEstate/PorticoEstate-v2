import {useMutation, useQuery, useQueryClient} from "@tanstack/react-query";
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
}

export interface CheckoutResponse {
    message: string;
    applications: Array<IApplication>;
}


export function useCheckoutApplications() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (checkoutData: CheckoutFormData) => {
            const url = phpGWLink(['bookingfrontend', 'applications', 'partials', 'checkout']);
            const response = await fetch(url, {
                method: 'POST',
                body: JSON.stringify(checkoutData),
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
    return useMutation({
        mutationFn: async (paymentData: VippsPaymentData) => {
            return await initiateVippsPayment(paymentData);
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