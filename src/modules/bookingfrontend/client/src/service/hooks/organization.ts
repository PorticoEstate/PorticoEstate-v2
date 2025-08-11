import {useQuery, UseQueryResult, useMutation, useQueryClient, UseMutationResult} from "@tanstack/react-query";
import {
    fetchMyOrganizations, 
    fetchOrganization, 
    fetchOrganizationGroups,
    fetchOrganizationGroup,
    fetchOrganizationBuildings, 
    fetchOrganizationDelegates,
    createOrganizationGroup,
    updateOrganizationGroup,
    toggleOrganizationGroupActive,
    addOrganizationDelegate,
    updateOrganizationDelegate,
    deleteOrganizationDelegate
} from "@/service/api/api-utils";
import {IOrganization, IShortOrganizationGroup, IShortOrganizationDelegate} from "@/service/types/api/organization.types";
import {useBookingUser} from "./api-hooks";
import {IBuilding} from "@/service/types/Building";

export function useMyOrganizations(): UseQueryResult<IOrganization[]> {
    return useQuery(
        {
            queryKey: ['myOrganizations'],
            queryFn: () => fetchMyOrganizations(), // Fetch function
            retry: 2, // Number of retry attempts if the query fails
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
        }
    );
}

export function useOrganization(id?: string | number): UseQueryResult<IOrganization> {
    const { data: user } = useBookingUser();

    return useQuery(
        {
            queryKey: ['organization', id, user?.id], // Include user ID to invalidate when auth changes
            queryFn: () => fetchOrganization(id!),
            retry: 2,
            refetchOnWindowFocus: false,
            enabled: !!id, // Only run query if id exists
        }
    );
}

export function useOrganizationGroups(id: string | number): UseQueryResult<IShortOrganizationGroup[]> {
    const { data: user } = useBookingUser();

    return useQuery(
        {
            queryKey: ['organizationGroups', id, user?.id],
            queryFn: () => fetchOrganizationGroups(id),
            retry: 2,
            refetchOnWindowFocus: false,
            enabled: !!id, // Only run query if id exists
        }
    );
}

export function useOrganizationGroup(organizationId: string | number, groupId: string | number): UseQueryResult<IShortOrganizationGroup> {
    const { data: user } = useBookingUser();

    return useQuery(
        {
            queryKey: ['organizationGroup', organizationId, groupId, user?.id],
            queryFn: () => fetchOrganizationGroup(organizationId, groupId),
            retry: 2,
            refetchOnWindowFocus: false,
            enabled: !!(organizationId && groupId), // Only run query if both IDs exist
        }
    );
}

export function useOrganizationBuildings(id: string | number): UseQueryResult<IBuilding[]> {
    const { data: user } = useBookingUser();

    return useQuery(
        {
            queryKey: ['organizationBuildings', id, user?.id],
            queryFn: () => fetchOrganizationBuildings(id),
            retry: 2,
            refetchOnWindowFocus: false,
            enabled: !!id, // Only run query if id exists
        }
    );
}

export function useOrganizationDelegates(id: string | number): UseQueryResult<IShortOrganizationDelegate[] | undefined> {
    const { data: user } = useBookingUser();

    return useQuery(
        {
            queryKey: ['organizationDelegates', id, user?.id],
            queryFn: () => fetchOrganizationDelegates(id),
            retry: 2,
            refetchOnWindowFocus: false,
            enabled: !!id && !!user, // Only run query if id exists and user is logged in
        }
    );
}

// Organization Group Mutations

export function useCreateOrganizationGroup(organizationId: string | number): UseMutationResult<
    {id: number; message: string},
    Error,
    {
        name: string;
        shortname?: string;
        description?: string;
        parent_id?: number;
        activity_id?: number;
        show_in_portal?: boolean;
        contacts?: Array<{
            name: string;
            email?: string;
            phone?: string;
        }>;
    }
> {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (data) => createOrganizationGroup(organizationId, data),
        onSuccess: () => {
            // Invalidate and refetch organization groups
            queryClient.invalidateQueries({ queryKey: ['organizationGroups', organizationId] });
            // Also invalidate organization data which might include group counts
            queryClient.invalidateQueries({ queryKey: ['organization', organizationId] });
        },
    });
}

export function useUpdateOrganizationGroup(organizationId: string | number): UseMutationResult<
    {message: string},
    Error,
    {
        groupId: number;
        data: {
            name?: string;
            shortname?: string;
            description?: string;
            parent_id?: number;
            activity_id?: number;
            show_in_portal?: boolean;
            active?: boolean;
            contacts?: Array<{
                id?: number;
                name: string;
                email?: string;
                phone?: string;
            }>;
        };
    }
> {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ groupId, data }) => updateOrganizationGroup(organizationId, groupId, data),
        onSuccess: () => {
            // Invalidate and refetch organization groups
            queryClient.invalidateQueries({ queryKey: ['organizationGroups', organizationId] });
            // Also invalidate organization data
            queryClient.invalidateQueries({ queryKey: ['organization', organizationId] });
        },
    });
}

export function useToggleOrganizationGroupActive(organizationId: string | number): UseMutationResult<
    {message: string},
    Error,
    {groupId: number; active: boolean}
> {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({groupId, active}) => toggleOrganizationGroupActive(organizationId, groupId, active),
        onSuccess: () => {
            // Invalidate and refetch organization groups
            queryClient.invalidateQueries({ queryKey: ['organizationGroups', organizationId] });
            // Also invalidate organization data
            queryClient.invalidateQueries({ queryKey: ['organization', organizationId] });
        },
    });
}

// Organization Delegate Mutations

export function useAddOrganizationDelegate(organizationId: string | number): UseMutationResult<
    {message: string},
    Error,
    {
        ssn: string;
        name?: string;
        email?: string;
        phone?: string;
        active?: boolean;
    }
> {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (data) => addOrganizationDelegate(organizationId, data),
        onSuccess: () => {
            // Invalidate and refetch organization delegates
            queryClient.invalidateQueries({ queryKey: ['organizationDelegates', organizationId] });
            // Also invalidate organization data which might include delegate counts
            queryClient.invalidateQueries({ queryKey: ['organization', organizationId] });
        },
    });
}

export function useUpdateOrganizationDelegate(organizationId: string | number): UseMutationResult<
    {message: string},
    Error,
    {
        delegateId: number;
        data: {
            name?: string;
            email?: string;
            phone?: string;
            active?: boolean;
        };
    }
> {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ delegateId, data }) => updateOrganizationDelegate(organizationId, delegateId, data),
        onSuccess: () => {
            // Invalidate and refetch organization delegates
            queryClient.invalidateQueries({ queryKey: ['organizationDelegates', organizationId] });
            // Also invalidate organization data
            queryClient.invalidateQueries({ queryKey: ['organization', organizationId] });
        },
    });
}

export function useDeleteOrganizationDelegate(organizationId: string | number): UseMutationResult<
    void,
    Error,
    number
> {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (delegateId: number) => deleteOrganizationDelegate(organizationId, delegateId),
        onSuccess: () => {
            // Invalidate and refetch organization delegates
            queryClient.invalidateQueries({ queryKey: ['organizationDelegates', organizationId] });
            // Also invalidate organization data
            queryClient.invalidateQueries({ queryKey: ['organization', organizationId] });
        },
    });
}