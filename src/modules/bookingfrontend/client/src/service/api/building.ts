import {phpGWLink} from "@/service/util";
import {useQuery} from "@tanstack/react-query";
import {IBuilding} from "@/service/types/Building";
import {IAPIQueryResponse, IDocument, IDocumentCategoryQuery} from "@/service/types/api.types";
import {IShortResource} from "@/service/pecalendar.types";


export async function fetchBuilding(building_id: number, instance?: string): Promise<IBuilding> {
    const url = phpGWLink(
        ["bookingfrontend", 'buildings', building_id],
        null,
        true,
        instance
    );
    const response = await fetch(url);
    if (!response.ok) {
        throw new Error('Failed to fetch building data');
    }
    const result = await response.json();
    return result;
}


export async function fetchResource(resource_id: number, instance?: string): Promise<IResource> {
    const url = phpGWLink(
        ["bookingfrontend", 'resources', resource_id],
        null,
        true,
        instance
    );

    const response = await fetch(url);
    if (!response.ok) {
        throw new Error('Failed to fetch building data');
    }
    const result = await response.json();
    return result;
}

/**
 *  React hook to fetch building data.
 * @param {number} building_id - The ID of the building to fetch.
 * @param {string} instance - Optional instance string for the request.
 * @returns {object} - Returns the query object from TanStack Query.
 */
export function useBuilding(building_id: number, instance?: string) {
    return useQuery<IBuilding>(
        {
            queryKey: ['building', building_id],
            queryFn: () => fetchBuilding(building_id, instance), // Fetch function
            enabled: !!building_id, // Only run the query if building_id is provided
            retry: 2, // Number of retry attempts if the query fails
            refetchOnWindowFocus: false, // Do not refetch on window focus by default
        }
    );
}


export async function fetchBuildingResources(building_id: number, instance?: string): Promise<IShortResource[]> {
    const url = phpGWLink(
        ["bookingfrontend", 'buildings', building_id, 'resources'],
        {short: 1, results: -1},
        true,
        instance
    );

    const response = await fetch(url);
    if (!response.ok) {
        throw new Error('Failed to fetch building data');
    }
    const result: IAPIQueryResponse<IShortResource> = await response.json();
    return result.results;
}




export async function fetchBuildingDocuments(buildingId: number | string, type_filter?: IDocumentCategoryQuery | IDocumentCategoryQuery[]): Promise<IDocument[]> {
    const url = phpGWLink(["bookingfrontend", 'buildings', buildingId, 'documents'],
    type_filter && {type: Array.isArray(type_filter) ? type_filter.join(',') : type_filter});

    const response = await fetch(url);
    const result = await response.json();
    return result;
}


export async function fetchResourceDocuments(buildingId: number | string, type_filter?: IDocumentCategoryQuery | IDocumentCategoryQuery[]): Promise<IDocument[]> {
    const url = phpGWLink(["bookingfrontend", 'resources', buildingId, 'documents'],
    type_filter && {type: Array.isArray(type_filter) ? type_filter.join(',') : type_filter});

    const response = await fetch(url);
    const result = await response.json();
    return result;
}
export function getDocumentLink(doc: IDocument, type: 'building' | 'resource'): string {
    const url = phpGWLink(['bookingfrontend',type+'s', 'document', doc.id, 'download']);
    return url
}
