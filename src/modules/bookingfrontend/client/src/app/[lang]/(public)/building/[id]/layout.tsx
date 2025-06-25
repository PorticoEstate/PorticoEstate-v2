import {fetchBuilding} from "@/service/api/building";
import {notFound} from "next/navigation";

interface BuildingLayoutParams {
    id: string;
}

interface BuildingLayoutProps {
    params: Promise<BuildingLayoutParams>;
    children: React.ReactNode;
}

export async function generateMetadata(props: BuildingLayoutProps) {
    // Await params in Next.js 15+
    const params = await props.params;
    // Convert the id to a number
    const buildingId = parseInt(params.id, 10);

    // Check if the buildingId is a valid number
    if (isNaN(buildingId)) {
        // If not a valid number, throw the notFound error
        return notFound();
    }

    // Fetch the building
    const building = await fetchBuilding(buildingId);

    // If building does not exist, throw the notFound error
    if (!building) {
        return notFound();
    }
    return {
        title: building.name,
    };
}

export default async function BuildingLayout({ children, params }: BuildingLayoutProps) {
    // Await params in Next.js 15+
    await params;
    return children;
}
