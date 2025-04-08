import {IBuilding} from "@/service/types/Building";
import {ISearchDataTown} from "@/service/types/api/search.types";
import {Buildings3Icon, LayersIcon} from "@navikt/aksel-icons";
import styles from '../building-page/building-header.module.scss';
import MapModal from "@/components/map-modal/map-modal";
import {IShortResource} from "@/service/pecalendar.types";
import {Button, Heading} from "@digdir/designsystemet-react";
import Link from "next/link";
import DividerCircle from "@/components/util/DividerCircle";

interface ResourceHeaderProps {
	resource: IShortResource | IResource;
	building: IBuilding;
	town?: ISearchDataTown;
}

const ResourceHeader = async (props: ResourceHeaderProps) => {
	const {building, resource, town} = props
	return (
		<section className={`${styles.buildingHeader}`}>
			<div className={styles.buildingName}>

				<Heading level={2} data-size="md" className={styles.heading}>
					<LayersIcon fontSize="24px"/>
					{resource.name}
				</Heading>
			</div>
			<div className={styles.infoLine}>
				<span>{building.city}</span>
				<span><DividerCircle/> {town?.name || building.district}</span>
				<span><DividerCircle/> <MapModal city={building.city} street={building.street}
												 zip={building.zip_code}/></span>
			</div>
			<div style={{display: 'flex', marginTop: '1rem'}}>
				<Button asChild variant={'secondary'} color={'neutral'}
						className={'default'}>
					<Link href={'/building/' + building.id}><Buildings3Icon fontSize="20px"/>{building.name}</Link>

				</Button>
			</div>

		</section>
	);
}

export default ResourceHeader


