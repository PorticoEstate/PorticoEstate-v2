import {fetchBuildingResources} from "@/service/api/building";
import {IBuilding} from "@/service/types/Building";
import {Button} from "@digdir/designsystemet-react";
import {getTranslation} from "@/app/i18n";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faLayerGroup} from "@fortawesome/free-solid-svg-icons";
import ClientPHPGWLink from "@/components/layout/header/ClientPHPGWLink";
import ResourceContainer from "@/components/building-page/resource-list/resource-container";
import Link from "next/link";

interface BuildingResourcesProps {
    building: IBuilding;
}

const BuildingResources = async (props: BuildingResourcesProps) => {
    const resources = await fetchBuildingResources(props.building.id)
    const {t} = await getTranslation()
    return (
        <div className={'mx-standard'}>

            <hr className={`my-2`}/>
            <ResourceContainer>
                {resources.map((res) =>
                    <Button asChild key={res.id} variant={'secondary'} color={'neutral'}
                            className={'default'}>
                        {/*<ClientPHPGWLink strURL={'bookingfrontend/'} oArgs={{*/}
                        {/*    menuaction: 'bookingfrontend.uiresource.show',*/}
                        {/*    building_id: props.building.id,*/}
                        {/*    id: res.id*/}
                        {/*}}>*/}
                        <Link href={'/resource/' + res.id}>
                            <FontAwesomeIcon icon={faLayerGroup}/>{res.name}
                        </Link>


                        {/*</ClientPHPGWLink>*/}


                    </Button>)}
            </ResourceContainer>
        </div>
    );
}

export default BuildingResources


