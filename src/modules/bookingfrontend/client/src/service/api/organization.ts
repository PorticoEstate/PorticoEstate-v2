import {useQuery, UseQueryResult} from "@tanstack/react-query";
import {phpGWLink} from "@/service/util";
import { Organization } from "../types/api/organization.types";

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
    })
}

export { Organization };
