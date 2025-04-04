import {useMutation, useQueryClient} from "@tanstack/react-query";
import {phpGWLink} from "@/service/util";
import {IApplication} from "@/service/types/api/application.types";

export interface CheckoutFormData {
    // Event Details
    eventTitle: string;
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

    // Optional parent application ID
    parent_id?: number;
    
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