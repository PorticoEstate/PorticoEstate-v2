import {IBuilding} from "@/service/types/Building";
import {fetchBuildingDocuments} from "@/service/api/building";
import BuildingPhotos from "@/components/building-page/building-photos/building-photos";
interface BuildingPhotosWrapperProps {
    building: IBuilding
}


const BuildingPhotosWrapper = async (props: BuildingPhotosWrapperProps) => {
    const photos = await fetchBuildingDocuments(props.building.id, 'images');
    return (
        <BuildingPhotos building={props.building} photos={photos}></BuildingPhotos>
    );
}

export default BuildingPhotosWrapper


