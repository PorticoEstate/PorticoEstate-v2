import {useMutation, useQuery, useQueryClient} from "@tanstack/react-query";
import {phpGWLink} from "@/service/util";
import {
    IHospitality,
    IHospitalityMenu,
    IHospitalityOrder,
    CreateHospitalityOrderRequest,
    UpdateHospitalityOrderRequest,
} from "@/service/types/api/hospitality.types";

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

export function useHospitalityOrders(applicationId: number | undefined) {
    return useQuery<IHospitalityOrder[]>({
        queryKey: ['hospitalityOrders', applicationId],
        queryFn: async () => {
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
}

export function useCreateHospitalityOrder(applicationId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: CreateHospitalityOrderRequest) => {
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
    });
}

export function useUpdateHospitalityOrder(applicationId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({orderId, data}: { orderId: number; data: UpdateHospitalityOrderRequest }) => {
            const url = phpGWLink(['bookingfrontend', 'applications', applicationId, 'hospitality-orders', orderId]);
            const response = await fetch(url, {
                method: 'PUT',
                body: JSON.stringify(data),
                headers: {'Content-Type': 'application/json'},
            });
            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error || 'Failed to update order');
            }
            return response.json() as Promise<IHospitalityOrder>;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: ['hospitalityOrders', applicationId]});
            queryClient.invalidateQueries({queryKey: ['externalPaymentEligibility']});
            queryClient.invalidateQueries({queryKey: ['partialApplications']});
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
                throw new Error(errorData.error || 'Failed to delete order');
            }
            return response.json();
        },
        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: ['hospitalityOrders', applicationId]});
            queryClient.invalidateQueries({queryKey: ['externalPaymentEligibility']});
            queryClient.invalidateQueries({queryKey: ['partialApplications']});
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

    // Map: applicationId -> hospitality IDs available for that application
    const applicationHospitalityMap = new Map<number, number[]>();
    queries.forEach((q, i) => {
        const appId = applicationIds[i];
        applicationHospitalityMap.set(appId, q.data?.map(h => h.id) || []);
    });

    return {
        hospitalities,
        orders: allOrders,
        isLoading,
        applicationHospitalityMap,
    };
}
