'use client'
import {FC, useEffect, useState} from 'react'
import {useRouter} from 'next/navigation'
import {useBookingUser} from '@/service/hooks/api-hooks'
import {useOrganization} from '@/service/hooks/organization'
import {useTrans} from '@/app/i18n/ClientTranslationProvider'
import OrganizationEditForm from '@/components/organization-page/edit/organization-edit-form'
import {Button, Heading} from '@digdir/designsystemet-react'
import {ArrowLeftIcon} from '@navikt/aksel-icons'
import Link from 'next/link'
import {updateOrganization} from '@/service/api/api-utils'
import {IOrganization} from '@/service/types/api/organization.types'
import {useQueryClient} from '@tanstack/react-query'

interface OrganizationEditPageClientProps {
	organizationId: string
}

const OrganizationEditPageClient: FC<OrganizationEditPageClientProps> = ({organizationId}) => {
	const router = useRouter()
	const t = useTrans()
	const queryClient = useQueryClient()
	const {data: user, isLoading: userLoading} = useBookingUser()
	const {data: organization, isLoading: orgLoading, error: orgError, refetch: refetchOrganization} = useOrganization(organizationId)
	const [isSaving, setIsSaving] = useState(false)
	const [saveError, setSaveError] = useState<string | null>(null)
	const [saveSuccess, setSaveSuccess] = useState(false)

	// Check if user has delegate access to this organization
	const hasAccess = user?.delegates?.some(delegate => 
		delegate.org_id === Number(organizationId) && delegate.active
	)

	// Handle save with partial updates
	const handleSave = async (formData: {
		name: string;
		shortname?: string | null;
		homepage?: string | null;
		phone?: string | null;
		email?: string | null;
		activity_id?: number | null;
		show_in_portal: boolean;
		street?: string | null;
		zip_code?: string | null;
		city?: string | null;
		description_json?: string | null;
	}) => {
		if (!organization) return

		setIsSaving(true)
		setSaveError(null)
		setSaveSuccess(false)

		try {
			// Create object with only changed fields
			const updatedFields: Partial<Pick<IOrganization, 'name' | 'shortname' | 'phone' | 'email' | 'homepage' | 'activity_id' | 'show_in_portal' | 'street' | 'zip_code' | 'city' | 'description_json'>> = {}
			
			// Only include fields that have actually changed
			if (formData.name !== undefined && formData.name !== organization.name) {
				updatedFields.name = formData.name
			}
			if (formData.shortname !== undefined && formData.shortname !== organization.shortname) {
				updatedFields.shortname = formData.shortname || undefined
			}
			if (formData.phone !== undefined && formData.phone !== organization.phone) {
				updatedFields.phone = formData.phone || undefined
			}
			if (formData.email !== undefined && formData.email !== organization.email) {
				updatedFields.email = formData.email || undefined
			}
			if (formData.homepage !== undefined && formData.homepage !== organization.homepage) {
				updatedFields.homepage = formData.homepage || undefined
			}
			if (formData.activity_id !== undefined && formData.activity_id !== organization.activity_id) {
				updatedFields.activity_id = formData.activity_id
			}
			if (formData.show_in_portal !== undefined && formData.show_in_portal !== !!organization.show_in_portal) {
				updatedFields.show_in_portal = formData.show_in_portal ? 1 : 0
			}
			if (formData.street !== undefined && formData.street !== organization.street) {
				updatedFields.street = formData.street || undefined
			}
			if (formData.zip_code !== undefined && formData.zip_code !== organization.zip_code) {
				updatedFields.zip_code = formData.zip_code || undefined
			}
			if (formData.city !== undefined && formData.city !== organization.city) {
				updatedFields.city = formData.city || undefined
			}
			if (formData.description_json !== undefined && formData.description_json !== organization.description_json) {
				updatedFields.description_json = formData.description_json || undefined
			}

			// Only make API call if there are actual changes
			if (Object.keys(updatedFields).length > 0) {
				await updateOrganization(organizationId, updatedFields)
				setSaveSuccess(true)
				
				// Invalidate all organization-related queries in cache
				await queryClient.invalidateQueries({
					queryKey: ['organization', organizationId]
				})
				await queryClient.invalidateQueries({
					queryKey: ['organizationGroups', organizationId]
				})
				await queryClient.invalidateQueries({
					queryKey: ['organizationBuildings', organizationId]
				})
				await queryClient.invalidateQueries({
					queryKey: ['organizationDelegates', organizationId]
				})
				await queryClient.invalidateQueries({
					queryKey: ['myOrganizations']
				})
				
				// Refetch organization data to get updated values
				await refetchOrganization()
				
				// Redirect back to organization page with cache-busting parameter
				setTimeout(() => {
					router.push(`/organization/${organizationId}?updated=${Date.now()}`)
				}, 1000)
			}
		} catch (error) {
			setSaveError(error instanceof Error ? error.message : 'Failed to update organization')
		} finally {
			setIsSaving(false)
		}
	}

	// Redirect if user doesn't have access (after loading is complete)
	useEffect(() => {
		if (!userLoading && !hasAccess) {
			router.push(`/organization/${organizationId}`)
		}
	}, [userLoading, hasAccess, router, organizationId])

	// Show loading state
	if (userLoading || orgLoading) {
		return (
			<main className="container mx-auto p-6">
				<div className="text-center">
					<p>{t('common.loading')}</p>
				</div>
			</main>
		)
	}

	// Show error if organization not found
	if (orgError || !organization) {
		return (
			<main className="container mx-auto p-6">
				<div className="text-center">
					<Heading level={1} data-size="lg" className="mb-4">
						{t('bookingfrontend.organization_not_found')}
					</Heading>
					<Button asChild variant="secondary">
						<Link href="/">
							<ArrowLeftIcon fontSize="1rem" />
							{t('common.back_to_start')}
						</Link>
					</Button>
				</div>
			</main>
		)
	}

	// Show access denied if user doesn't have permission
	if (!hasAccess) {
		return (
			<main className="container mx-auto p-6">
				<div className="text-center">
					<Heading level={1} data-size="lg" className="mb-4">
						{t('bookingfrontend.access_denied')}
					</Heading>
					<p className="mb-4">{t('bookingfrontend.no_permission_edit_organization')}</p>
					<Button asChild variant="secondary">
						<Link href={`/organization/${organizationId}`}>
							<ArrowLeftIcon fontSize="1rem" />
							{t('common.back_to_organization')}
						</Link>
					</Button>
				</div>
			</main>
		)
	}

	return (
		<main>
			<OrganizationEditForm 
				organization={organization}
				onSave={handleSave}
				isLoading={isSaving}
			/>
		</main>
	)
}

export default OrganizationEditPageClient