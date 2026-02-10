import {fetchSSRBuildingResources} from "@/service/api/building-ssr";
import {IBuilding} from "@/service/types/Building";
import {Button} from "@digdir/designsystemet-react";
import {getTranslation} from "@/app/i18n";
import { LayersIcon } from "@navikt/aksel-icons";
import Link from "next/link";
import styles from "@/components/building-page/resource-list/building-resources.module.scss";
import React from "react";
import GSAccordion from "@/components/gs-accordion/g-s-accordion";

interface BuildingResourcesProps {
    building: IBuilding;
}

const BuildingResources = async (props: BuildingResourcesProps) => {
    const resources = await fetchSSRBuildingResources(props.building.id, true)
    const {t} = await getTranslation()
    return (
		<GSAccordion data-color={'neutral'}>
			<GSAccordion.Heading>
				<h3>{t('bookingfrontend.rental_resources')}</h3>
			</GSAccordion.Heading>
			<GSAccordion.Content>
				<div className={styles.resourcesGrid}>

                {resources.map((res) =>
                    <Button asChild key={res.id} variant={'secondary'} data-color={'accent'}
                            className={'default'}>
                        <Link href={'/resource/' + res.id}>
                            <LayersIcon fontSize="1.25rem" />{res.name}
                        </Link>
                    </Button>)}
				</div>

			</GSAccordion.Content>
		</GSAccordion>
    );
}

export default BuildingResources


