import {IBuilding} from "@/service/types/Building";
import styles from './building-header.module.scss';
import {getTranslation} from "@/app/i18n";
import MapModal from "@/components/map-modal/map-modal";
import {Heading} from "@digdir/designsystemet-react";
import DividerCircle from "@/components/util/DividerCircle";
import {ISearchDataTown} from "@/service/types/api/search.types";
import BuildingIcon from "@/icons/BuildingIcon";

interface BuildingHeaderProps {
	building: IBuilding;
	town?: ISearchDataTown;
}

const BuildingHeader = async (props: BuildingHeaderProps) => {
	const {building, town} = props
	const {t} = await getTranslation()
	return (
		<section className={`${styles.buildingHeader}`}>
			<div className={styles.buildingName}>
				<Heading level={2} data-size="md" className={styles.heading}>
					<BuildingIcon fontSize="24px"/>
					{building.name}
				</Heading>
			</div>
			<div className={styles.infoLine}>
				<span>{building.city}</span>
				<span><DividerCircle/> {town?.name || building.district}</span>

				<span><DividerCircle/> <MapModal city={building.city} street={building.street} zip={building.zip_code}/></span>
			</div>
		</section>
	);
}

export default BuildingHeader


