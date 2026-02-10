import {IBuilding} from "@/service/types/Building";
import {fetchSSRBuildingDocuments, fetchSSROrganizationDocuments, fetchSSRResourceDocuments} from "@/service/api/building-ssr";
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
        props.type === "building" && await fetchSSRBuildingDocuments(props.object.id, 'images') ||
        props.type === "organization" && await fetchSSROrganizationDocuments(props.object.id, 'images') ||
        props.type === 'resource' && await fetchSSRResourceDocuments(props.object.id, 'images');

    if(!photos || photos.length === 0) return null;

    return (
        <div className={props.className}>
            <PhotosGrid photos={photos} type={props.type} />
        </div>
    );
}

export default BuildingPhotos;

