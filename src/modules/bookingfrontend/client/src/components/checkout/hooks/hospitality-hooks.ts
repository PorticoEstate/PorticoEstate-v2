import {useMutation, useQueries, useQuery, useQueryClient} from "@tanstack/react-query";
import {phpGWLink} from "@/service/util";
import {
    IHospitality,
    IHospitalityMenu,
    IHospitalityOrder,
    CreateHospitalityOrderRequest,
    UpdateHospitalityOrderRequest,
} from "@/service/types/api/hospitality.types";
import {CateringState} from "@/utils/article-pricing";
import {cartCateringState} from "@/utils/checkout-total";

/**
 * The server refused a change to an order or its cancellation. 409 is the deadline guard (a deadline passed, the
 * order is locked or the application is closed); the controller uses 409 for nothing else, so the status alone says
 * «too late».
 */
export class HospitalityOrderRefusal extends Error {
    constructor(message: string, readonly status: number) {
        super(message);
        this.name = 'HospitalityOrderRefusal';
    }
}

export function useAvailableHospitalities(applicationId: number | undefined) {
    return useQuery<IHospitality[]>({
        queryKey: ['availableHospitalities', applicationId],
        queryFn: async () => {
            const url = phpGWLink(['bookingfrontend', 'applications', applicationId!, 'hospitalities']);
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error('Failed to fetch hospitalities');
            }
            return response.json();
        },
        enabled: !!applicationId,
        retry: false,
        refetchOnWindowFocus: false,
    });
}

export function useHospitalityMenu(hospitalityId: number | undefined) {
    return useQuery<IHospitalityMenu>({
        queryKey: ['hospitalityMenu', hospitalityId],
        queryFn: async () => {
            const url = phpGWLink(['bookingfrontend', 'hospitality', hospitalityId!, 'menu']);
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error('Failed to fetch menu');
            }
            return response.json();
        },
        enabled: !!hospitalityId,
        retry: false,
        refetchOnWindowFocus: false,
    });
}

/**
 * The ONE description of an application's catering-order read. useHospitalityOrders and useCartCateringOrders both
 * use it, so they share a cache entry: the checkout's total reads the very orders the catering section lists, and
 * no extra request is made for it.
 */
const hospitalityOrdersQuery = (applicationId: number | undefined) => ({
    queryKey: ['hospitalityOrders', applicationId],
    queryFn: async (): Promise<IHospitalityOrder[]> => {
        const url = phpGWLink(['bookingfrontend', 'applications', applicationId!, 'hospitality-orders']);
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error('Failed to fetch hospitality orders');
        }
        return response.json();
    },
    enabled: !!applicationId,
    retry: false,
    refetchOnWindowFocus: false,
});

export function useHospitalityOrders(applicationId: number | undefined) {
    return useQuery<IHospitalityOrder[]>(hospitalityOrdersQuery(applicationId));
}

/**
 * Every catering order of the cart's applications, for the checkout's totals (GH #1478). With `enabled` false
 * (catering switched off) nothing is read and the answer is «read, none»: the caller decides whether catering
 * being off is known yet.
 */
export function useCartCateringOrders(
    applicationIds: number[],
    enabled: boolean
): {orders: IHospitalityOrder[]; state: CateringState} {
    return useQueries({
        queries: enabled ? applicationIds.map(id => hospitalityOrdersQuery(id)) : [],
        combine: (results): {orders: IHospitalityOrder[]; state: CateringState} => ({
            orders: results.flatMap(result => result.data ?? []),
            state: cartCateringState(results),
        }),
    });
}

export function useCreateHospitalityOrder(applicationId: number | undefined) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: CreateHospitalityOrderRequest) => {
            if (!applicationId) {
                throw new Error('Cannot create a hospitality order without an application');
            }
            const url = phpGWLink(['bookingfrontend', 'applications', applicationId, 'hospitality-orders']);
            const response = await fetch(url, {
                method: 'POST',
                body: JSON.stringify(data),
                headers: {'Content-Type': 'application/json'},
            });
            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error || 'Failed to create order');
            }
            return response.json() as Promise<IHospitalityOrder>;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: ['hospitalityOrders', applicationId]});
            queryClient.invalidateQueries({queryKey: ['externalPaymentEligibility']});
            queryClient.invalidateQueries({queryKey: ['partialApplications']});
        },
        // A refusal (e.g. a deadline that passed while the page was open) changes what the server
        // says is allowed: re-read the orders so the buttons follow it.
        onError: () => {
            queryClient.invalidateQueries({queryKey: ['hospitalityOrders', applicationId]});
        },
    });
}

export function useUpdateHospitalityOrder(applicationId: number | undefined) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({orderId, data}: { orderId: number; data: UpdateHospitalityOrderRequest }) => {
            if (!applicationId) {
                throw new Error('Cannot update a hospitality order without an application');
            }
            const url = phpGWLink(['bookingfrontend', 'applications', applicationId, 'hospitality-orders', orderId]);
            const response = await fetch(url, {
                method: 'PUT',
                body: JSON.stringify(data),
                headers: {'Content-Type': 'application/json'},
            });
            if (!response.ok) {
                const errorData = await response.json();
                throw new HospitalityOrderRefusal(errorData.error || 'Failed to update order', response.status);
            }
            return response.json() as Promise<IHospitalityOrder>;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: ['hospitalityOrders', applicationId]});
            queryClient.invalidateQueries({queryKey: ['externalPaymentEligibility']});
            queryClient.invalidateQueries({queryKey: ['partialApplications']});
        },
        // A refusal (e.g. a deadline that passed while the page was open) changes what the server
        // says is allowed: re-read the orders so the buttons follow it.
        onError: () => {
            queryClient.invalidateQueries({queryKey: ['hospitalityOrders', applicationId]});
        },
    });
}

export function useDeleteHospitalityOrder(applicationId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (orderId: number) => {
            const url = phpGWLink(['bookingfrontend', 'applications', applicationId, 'hospitality-orders', orderId]);
            const response = await fetch(url, {
                method: 'DELETE',
            });
            if (!response.ok) {
                const errorData = await response.json();
                throw new HospitalityOrderRefusal(errorData.error || 'Failed to delete order', response.status);
            }
            return response.json();
        },
        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: ['hospitalityOrders', applicationId]});
            queryClient.invalidateQueries({queryKey: ['externalPaymentEligibility']});
            queryClient.invalidateQueries({queryKey: ['partialApplications']});
        },
        // A refusal (e.g. a deadline that passed while the page was open) changes what the server
        // says is allowed: re-read the orders so the buttons follow it.
        onError: () => {
            queryClient.invalidateQueries({queryKey: ['hospitalityOrders', applicationId]});
        },
    });
}

/**
 * Aggregate hook: fetches available hospitalities for multiple application IDs
 * and deduplicates by hospitality ID. Returns merged set + per-application orders.
 */
export function useApplicationGroupHospitalities(applicationIds: number[]) {
    const queries = applicationIds.map(id => {
        // eslint-disable-next-line react-hooks/rules-of-hooks
        return useAvailableHospitalities(id);
    });

    const orderQueries = applicationIds.map(id => {
        // eslint-disable-next-line react-hooks/rules-of-hooks
        return useHospitalityOrders(id);
    });

    const isLoading = queries.some(q => q.isLoading) || orderQueries.some(q => q.isLoading);

    // Deduplicate hospitalities by ID
    const hospitalityMap = new Map<number, IHospitality>();
    queries.forEach(q => {
        q.data?.forEach(h => {
            if (!hospitalityMap.has(h.id)) {
                hospitalityMap.set(h.id, h);
            }
        });
    });
    const hospitalities = Array.from(hospitalityMap.values());

    // Collect all orders across applications
    const allOrders: IHospitalityOrder[] = [];
    orderQueries.forEach(q => {
        if (q.data) {
            allOrders.push(...q.data);
        }
    });

    return {
        hospitalities,
        orders: allOrders,
        isLoading,
    };
}
