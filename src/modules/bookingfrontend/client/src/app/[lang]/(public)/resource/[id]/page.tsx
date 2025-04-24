import {notFound} from "next/navigation";
import {fetchBuilding, fetchResource} from "@/service/api/building";
import DescriptionAccordion from "@/components/building-page/description-accordion";
import ResourceHeader from "@/components/resource-page/resource-header";
import TextAccordion from "@/components/building-page/text-accordion";
import {getTranslation} from "@/app/i18n";
import BuildingCalendar from "@/components/building-calendar";
import BuildingPhotos from "@/components/building-page/building-photos/building-photos";
import {fetchTowns} from "@/service/api/api-utils";

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


    // If building does not exist, throw the notFound error
    if (!resource || resource.building_id === null || resource.building_id === undefined) {
        return notFound();
    }
    const building = await fetchBuilding(resource.building_id);
    
    // Fetch towns data to get the town for this building
    const towns = await fetchTowns();
    const town = towns.find(t => t.id === building.town_id);

    const {t} = await getTranslation();
    return (
        <main>
            <ResourceHeader building={building} resource={resource} town={town} />
			<BuildingPhotos object={building} type={'building'} />

            <section className={'my-2'}>

                <DescriptionAccordion description_json={resource.description_json}/>
				<TextAccordion text={resource.opening_hours} title={t('booking.opening hours')}/>
                <TextAccordion text={resource.contact_info} title={t('bookingfrontend.contact information')}/>
            </section>
                <BuildingCalendar building_id={`${building.id}`} resource_id={`${resourceId}`} initialDate={props.initialDate}/>
                {/*<BuildingContact building={building}/>*/}
        </main>
);
}

export default Resource


