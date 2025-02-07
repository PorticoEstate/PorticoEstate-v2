import {useQuery, UseQueryResult} from "@tanstack/react-query";
import {fetchMyOrganizations} from "@/service/api/api-utils";
import {IOrganization} from "@/service/types/api/organization.types";

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