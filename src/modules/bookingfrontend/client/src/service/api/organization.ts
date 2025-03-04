import { useQuery, useMutation } from "@tanstack/react-query";
import { phpGWLink } from "@/service/util";
import { Organization, Group, Delegate } from "../types/api/organization.types";
import { CreatingDelegate } from "@/components/organization/delegate/schemas";
import { CreatingGroup } from "@/components/organization/group/schemas";

export const useOrganizationData = (orgId: number) => {
    return useQuery({
        queryKey: ['organization', orgId],
        retry: 2,
        queryFn: async (): Promise<Organization> => {
            const url = phpGWLink(['bookingfrontend', 'organization', orgId]);
            const res = await fetch(url);
            const data = await res.json();
            return {
                ...data,
                groups: JSON.parse(data.groups),
                delegaters: JSON.parse(data.delegaters)
            }
        }
    });
}

export const useDelegateData = (delegateId: number) => {
    return useQuery({
        queryKey: ['delegate', delegateId],
        retry: 2,
        queryFn: async (): Promise<Delegate> => {
            const url = phpGWLink([
                'bookingfrontend', 
                'organization',
                'delegate', 
                delegateId
            ]);
            const res = await fetch(url);
            return await res.json();
        }
    })
}

export const useGroupData = (groupId: number) => {
    return useQuery({
        queryKey: ['group', groupId],
        retry: 2,
        queryFn: async (): Promise<Group> => {
            const url = phpGWLink([
                'bookingfrontend',
                'organization',
                'group',
                groupId
            ]);
            const res = await fetch(url);
            return await res.json();
        }
    })
}

export const createDelegate = (orgId: number) => {
    return useMutation({
        mutationFn: async (data: CreatingDelegate) => {
            const url = phpGWLink([
                'bookingfrontend', 
                'organization', 
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
    });
}

export const createGroup = (orgId: number) => {
    return useMutation({
        mutationFn: async (data: CreatingGroup) => {
            const url = phpGWLink([
                'bookingfrontend', 
                'organization', 
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
    })
}