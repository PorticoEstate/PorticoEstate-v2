import {getTranslation} from "@/app/i18n";
import {IBuilding} from "@/service/types/Building";

interface BuildingContactProps {
    building: IBuilding;
}

const BuildingContact = async (props: BuildingContactProps) => {
    const {t} = await getTranslation();
    return (
        <div className={'mx-3'}>
            <hr className={`my-2`}/>
            <div>
                <h3>
                    {t('common.contact')}
                </h3>
                <div>{t('booking.phone')}: {props.building.phone}</div>
                <div>{t('common.email')}: {props.building.email}</div>
            </div>
        </div>
    );
}

export default BuildingContact

