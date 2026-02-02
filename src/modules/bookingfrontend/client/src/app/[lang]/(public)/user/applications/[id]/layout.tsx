import { PropsWithChildren } from 'react';
import {notFound} from "next/navigation";
import {fetchApplication} from "@/service/api/api-utils";

interface ApplicationDetailLayoutProps extends PropsWithChildren {
  params: {
    id: string;
  }
}


export async function generateMetadata(props: ApplicationDetailLayoutProps) {
	// Convert the id to a number
	const applicationId = parseInt(props.params.id, 10);

	// Check if the buildingId is a valid number
	if (isNaN(applicationId)) {
		// If not a valid number, throw the notFound error
		return notFound();
	}

	try {
		// Try to fetch the application (this will work for authenticated users)
		const application = await fetchApplication(applicationId);
		
		return {
			title: application.name,
		};
	} catch (error) {
		// If fetch fails (e.g., for external secret access), return generic metadata
		// The client-side component will handle setting the correct title
		return {
			title: `Application ${applicationId}`,
		};
	}
}


export default async function ApplicationDetailLayout({ children, params }: ApplicationDetailLayoutProps) {
 	return children;
}