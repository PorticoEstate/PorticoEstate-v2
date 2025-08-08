import { getTranslation } from "@/app/i18n"
import { IBuilding } from "@/service/types/Building"
import { IResource } from "@/service/types/resource.types"
import ResponsiveWrapper from "@/components/shared/responsive-wrapper"
import DescriptionContent from "./description-content"
import {IOrganization} from "@/service/types/api/organization.types";

interface DescriptionWrapperProps {
    description_json?: (IBuilding | IResource | IOrganization)['description_json']
    className?: string
}

const DescriptionWrapper = async (props: DescriptionWrapperProps) => {
    const { description_json, className } = props
    const { t } = await getTranslation()

    return (
        <ResponsiveWrapper
            title={t('admin.info')}
            isEmpty={!description_json}
            className={className}
        >
            <DescriptionContent description_json={description_json} />
        </ResponsiveWrapper>
    )
}

export default DescriptionWrapper