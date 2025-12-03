import {notFound} from "next/navigation";
import {fetchBuilding, fetchResource, fetchBuildingDocuments, fetchResourceDocuments} from "@/service/api/building";
import DescriptionAccordion from "@/components/building-page/description-accordion";
import ResourceHeader from "@/components/resource-page/resource-header";
import TextAccordion from "@/components/building-page/text-accordion";
import {getTranslation} from "@/app/i18n";
import BuildingCalendar from "@/components/building-calendar";
import BuildingPhotos from "@/components/building-page/building-photos/building-photos";
import DocumentsSection from "@/components/shared/documents-section/documents-section";
import {fetchTowns} from "@/service/api/api-utils";
import ResourceSubscriptionTest from "@/components/resource-page/resource-subscription-test";
import {IDocumentCategoryQuery} from "@/service/types/api.types";

interface ResourceParams {
    id: string;
}

interface ResourceProps {
    params: ResourceParams;
    initialDate?: string;
}



const Resource = async (props: ResourceProps) => {
    // Convert the id to a number
    const resourceId = parseInt(props.params.id, 10);

    // Check if the buildingId is a valid number
    if (isNaN(resourceId)) {
        // If not a valid number, throw the notFound error
        return notFound();
    }

    // Fetch the building
    const resource = await fetchResource(resourceId);

	// console.log(resourceId);
    // If building does not exist, throw the notFound error
    if (!resource || resource.building_id === null || resource.building_id === undefined) {
        return notFound();
    }
    const building = await fetchBuilding(resource.building_id);

    // Fetch towns data to get the town for this building
    const towns = await fetchTowns();
    const town = towns.find(t => t.id === building.town_id);

    // Fetch documents from both resource and building (excluding only pictures)
    const documentTypes:  IDocumentCategoryQuery[] = ['drawing', 'price_list', 'other', 'regulation', 'HMS_document'];
    const [resourceDocs, buildingDocs] = await Promise.all([
        fetchResourceDocuments(resourceId, documentTypes),
        fetchBuildingDocuments(resource.building_id, documentTypes)
    ]);

    // Combine and deduplicate documents by ID
    const allDocs = [...resourceDocs, ...buildingDocs];
    const uniqueDocuments = Array.from(
        new Map(allDocs.map(doc => [doc.id, doc])).values()
    );

    const {t} = await getTranslation();
    return (
        <main>
            <ResourceHeader building={building} resource={resource} town={town} />
			<BuildingPhotos object={resource} type={'resource'} />

            <section className={'my-2'}>
                <DescriptionAccordion description_json={resource.description_json}/>
				<TextAccordion text={resource.opening_hours} title={t('booking.opening hours')}/>
                <TextAccordion text={resource.contact_info} title={t('bookingfrontend.contact information')}/>
                <DocumentsSection documents={uniqueDocuments} type="resource" />

                {/* Test component for resource ID 482 */}
                {/*{resourceId === 482 && (*/}
                {/*    <ResourceSubscriptionTest resourceId={resourceId} />*/}
                {/*)}*/}
            </section>
                <BuildingCalendar building_id={`${building.id}`} resource_id={`${resourceId}`} initialDate={props.initialDate}/>
                {/*<BuildingContact building={building}/>*/}
        </main>
);
}

export default Resource


