import { IBuilding } from "@/service/types/Building"
import { Button } from "@digdir/designsystemet-react"
import Link from "next/link"
import styles from './organization-buildings-content.module.scss'
import BuildingIcon from "@/icons/BuildingIcon";

interface OrganizationBuildingsContentProps {
    buildings: IBuilding[]
}

const OrganizationBuildingsContent = async (props: OrganizationBuildingsContentProps) => {
    const { buildings } = props

    return (
        <div className={styles.buildingsContent}>
            {buildings.map((building) => (
                <div key={building.id} className={styles.buildingItem}>

                        <Button asChild variant={'secondary'} color={'neutral'} className={'default'}>
                            <Link href={'/building/' + building.id}>
                                <BuildingIcon fontSize="20px"/>{building.name}
                            </Link>
                        </Button>
                </div>
            ))}
        </div>
    )
}

export default OrganizationBuildingsContent