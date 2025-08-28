import {IBuilding} from "@/service/types/Building";
import {fetchBuildingDocuments, fetchOrganizationDocuments, fetchResourceDocuments} from "@/service/api/building";
import PhotosGrid from "@/components/building-page/building-photos/photos-grid";
import { IResource } from "@/service/types/resource.types";
import {IOrganization} from "@/service/types/api/organization.types";
import {IDocument} from "@/service/types/api.types";

interface BuildingPhotosWrapperProps {
    object: IBuilding | IResource | IOrganization;
    type: 'building' | 'resource' | 'organization';
	photos?: IDocument[];
	className?: string;
}

const BuildingPhotos = async (props: BuildingPhotosWrapperProps) => {
    const photos = props.photos ||
        props.type === "building" && await fetchBuildingDocuments(props.object.id, 'images') ||
        props.type === "organization" && await fetchOrganizationDocuments(props.object.id, 'images') ||
        props.type === 'resource' && await fetchResourceDocuments(props.object.id, 'images');

    if(!photos || photos.length === 0) return null;

    return (
        <div className={props.className}>
            <PhotosGrid photos={photos} type={props.type} />
        </div>
    );
}

export default BuildingPhotos;

