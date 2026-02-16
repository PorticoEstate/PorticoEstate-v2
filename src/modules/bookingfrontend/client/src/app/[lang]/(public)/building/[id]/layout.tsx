import {fetchSSRBuilding} from "@/service/api/building-ssr";
import {notFound} from "next/navigation";

interface BuildingLayoutParams {
    id: string;
}

interface BuildingLayoutProps {
    params: BuildingLayoutParams;
    children: React.ReactNode;
}

export async function generateMetadata(props: BuildingLayoutProps) {
    // Convert the id to a number
    const buildingId = parseInt(props.params.id, 10);

    // Check if the buildingId is a valid number
    if (isNaN(buildingId)) {
        // If not a valid number, throw the notFound error
        return notFound();
    }

    // Fetch the building
    const building = await fetchSSRBuilding(buildingId);

    // If building does not exist, throw the notFound error
    if (!building) {
        return notFound();
    }
    return {
        title: building.name,
    };
}

export default function BuildingLayout({ children }: BuildingLayoutProps) {
    return children;
}
