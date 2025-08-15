'use client'
import {FC} from 'react'
import {Button} from '@digdir/designsystemet-react'
import {PencilIcon} from '@navikt/aksel-icons'
import {useBookingUser} from '@/service/hooks/api-hooks'
import {useTrans} from '@/app/i18n/ClientTranslationProvider'
import Link from 'next/link'

interface OrganizationEditButtonProps {
	organizationId: string
	className?: string
}

const OrganizationEditButton: FC<OrganizationEditButtonProps> = ({organizationId, className}) => {
	const {data: user} = useBookingUser()
	const t = useTrans()

	// Check if user has delegate access to this organization
	const hasAccess = user?.delegates?.some(delegate => 
		delegate.org_id === Number(organizationId) && delegate.active
	)

	// Don't render button if user doesn't have access
	if (!hasAccess) {
		return null
	}

	return (
		<Button 
			asChild 
			variant="secondary" 
			data-size="sm"
			className={className}
		>
			<Link href={`/organization/${organizationId}/edit`}>
				<PencilIcon fontSize="1rem" />
				{t('common.edit')}
			</Link>
		</Button>
	)
}

export default OrganizationEditButton