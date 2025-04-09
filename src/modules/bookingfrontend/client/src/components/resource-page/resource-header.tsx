import {IBuilding} from "@/service/types/Building";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faCircle, faLayerGroup} from "@fortawesome/free-solid-svg-icons";
import {Buildings3Icon} from "@navikt/aksel-icons";
import styles from '../building-page/building-header.module.scss';
import {getTranslation} from "@/app/i18n";
import MapModal from "@/components/map-modal/map-modal";
import {IShortResource} from "@/service/pecalendar.types";
import ClientPHPGWLink from "@/components/layout/header/ClientPHPGWLink";
import {Button} from "@digdir/designsystemet-react";
import Link from "next/link";

interface ResourceHeaderProps {
    resource: IShortResource | IResource;
    building: IBuilding;
}

const ResourceHeader = async (props: ResourceHeaderProps) => {
    const {building, resource} = props
    const {t} = await getTranslation()
    return (
        <section className={`${styles.buildingHeader} mx-standard`}>
            <div className={styles.buildingName}>
                <h2>
                    <FontAwesomeIcon style={{fontSize: '22px'}} icon={faLayerGroup}/>
                    {resource.name}
                </h2>
            </div>
            <div className={`${styles.buildingLocation}`}>
                <MapModal city={building.city} street={building.street} zip={building.zip_code}/>
            </div>
            <div className={`${styles.buildingArea} text-overline`}>
                <span>{t('booking.district')}: {building.district}</span>
                <FontAwesomeIcon icon={faCircle} fontSize={'6px'}/>
                <span>{t('bookingfrontend.building')}: {building.name}</span>
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


