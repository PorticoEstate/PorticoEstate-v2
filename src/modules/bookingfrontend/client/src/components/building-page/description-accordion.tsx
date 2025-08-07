import {IBuilding} from "@/service/types/Building";
import {fallbackLng} from "@/app/i18n/settings";
import parse from 'html-react-parser';
import GSAccordion from "@/components/gs-accordion/g-s-accordion";
import {getTranslation} from "@/app/i18n";
import {unescapeHTML} from "@/components/building-page/util/building-text-util";
import {Details} from "@digdir/designsystemet-react";
import { IResource } from "@/service/types/resource.types";

interface DescriptionAccordionProps {
    description_json: (IBuilding | IResource)['description_json'];
}

const DescriptionAccordion = async (props: DescriptionAccordionProps) => {
    const {t, i18n} = await getTranslation();
    const descriptionJson = JSON.parse(props.description_json || '');
    let description = descriptionJson[i18n.language];

    // Check if description is essentially empty (just br tags, whitespace, etc.)
    if (description) {
        const unescaped = unescapeHTML(description);
        const trimmed = unescaped.trim();
        const contentCheck = trimmed.replace(/<br\s*\/?>/gi, '').replace(/\s+/g, '');
        if (!contentCheck || contentCheck.length === 0) {
            description = null; // Treat as empty
        }
    }

    if (!description) {
        description = descriptionJson[fallbackLng.key];

        // Check fallback language content as well
        if (description) {
            const unescaped = unescapeHTML(description);
            const trimmed = unescaped.trim();
            const contentCheck = trimmed.replace(/<br\s*\/?>/gi, '').replace(/\s+/g, '');
            if (!contentCheck || contentCheck.length === 0) {
                description = null; // Treat as empty
            }
        }
    }

    if (!description) {
        return null;
    }
    return (
        <GSAccordion data-color={'neutral'}>
                <GSAccordion.Heading>
                    <h3>{t('common.description')}</h3>
                </GSAccordion.Heading>
                <GSAccordion.Content>{parse(unescapeHTML(description))}</GSAccordion.Content>
        </GSAccordion>
    );
}

export default DescriptionAccordion


