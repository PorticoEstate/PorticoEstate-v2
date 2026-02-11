import BuildingCalendar from "@/components/building-calendar";
import {fetchBuilding, fetchBuildingDocuments} from "@/service/api/building";
import {notFound} from "next/navigation";
import BuildingHeader from "@/components/building-page/building-header";
import DescriptionAccordion from "@/components/building-page/description-accordion";
import BuildingResources from "@/components/building-page/resource-list/building-resources";
import BuildingContact from "@/components/building-page/building-contact";
import BuildingPhotos from "@/components/building-page/building-photos/building-photos";
import DocumentsSection from "@/components/shared/documents-section/documents-section";
import {fetchTowns} from "@/service/api/api-utils";
import ShortDectionAccordion from "@/components/building-page/short-description-section";

interface BuildingShowParams {
    id: string;
}
interface BuildingShowProps {
    params: BuildingShowParams;
    initialDate?: string; // Add optional initialDate parameter
}

const BuildingShow = async (props: BuildingShowProps) => {
    // Convert the id to a number
    const buildingId = parseInt(props.params.id, 10);

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

    // Fetch towns data to get the town for this building
    const towns = await fetchTowns();
    const town = towns.find(t => t.id === building.town_id);

    // Fetch building documents (excluding only pictures)
    const documents = await fetchBuildingDocuments(buildingId, ['drawing', 'price_list', 'other', 'regulation', 'HMS_document']);

    return (
        <main>
            <BuildingHeader building={building} town={town} />
            {/*<hr className={`my-2 mx-standard`}/>*/}
			<ShortDectionAccordion short_description={building.short_description} />
			<BuildingPhotos object={building} type={'building'} />
			<section className={'my-2'}>
				{/* Photos moved above accordions */}
                <BuildingResources building={building}/>
                <DescriptionAccordion description_json={building.description_json}/>
                <DocumentsSection documents={documents} type="building" />
            </section>
            {/*<hr className={`my-2`}/>*/}
            <BuildingCalendar building_id={props.params.id} initialDate={props.initialDate}/>
            <BuildingContact building={building}/>
        </main>
    );
}

export default BuildingShow


