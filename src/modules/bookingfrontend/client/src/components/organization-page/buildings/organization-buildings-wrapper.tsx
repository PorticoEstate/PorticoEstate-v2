import { getTranslation } from "@/app/i18n"
import { IBuilding } from "@/service/types/Building"
import ResponsiveWrapper from "@/components/shared/responsive-wrapper"
import OrganizationBuildingsContent from "./organization-buildings-content"

interface OrganizationBuildingsWrapperProps {
    buildings: IBuilding[]
    className?: string
}

const OrganizationBuildingsWrapper = async (props: OrganizationBuildingsWrapperProps) => {
    const { buildings, className } = props
    const { t } = await getTranslation()
    
    return (
        <ResponsiveWrapper 
            title={t('booking.buildings')} 
            isEmpty={!buildings || buildings.length === 0}
            className={className}
        >
            <OrganizationBuildingsContent buildings={buildings} />
        </ResponsiveWrapper>
    )
}

export default OrganizationBuildingsWrapper