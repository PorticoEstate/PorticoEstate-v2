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

	// Fetch the application
	const application = await fetchApplication(applicationId);

	// If application does not exist, throw the notFound error
	if (!application) {
		return notFound();
	}
	return {
		title: application.name,
	};
}


export default async function ApplicationDetailLayout({ children, params }: ApplicationDetailLayoutProps) {
 	return children;
}