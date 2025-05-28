import {IBuilding} from "@/service/types/Building";
import {fetchBuildingDocuments, fetchResourceDocuments} from "@/service/api/building";
import PhotosGrid from "@/components/building-page/building-photos/photos-grid";
import { IResource } from "@/service/types/resource.types";

interface BuildingPhotosWrapperProps {
    object: IBuilding | IResource;
    type: 'building' | 'resource';
}

const BuildingPhotos = async (props: BuildingPhotosWrapperProps) => {
    const photos =
        props.type === "building" && await fetchBuildingDocuments(props.object.id, 'images') ||
        props.type === 'resource' && await fetchResourceDocuments(props.object.id, 'images');

    if(!photos || photos.length === 0) return null;

    return (
        <PhotosGrid photos={photos} type={props.type} />
    );
}

export default BuildingPhotos;

