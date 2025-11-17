'use client'

import {useBookingUser} from "@/service/hooks/api-hooks"
import {IOrganization, IOrganizationDelegate} from "@/service/types/api/organization.types"
import {useOrganizationDelegate} from "@/service/hooks/organization"
import {useTrans} from "@/app/i18n/ClientTranslationProvider"
import {Button} from "@digdir/designsystemet-react"
import {ArrowLeftIcon, PencilIcon} from "@navikt/aksel-icons"
import Link from 'next/link'
import {useState} from 'react'
import {useQueryClient} from "@tanstack/react-query"
import MobileDialog from "@/components/dialog/mobile-dialog"
import DelegateForm, {DelegateEditFormData} from '@/components/organization-page/delegates/delegate-form'
import {updateOrganizationDelegate} from "@/service/api/api-utils"
import styles from './delegate-detail-content.module.scss'

interface DelegateDetailContentProps {
	organization: IOrganization
	delegateId: string | number;
	organizationId: string | number
}

const DelegateDetailContent = ({organization, delegateId, organizationId}: DelegateDetailContentProps) => {
	const {data: user} = useBookingUser()
	const t = useTrans()
	const queryClient = useQueryClient()

	const [isEditing, setIsEditing] = useState(false)
	const [isSubmitting, setIsSubmitting] = useState(false)
	const [formError, setFormError] = useState<string | null>(null)

	// Fetch delegate data client-side
	const {data: delegate, isLoading, error} = useOrganizationDelegate(organizationId, delegateId)
	const currentDelegate = delegate

	// Check if user has access to this organization's delegates (only active delegates)
	const hasEditAccess = user?.delegates?.some(delegate => delegate.org_id === Number(organizationId) && delegate.active)

	// Check if this delegate is the current user
	const isCurrentUser = currentDelegate?.is_self === true

	// Handle delegate update
	const handleUpdateDelegate = async (data: DelegateEditFormData) => {
		if (!currentDelegate) return
		
		setIsSubmitting(true)
		setFormError(null)
		try {
			await updateOrganizationDelegate(organizationId, currentDelegate.id, data)
			await queryClient.invalidateQueries({queryKey: ['organizationDelegate', organizationId, currentDelegate.id]})
			await queryClient.invalidateQueries({queryKey: ['organizationDelegates', organizationId]})
			setIsEditing(false)
		} catch (error) {
			setFormError(error instanceof Error ? error.message : 'Failed to update delegate')
		} finally {
			setIsSubmitting(false)
		}
	}

	// Loading state
	if (isLoading) {
		return (
			<div className={styles.container}>
				<div className={styles.navigation}>
					<Link
						href={`/organization/${organizationId}`}
						className={styles.backLink}
					>
						<ArrowLeftIcon aria-hidden="true" />
						{organization.name}
					</Link>
				</div>
				<div className={styles.loading}>
					{t('common.loading')}
				</div>
			</div>
		)
	}

	// Error state
	if (error || !currentDelegate) {
		return (
			<div className={styles.container}>
				<div className={styles.navigation}>
					<Link
						href={`/organization/${organizationId}`}
						className={styles.backLink}
					>
						<ArrowLeftIcon aria-hidden="true" />
						{organization.name}
					</Link>
				</div>
				<div className={styles.errorState}>
					<div className={styles.errorTitle}>
						{t('bookingfrontend.delegate_not_found')}
					</div>
					<div className={styles.errorMessage}>
						{error?.message || t('bookingfrontend.delegate_not_available')}
					</div>
				</div>
			</div>
		)
	}

	return (
		<div className={styles.container}>
			{/* Breadcrumb/Navigation */}
			<div className={styles.navigation}>
				<Link
					href={`/organization/${organizationId}`}
					className={styles.backLink}
				>
					<ArrowLeftIcon aria-hidden="true" />
					{organization.name}
				</Link>
			</div>

			{/* Delegate Header */}
			<header className={styles.header}>
				<div className={styles.titleSection}>
					<h1 className={styles.title}>
						{currentDelegate.name}
						{isCurrentUser && (
							<span className={styles.selfBadge}>
								({t('bookingfrontend.you')})
							</span>
						)}
					</h1>
				</div>

				{hasEditAccess && (
					<div className={styles.actions}>
						<Button
							variant="secondary"
							onClick={() => setIsEditing(true)}
						>
							<PencilIcon aria-hidden="true" />
							{t('bookingfrontend.edit_delegate')}
						</Button>
					</div>
				)}
			</header>

			{formError && (
				<div className={styles.error}>{formError}</div>
			)}

			{/* Delegate Information */}
			<section className={styles.infoSection}>
				<div className={styles.infoGrid}>
					<div className={styles.infoItem}>
						<dt className={styles.label}>{t('bookingfrontend.name')}</dt>
						<dd className={styles.value}>{currentDelegate.name}</dd>
					</div>

					<div className={styles.infoItem}>
						<dt className={styles.label}>{t('common.status')}</dt>
						<dd className={styles.value}>
							<span className={currentDelegate.active ? styles.activeStatus : styles.inactiveStatus}>
								{currentDelegate.active ? t('bookingfrontend.active') : t('common.inactive')}
							</span>
						</dd>
					</div>

					<div className={styles.infoItem}>
						<dt className={styles.label}>{t('common.email')}</dt>
						<dd className={styles.value}>
							{currentDelegate.email ? (
								<a href={`mailto:${currentDelegate.email}`} className={styles.contactLink}>
									{currentDelegate.email}
								</a>
							) : (
								<span className={styles.noValue}>-</span>
							)}
						</dd>
					</div>

					<div className={styles.infoItem}>
						<dt className={styles.label}>{t('common.phone')}</dt>
						<dd className={styles.value}>
							{currentDelegate.phone ? (
								<a href={`tel:${currentDelegate.phone}`} className={styles.contactLink}>
									{currentDelegate.phone}
								</a>
							) : (
								<span className={styles.noValue}>-</span>
							)}
						</dd>
					</div>

					{currentDelegate.ssn && hasEditAccess && (
						<div className={styles.infoItem}>
							<dt className={styles.label}>{t('bookingfrontend.ssn')}</dt>
							<dd className={styles.value}>{currentDelegate.ssn}</dd>
						</div>
					)}

					<div className={styles.infoItem}>
						<dt className={styles.label}>{t('bookingfrontend.organization')}</dt>
						<dd className={styles.value}>
							<Link
								href={`/organization/${organizationId}`}
								className={styles.organizationLink}
							>
								{organization.name}
							</Link>
						</dd>
					</div>
				</div>
			</section>

			{/* Edit Delegate Modal */}
			{isEditing && (
				<MobileDialog
					dialogId={'edit-delegate-dialog'}
					open={isEditing}
					onClose={() => setIsEditing(false)}
					title={t('bookingfrontend.edit_delegate')}
					footer={(attemptClose) => (
						<div className={styles.formActions}>
							<Button
								type="submit"
								form="edit-delegate-form"
								disabled={isSubmitting}
							>
								{isSubmitting ? t('common.saving') : t('common.save')}
							</Button>
							<Button
								type="button"
								variant="tertiary"
								onClick={attemptClose}
								disabled={isSubmitting}
							>
								{t('common.cancel')}
							</Button>
						</div>
					)}
				>
					<DelegateForm
						delegate={currentDelegate}
						onSubmit={(data) => handleUpdateDelegate(data as DelegateEditFormData)}
						onCancel={() => setIsEditing(false)}
						isSubmitting={isSubmitting}
						hideActions={true}
						formId="edit-delegate-form"
						isEdit={true}
					/>
				</MobileDialog>
			)}
		</div>
	)
}

export default DelegateDetailContent