import {IBuilding} from "@/service/types/Building";
import {ISearchDataTown} from "@/service/types/api/search.types";
import styles from '../building-page/building-header.module.scss';
import MapModal from "@/components/map-modal/map-modal";
import {IShortResource} from "@/service/pecalendar.types";
import {Button, Heading} from "@digdir/designsystemet-react";
import Link from "next/link";
import DividerCircle from "@/components/util/DividerCircle";
import { IResource } from "@/service/types/resource.types";
import ResourceIcon from "@/icons/ResourceIcon";
import BuildingIcon from "@/icons/BuildingIcon";
import {HospitalityLine} from "@/components/hospitality-notice/hospitality-notice";

interface ResourceHeaderProps {
	resource: IShortResource | IResource;
	building: IBuilding;
	town?: ISearchDataTown;
	/** Food and drink can be ordered for the room: has_hospitality of GET /resources/{id} */
	hasHospitality?: boolean;
}

const ResourceHeader = async (props: ResourceHeaderProps) => {
	const {building, resource, town, hasHospitality} = props
	return (
		<section className={`${styles.buildingHeader}`}>
			<div className={styles.buildingName}>

				<Heading level={2} data-size="md" className={styles.heading}>
					<ResourceIcon fontSize="24px"/>
					{resource.name}
				</Heading>
			</div>
			<div className={styles.infoLine}>
				<span>{building.city}</span>
				<span><DividerCircle/> {town?.name || building.district}</span>
				<span><DividerCircle/> <MapModal city={building.city} street={building.street}
												 zip={building.zip_code}/></span>
			</div>
			<HospitalityLine hasHospitality={hasHospitality}/>


		</section>
	);
}

export default ResourceHeader


