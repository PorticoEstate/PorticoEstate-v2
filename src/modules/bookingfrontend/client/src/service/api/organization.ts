import { useQuery, useMutation, QueryClient } from "@tanstack/react-query";
import { phpGWLink } from "@/service/util";
import { Group, Organization, ViewDelegate } from "../types/api/organization.types";
import { CreatingDelegate, UpdatingDelegate } from "@/components/organization/delegate/schemas";
import { CreatingGroup } from "@/components/organization/group/schemas";
import { FetchAuthOptions } from "./api-utils";

// Organization data source
export const fetchOrganization = async (orgId: number): Promise<Organization> => {
    const url = phpGWLink(['bookingfrontend', 'organizations', orgId]);
    
    const res = await fetch(url, FetchAuthOptions());
    const data = await res.json();
    if (!res.ok) {
        return data;
    }
    //TODO: Split requests for groups and delegaters
    return {
        ...data,
        contacts: JSON.parse(data.contacts),
        activity: JSON.parse(data.activity),
        show_in_portal: !!data.show_in_portal,
        buildings: JSON.parse(data.buildings)
    }
}
export const patchOrganizationDataRequest = async (orgId: number, data: any) => {
    const url = phpGWLink([
        'bookingfrontend',
        'organizations',
        orgId
    ]);
    const res = await fetch(url, {
        method: "PATCH",
        body: JSON.stringify(data),
        headers: {
            "Content-Type": "application/json",
        }
    });
    const json = await res.json();
    if (!res.ok) {
        throw new Error(json.error);
    }
    return json; 
}
export const useOrganizationData = (orgId: number, initialData?: Organization) => {
    return useQuery({
        queryKey: ['organization', orgId],
        retry: 2,
        initialData,
        queryFn: () => fetchOrganization(orgId),
    });
}
export const updateOrganization = (orgId: number, client: QueryClient) => {
    return useMutation({
        mutationFn: (data: any) => patchOrganizationDataRequest(orgId, data.organization),
        onSuccess: () => {
            client.invalidateQueries({ queryKey: ['organization', orgId] });
        }
    });
}


// Delegate data source
export const fetchDelegateData = async (orgId: number, delegateId: number): Promise<ViewDelegate> => {
    const url = phpGWLink([
        'bookingfrontend',
        'organizations',
        orgId,
        'delegate',
        delegateId
    ]);
    const res = await fetch(url, FetchAuthOptions());
    const body = await res.json();
    if (body.error) return body;
    return {
        ...body,
        organization: JSON.parse(body.organization)
    }
}
export const patchDelegateRequest = async (
    orgId: number, 
    delegateId: number, 
    data: UpdatingDelegate
) => {
    const url = phpGWLink([
        'bookingfrontend',
        'organizations',
        orgId,
        'delegate',
        delegateId
    ]);
    const res = await fetch(url, {
        method: "PATCH",
        body: JSON.stringify(data),
        headers: {
            "Content-Type": "application/json",
        }
    });
    
    if (!res.ok) {
        throw new Error();
    }
    return await res.json();
}
export const createDelegateRequest = async (orgId: number, data: CreatingDelegate) => {
    const url = phpGWLink([
        'bookingfrontend',
        'organizations',
        orgId,
        'delegate'
    ]);
    const res = await fetch(url, {
        method: "POST",
        body: JSON.stringify(data),
        headers: {
            "Content-Type": "application/json",
        }
    });
    
    if (!res.ok) {
        throw new Error();
    }
    return await res.json();
}
export const useDelegateList = (orgId: number) => {
    return useQuery({
        queryKey: ['delegates', orgId],
        queryFn: async () => {
            const url = phpGWLink([
                'bookingfrontend',
                'organizations',
                orgId,
                'delegates'
            ]);
            const res = await fetch(url, FetchAuthOptions());
            const body = await res.json();
            if (body.error) return body;
            return JSON.parse(body.data);
        }
    })
}
export const patchDelegate = (orgId: number, delegateId: number, client: QueryClient) => {
    return useMutation({
        mutationFn: (data: UpdatingDelegate) => patchDelegateRequest(orgId, delegateId, data),
        onSuccess: () => {
            client.invalidateQueries({ queryKey: ['delegate', delegateId] });
            client.invalidateQueries({ queryKey: ['delegates', orgId] });
        }
    })
}
export const createDelegate = (orgId: number, client: QueryClient) => {
    return useMutation({
        mutationFn: (data: CreatingDelegate) => createDelegateRequest(orgId, data),
        onSuccess: () => {
            client.invalidateQueries({ queryKey: ['delegates', orgId] });
        }
    });
}


// Group data source
export const fetchGroupData = async (orgId: number, groupId: number): Promise<Group> => {
    const url = phpGWLink([
        'bookingfrontend',
        'organizations',
        orgId,
        'group',
        groupId
    ]);
    const res = await fetch(url, FetchAuthOptions());
    const result = await res.json();
    if (!res.ok) return result;

    return JSON.parse(result.data);
}
export const createGroupRequest = async (orgId: number, data: CreatingGroup) => {
    const url = phpGWLink([
        'bookingfrontend',
        'organizations',
        orgId,
        'group'
    ]);
    const res = await fetch(url, {
        method: "POST",
        body: JSON.stringify(data),
        headers: {
            "Content-Type": "application/json",
        }
    });

    if (!res.ok) {
        throw new Error();
    }
    return await res.json();
}
export const patchGroupRequest = async (orgId: number, groupId: number, data: any) => {
    const url = phpGWLink([
        'bookingfrontend',
        'organizations',
        orgId,
        'group',
        groupId
    ]);
    const res = await fetch(url, {
        method: "PATCH",
        body: JSON.stringify(data),
        headers: {
            "Content-Type": "application/json",
        }
    });

    if (!res.ok) {
        throw new Error();
    }
    return await res.json();
}
export const deleteGroupLeaderRequest = async (orgId: number, groupId: number, leaderId: number) => {
    const url = phpGWLink([
        'bookingfrontend',
        'organizations',
        orgId,
        'group',
        groupId,
        'leader',
        leaderId
    ]);
    const res = await fetch(url, {
        method: "DELETE",
        headers: {
            "Content-Type": "application/json",
        }
    });
    if (!res.ok) {
        throw new Error();
    }
    return await res.json();
}
export const addGroupLeaderRequest = async (orgId: number, groupId: number, data: any) => {
    const url = phpGWLink([
        'bookingfrontend',
        'organizations',
        orgId,
        'group',
        groupId,
        'leader'
    ]);
    const res = await fetch(url, {
        method: "POST",
        body: JSON.stringify(data),
        headers: {
            "Content-Type": "application/json",
        }
    });
    if (!res.ok) {
        throw new Error();
    }
    return await res.json();
}
export const updateGroupLeaderRequest = async (orgId: number, groupId: number, data: any) => {
    const url = phpGWLink([
        'bookingfrontend',
        'organizations',
        orgId,
        'group',
        groupId,
        'leaders'
    ]);
    const res = await fetch(url, {
        method: "POST",
        body: JSON.stringify(data),
        headers: {
            "Content-Type": "application/json",
        }
    });
    if (!res.ok) {
        throw new Error();
    }
    return await res.json();
}
export const patchGroup = (orgId: number, groupId: number, client: QueryClient) => {
    return useMutation({
        mutationFn: async ({ added, removed, groupData }: any) => {
            const leaders = { added, removed };
            if (added.length !== 0 || removed.length !== 0) {
                await updateGroupLeaderRequest(orgId, groupId, leaders);
            }
            await patchGroupRequest(orgId, groupId, groupData);
            return true;
        },
        onSuccess: () => {
            client.invalidateQueries({ queryKey: ['group', groupId] });
        }
    })
}
export const useGroupData = (orgId: number, groupId: number, initialData: Group) => {
    return useQuery({
        queryKey: ['group', groupId],
        initialData,
        retry: 2,
        queryFn: () => fetchGroupData(orgId, groupId),
    })
}
export const useGroupList = (orgId: number) => {
    return useQuery({
        queryKey: ['groups', orgId],
        queryFn: async () => {
            const url = phpGWLink([
                'bookingfrontend',
                'organizations',
                orgId,
                'groups'
            ]);
            const res = await fetch(url, FetchAuthOptions());
            const body = await res.json();
            if (body.error) return body;
            return JSON.parse(body.data);
        }
    })
}
export const createGroup = (orgId: number, client: QueryClient) => {
    return useMutation({
        mutationFn: (data: CreatingGroup) => createGroupRequest(orgId, data),
        onSuccess: () => {
            client.invalidateQueries({ queryKey: ['organization', orgId] });
        }
    })
}