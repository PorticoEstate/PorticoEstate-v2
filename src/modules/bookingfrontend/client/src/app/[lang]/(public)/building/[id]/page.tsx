import BuildingCalendar from "@/components/building-calendar";
import {fetchBuilding} from "@/service/api/building";
import {notFound} from "next/navigation";
import BuildingHeader from "@/components/building-page/building-header";
import DescriptionAccordion from "@/components/building-page/description-accordion";
import BuildingResources from "@/components/building-page/resource-list/building-resources";
import BuildingContact from "@/components/building-page/building-contact";
import PhotosAccordion from "@/components/building-page/building-photos/photos-accordion";
import GSAccordion from "@/components/gs-accordion/g-s-accordion";
import {Button} from "@digdir/designsystemet-react";
interface BuildingShowParams {
    id: string;
}
interface BuildingShowProps {
    params: BuildingShowParams
}


export async function generateMetadata(props: BuildingShowProps) {
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
    return {
        title: building.name,
    }
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
    return (
        <main>
            <BuildingHeader building={building}/>
            <BuildingResources building={building}/>
            {/*<hr className={`my-2 mx-standard`}/>*/}

            <GSAccordion   border color={"neutral"} className={'mx-standard my-2'}>
                <DescriptionAccordion description_json={building.description_json}/>
                <PhotosAccordion object={building} type={'building'}/>
                {/*<BuildingDescription building={building}/>*/}
                {/*<BuildingDescription building={building}/>*/}
            </GSAccordion>
            <hr className={`my-2 mx-2`}/>
            <BuildingCalendar building_id={props.params.id}/>
            <BuildingContact building={building}/>
        </main>
    );
}

export default BuildingShow


