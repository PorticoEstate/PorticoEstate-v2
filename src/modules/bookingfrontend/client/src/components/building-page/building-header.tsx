import {IBuilding} from "@/service/types/Building";
import styles from './building-header.module.scss';
import {getTranslation} from "@/app/i18n";
import MapModal from "@/components/map-modal/map-modal";
import {Buildings3Icon} from "@navikt/aksel-icons";
import {Heading} from "@digdir/designsystemet-react";
import DividerCircle from "@/components/util/DividerCircle";

interface BuildingHeaderProps {
	building: IBuilding;
}

const BuildingHeader = async (props: BuildingHeaderProps) => {
	const {building} = props
	const {t} = await getTranslation()
	return (
		<section className={`${styles.buildingHeader}`}>
			<div className={styles.buildingName}>
				<Heading level={2} data-size="md" className={styles.heading}>
					<Buildings3Icon fontSize="24px"/>
					{building.name}
				</Heading>
			</div>
			<div className={styles.infoLine}>
				<span>{building.city}</span>
				<span><DividerCircle/> {building.district}</span>

				<span><DividerCircle/> <MapModal city={building.city} street={building.street} zip={building.zip_code}/></span>
			</div>
		</section>
	);
}

export default BuildingHeader


