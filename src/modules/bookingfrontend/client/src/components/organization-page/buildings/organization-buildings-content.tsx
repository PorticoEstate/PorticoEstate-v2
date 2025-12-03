import { IBuilding } from "@/service/types/Building"
import { Buildings3Icon } from "@navikt/aksel-icons"
import { Button } from "@digdir/designsystemet-react"
import Link from "next/link"
import styles from './organization-buildings-content.module.scss'

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
                                <Buildings3Icon fontSize="20px"/>{building.name}
                            </Link>
                        </Button>
                </div>
            ))}
        </div>
    )
}

export default OrganizationBuildingsContent