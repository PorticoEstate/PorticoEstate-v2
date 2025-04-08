import {fetchBuilding, fetchResource} from "@/service/api/building";
import {notFound} from "next/navigation";

interface ResourceLayoutParams {
    id: string;
}

interface ResourceLayoutProps {
    params: ResourceLayoutParams;
    children: React.ReactNode;
}

export async function generateMetadata(props: ResourceLayoutProps) {
    // Convert the id to a number
    const resourceId = parseInt(props.params.id, 10);

    // Check if the resourceId is a valid number
    if (isNaN(resourceId)) {
        // If not a valid number, throw the notFound error
        return notFound();
    }

    // Fetch the resource
    const resource = await fetchResource(resourceId);

    // If resource does not exist or has no building, throw the notFound error
    if (!resource || resource.building_id === null || resource.building_id === undefined) {
        return notFound();
    }
    
    const building = await fetchBuilding(resource.building_id);

    return {
        title: `${resource.name} - ${building.name}`,
    };
}

export default function ResourceLayout({ children }: ResourceLayoutProps) {
    return children;
}