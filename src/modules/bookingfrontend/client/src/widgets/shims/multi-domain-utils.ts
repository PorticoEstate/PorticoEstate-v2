// Shim for @/service/multi-domain-utils — used in widget builds only.
export function createDomainBuildingUrl(_domain: any, buildingId: number, _originalId?: number): string {
	return `/building/${buildingId}`;
}
