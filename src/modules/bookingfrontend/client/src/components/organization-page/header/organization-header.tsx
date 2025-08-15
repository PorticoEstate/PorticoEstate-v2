import {TenancyIcon} from '@navikt/aksel-icons'
import {Heading} from '@digdir/designsystemet-react'
import DividerCircle from '@/components/util/DividerCircle'
import MapModal from '@/components/map-modal/map-modal'
import {IOrganization} from '@/service/types/api/organization.types'
import {ISearchDataTown} from '@/service/types/api/search.types'
import {IDocument} from '@/service/types/api.types'
import Avatar from '@/components/shared/avatar'
import OrganizationEditButton from '../edit/organization-edit-button'
import styles from './organization-header.module.scss'

interface OrganizationHeaderProps {
	organization: IOrganization
	avatar?: IDocument
	className?: string
}

const OrganizationHeader = (props: OrganizationHeaderProps) => {
	const {organization, avatar, className} = props

	return (
		<section className={`${styles.organizationHeader} ${className || ''}`}>
			{avatar && (
				<Avatar
					document={avatar}
					name={organization.name}
					type="organization"
					size="medium"
				/>
			)}
			<div className={styles.organizationName}>
				<Heading level={2} data-size="md" className={styles.heading}>
					<TenancyIcon fontSize="1.25rem"/>
					{organization.name}
				</Heading>
				<OrganizationEditButton 
					organizationId={organization.id.toString()} 
					className={styles.editButton}
				/>
			</div>
			<div className={styles.infoLine}>
				<span>{organization.city}</span>
				<span><DividerCircle/> {organization.district}</span>
				<span><DividerCircle/> <MapModal city={organization.city || ''} street={organization.street || ''}
												 zip={organization.zip_code || ''}/></span>
			</div>
		</section>
	)
}

export default OrganizationHeader