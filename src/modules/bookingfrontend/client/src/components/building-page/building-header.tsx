import {IBuilding} from "@/service/types/Building";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faLocationDot} from "@fortawesome/free-solid-svg-icons";
import styles from './building-header.module.scss';
import {getTranslation} from "@/app/i18n";
import MapModal from "@/components/map-modal/map-modal";
interface BuildingHeaderProps {
    building: IBuilding;
}

const BuildingHeader = async (props: BuildingHeaderProps) => {
    const {building} = props
    const {t} = await getTranslation()
    return (
        <section className={`${styles.buildingHeader} mx-3`}>
            <div className={styles.buildingName}>
                <h2>
                    <FontAwesomeIcon  style={{fontSize: '22px'}} icon={faLocationDot}/>
                    {building.name}
                </h2>
            </div>
            <div className={`${styles.buildingLocation}`}>
                <MapModal city={building.city} street={building.street} zip={building.zip_code} />
            </div>
            <div className={`${styles.buildingArea} text-overline`}>
                {t('booking.district')}: {building.district}
            </div>
        </section>
    );
}

export default BuildingHeader


