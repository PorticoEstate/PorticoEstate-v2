import {unstable_cache} from 'next/cache';
import {fetchBuilding, fetchResource, fetchBuildingResources, fetchBuildingDocuments, fetchResourceDocuments, fetchOrganizationDocuments} from './building';
import {IDocumentCategoryQuery} from '@/service/types/api.types';
import {IShortResource} from '@/service/pecalendar.types';
import {IResource} from '@/service/types/resource.types';

/**
 * SSR-cached version of fetchBuilding.
 * Uses unstable_cache with entity-specific tags for on-demand revalidation.
 *
 * Invalidate with: /api/cache-reset?tag=building-{id}
 */
export const fetchSSRBuilding = (buildingId: number, instance?: string) =>
    unstable_cache(
        async () => fetchBuilding(buildingId, instance),
        ['building', `${buildingId}`],
        {
            revalidate: 3600,
            tags: ['buildings', `building-${buildingId}`],
        }
    )();

/**
 * SSR-cached version of fetchResource.
 * Uses unstable_cache with entity-specific tags for on-demand revalidation.
 *
 * Invalidate with: /api/cache-reset?tag=resource-{id}
 */
export const fetchSSRResource = (resourceId: number, instance?: string) =>
    unstable_cache(
        async () => fetchResource(resourceId, instance),
        ['resource', `${resourceId}`],
        {
            revalidate: 3600,
            tags: ['resources', `resource-${resourceId}`],
        }
    )();

/**
 * SSR-cached version of fetchBuildingResources.
 *
 * Invalidate with: /api/cache-reset?tag=building-resources-{id}
 */
export const fetchSSRBuildingResources = <isShort extends boolean = false>(
    buildingId: number | string,
    short: isShort = false as isShort,
    instance?: string
) =>
    unstable_cache(
        async () => fetchBuildingResources(buildingId, short, instance),
        ['buildingResources', `${buildingId}`, `${short}`],
        {
            revalidate: 3600,
            tags: ['resources', `building-resources-${buildingId}`],
        }
    )() as Promise<(isShort extends true ? IShortResource : IResource)[]>;

/**
 * SSR-cached version of fetchBuildingDocuments.
 *
 * Invalidate with: /api/cache-reset?tag=building-documents-{id}
 */
export const fetchSSRBuildingDocuments = (
    buildingId: number | string,
    typeFilter?: IDocumentCategoryQuery | IDocumentCategoryQuery[]
) =>
    unstable_cache(
        async () => fetchBuildingDocuments(buildingId, typeFilter),
        ['buildingDocuments', `${buildingId}`, `${typeFilter}`],
        {
            revalidate: 3600,
            tags: ['buildings', `building-documents-${buildingId}`],
        }
    )();

/**
 * SSR-cached version of fetchResourceDocuments.
 *
 * Invalidate with: /api/cache-reset?tag=resource-documents-{id}
 */
export const fetchSSRResourceDocuments = (
    resourceId: number | string,
    typeFilter?: IDocumentCategoryQuery | IDocumentCategoryQuery[]
) =>
    unstable_cache(
        async () => fetchResourceDocuments(resourceId, typeFilter),
        ['resourceDocuments', `${resourceId}`, `${typeFilter}`],
        {
            revalidate: 3600,
            tags: ['resources', `resource-documents-${resourceId}`],
        }
    )();

/**
 * SSR-cached version of fetchOrganizationDocuments.
 *
 * Invalidate with: /api/cache-reset?tag=organization-documents-{id}
 */
export const fetchSSROrganizationDocuments = (
    organizationId: number | string,
    typeFilter?: IDocumentCategoryQuery | IDocumentCategoryQuery[]
) =>
    unstable_cache(
        async () => fetchOrganizationDocuments(organizationId, typeFilter),
        ['organizationDocuments', `${organizationId}`, `${typeFilter}`],
        {
            revalidate: 3600,
            tags: ['organizations', `organization-documents-${organizationId}`],
        }
    )();
