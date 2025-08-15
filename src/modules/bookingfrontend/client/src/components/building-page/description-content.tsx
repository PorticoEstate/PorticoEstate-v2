import { fallbackLng } from "@/app/i18n/settings"
import parse from 'html-react-parser'
import { getTranslation } from "@/app/i18n"
import { unescapeHTML } from "@/components/building-page/util/building-text-util"
import { IBuilding } from "@/service/types/Building"
import { IResource } from "@/service/types/resource.types"
import styles from './description-content.module.scss'
import {IOrganization} from "@/service/types/api/organization.types";

interface DescriptionContentProps {
    description_json: (IBuilding | IResource | IOrganization)['description_json']
}

const DescriptionContent = async (props: DescriptionContentProps) => {
    const { description_json } = props
    const { t, i18n } = await getTranslation()
    let descriptionJson
    try {
        descriptionJson = JSON.parse(description_json || '')
    } catch {
        return null // Invalid JSON
    }

    let description = descriptionJson[i18n.language]

    // Check if description is essentially empty (just br tags, whitespace, etc.)
    if (description) {
        const unescaped = unescapeHTML(description)
        const trimmed = unescaped.trim()
        const contentCheck = trimmed.replace(/<br\s*\/?>/gi, '').replace(/\s+/g, '')
        if (!contentCheck || contentCheck.length === 0) {
            description = null // Treat as empty
        }
    }

    if (!description) {
        description = descriptionJson[fallbackLng.key]

        // Check fallback language content as well
        if (description) {
            const unescaped = unescapeHTML(description)
            const trimmed = unescaped.trim()
            const contentCheck = trimmed.replace(/<br\s*\/?>/gi, '').replace(/\s+/g, '')
            if (!contentCheck || contentCheck.length === 0) {
                description = null // Treat as empty
            }
        }
    }

    if (!description) {
        return null
    }

    const parsedContent = parse(unescapeHTML(description))

    return (
        <div className={styles.descriptionContent}>
            {parsedContent}
        </div>
    )
}

export default DescriptionContent