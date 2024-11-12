import {IBuilding} from "@/service/types/Building";
import {fetchBuildingDocuments, fetchResourceDocuments} from "@/service/api/building";
import PhotosGrid from "@/components/building-page/building-photos/photos-grid";
interface BuildingPhotosWrapperProps {
    object: IBuilding | IResource;
    type: 'building' | 'resource';
}


const PhotosAccordion = async (props: BuildingPhotosWrapperProps) => {
    const photos =
        props.type === "building" && await fetchBuildingDocuments(props.object.id, 'images') ||
        props.type === 'resource' && await fetchResourceDocuments(props.object.id, 'images');

    if(!photos) return null;
    return (
        <PhotosGrid photos={photos}></PhotosGrid>
    );
}

export default PhotosAccordion


