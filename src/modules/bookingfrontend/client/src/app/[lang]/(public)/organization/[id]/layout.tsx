/**
 * Organization [id] Layout Component
 *
 * Fetches organization data and provides it to all child pages
 */

import {ReactNode} from "react";
import {notFound} from "next/navigation";
import {fetchOrganization} from "@/service/api/api-utils";

interface OrganizationIdLayoutProps {
  children: ReactNode
  params: { id: string; lang: string }
}

export async function generateMetadata(props: OrganizationIdLayoutProps) {
	// Convert the id to a number
	const organizationId = parseInt(props.params.id, 10);

	// Check if the buildingId is a valid number
	if (isNaN(organizationId)) {
		// If not a valid number, throw the notFound error
		return notFound();
	}

	// Fetch the building
	const building = await fetchOrganization(organizationId);

	// If building does not exist, throw the notFound error
	if (!building) {
		return notFound();
	}
	return {
		title: building.name,
	};
}

export default async function OrganizationIdLayout({ children }: OrganizationIdLayoutProps) {
	return children;
}
