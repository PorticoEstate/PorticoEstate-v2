import {IBuilding} from "@/service/types/Building";
import {fallbackLng} from "@/app/i18n/settings";
import parse from 'html-react-parser';
import GSAccordion from "@/components/gs-accordion/g-s-accordion";
import {getTranslation} from "@/app/i18n";
import {unescapeHTML} from "@/components/building-page/util/building-text-util";
import {Details} from "@digdir/designsystemet-react";

interface DescriptionAccordionProps {
    description_json: (IBuilding | IResource)['description_json'];
}

const DescriptionAccordion = async (props: DescriptionAccordionProps) => {
    const {t, i18n} = await getTranslation();
    const descriptionJson = JSON.parse(props.description_json || '');
    let description = descriptionJson[i18n.language];
    if (!description) {
        description = descriptionJson[fallbackLng.key];
    }
    if (!description) {
        return null;
    }
    return (
        <GSAccordion>
                <GSAccordion.Heading>
                    <h3>{t('common.description')}</h3>
                </GSAccordion.Heading>
                <GSAccordion.Content>{parse(unescapeHTML(description))}</GSAccordion.Content>
        </GSAccordion>
    );
}

export default DescriptionAccordion


