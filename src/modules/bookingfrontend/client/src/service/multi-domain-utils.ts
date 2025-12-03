import { IMultiDomain } from "@/service/types/api.types";
import {createExternalDomainUrl} from "@/service/api/api-utils-static";


/**
 * Extracts the base URL from a webservicehost
 * Handles various formats like:
 * - https://stavanger.aktiv-kommune.no/bookingfrontend/
 * - https://stavanger.aktiv-kommune.no
 * - https://stavanger.aktiv-kommune.no/bookingfrontend/searchdata
 * - stavanger.aktiv-kommune.no
 */
export function extractBaseUrl(webservicehost: string): string {
	if (!webservicehost) return '';

	let url = webservicehost.trim();

	// Add protocol if missing
	if (!url.startsWith('http://') && !url.startsWith('https://')) {
		url = `https://${url}`;
	}

	try {
		const urlObj = new URL(url);
		return `${urlObj.protocol}//${urlObj.host}`;
	} catch (error) {
		console.error('Failed to parse webservicehost URL:', webservicehost, error);
		return webservicehost + '/';
	}
}


/**
 * Creates a redirect URL for a resource in another domain
 * Uses original_id if available, otherwise falls back to the provided ID
 */
export function createDomainResourceUrl(domain: IMultiDomain, resourceId: number, originalId?: number): string {
    const targetId = originalId || resourceId;
    return createExternalDomainUrl(domain, ['bookingfrontend', 'client', 'resource', targetId], null, false);
}

/**
 * Creates a redirect URL for a building in another domain
 * Uses original_id if available, otherwise falls back to the provided ID
 */
export function createDomainBuildingUrl(domain: IMultiDomain, buildingId: number, originalId?: number): string {
    const targetId = originalId || buildingId;
	return createExternalDomainUrl(domain, ['bookingfrontend', 'client', 'building', targetId], null, false);
}

/**
 * Redirects to another domain's instance
 */
export function redirectToDomain(url: string): void {
    window.location.href = url;
}