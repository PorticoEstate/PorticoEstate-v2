import {useQuery, UseQueryResult, useMutation} from "@tanstack/react-query";
import {phpGWLink} from "@/service/util";
import { Organization } from "../types/api/organization.types";
import { CreatingDelegate } from "@/components/organization/delegate/schemas";

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
        queryFn: async (): Promise<Organization> => {
            const url = phpGWLink([
                'bookingfrontend', 
                'organization',
                'delegate', 
                delegateId
            ]);
            const res = await fetch(url);
            const data = await res.json();
            return {
                ...data,
                groups: JSON.parse(data.groups),
                delegaters: JSON.parse(data.delegaters)
            }
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