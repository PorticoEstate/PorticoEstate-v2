import ResourceHeader from "@/components/resource-page/resource-header";
import GSAccordion from "@/components/gs-accordion/g-s-accordion";
import DescriptionAccordion from "@/components/building-page/description-accordion";
import PhotosAccordion from "@/components/building-page/building-photos/photos-accordion";
import TextAccordion from "@/components/building-page/text-accordion";
import BuildingCalendar from "@/components/building-calendar";

const page = () => {

    return <main>
        {/*<ResourceHeader building={building} resource={resource}/>*/}
        {/*<hr className={`my-2 mx-standard`}/>*/}
        <GSAccordion border color={"neutral"} className={'mx-standard my-2'}>
            {/*<DescriptionAccordion description_json={resource.description_json}/>*/}
            {/*<PhotosAccordion object={resource} type={"resource"}/>*/}
            <TextAccordion text={`Hello world!`} title={`Tittel`}/>
            {/*<TextAccordion text={resource.contact_info} title={t('bookingfrontend.contact information')}/>*/}
        </GSAccordion>

        {/*<BuildingCalendar building_id={`${building.id}`} resource_id={`${resourceId}`}/>*/}
        {/*<BuildingContact building={building}/>*/}
    </main>
}

export default page