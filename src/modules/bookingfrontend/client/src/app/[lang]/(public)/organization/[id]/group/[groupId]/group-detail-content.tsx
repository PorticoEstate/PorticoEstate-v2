'use client'

import {useBookingUser} from "@/service/hooks/api-hooks"
import {IOrganization, IShortOrganizationGroup, IOrganizationGroup} from "@/service/types/api/organization.types"
import {useOrganizationGroup} from "@/service/hooks/organization"
import {useTrans} from "@/app/i18n/ClientTranslationProvider"
import {Button} from "@digdir/designsystemet-react"
import {ArrowLeftIcon, PencilIcon} from "@navikt/aksel-icons"
import Link from 'next/link'
import {useState} from 'react'
import {useQueryClient} from "@tanstack/react-query"
import EditGroupModal from '@/components/organization-page/groups/edit-group-modal'
import styles from './group-detail-content.module.scss'

interface GroupDetailContentProps {
	organization: IOrganization
	group: IShortOrganizationGroup
	organizationId: string | number
}

const GroupDetailContent = ({organization, group, organizationId}: GroupDetailContentProps) => {
	const {data: user} = useBookingUser()
	const t = useTrans()
	const queryClient = useQueryClient()

	const [isEditing, setIsEditing] = useState(false)

	// Use the updated group data from the hook for real-time updates
	const {data: updatedGroup} = useOrganizationGroup(organizationId, group.id)
	const currentGroup = updatedGroup || group

	// Check if user has access to this organization's groups (only active delegates)
	const hasEditAccess = user?.delegates?.some(delegate => delegate.org_id === Number(organizationId) && delegate.active)

	// Handle successful edit to refresh data
	const handleEditSuccess = () => {
		queryClient.invalidateQueries({ queryKey: ['organizationGroup', organizationId, group.id] })
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

			{/* Group Header */}
			<header className={styles.header}>
				<div className={styles.titleSection}>
					<h1 className={styles.title}>{currentGroup.name}</h1>
					{currentGroup.shortname && (
						<div className={styles.shortname}>({currentGroup.shortname})</div>
					)}
				</div>

				{hasEditAccess && (
					<div className={styles.actions}>
						<Button
							variant="secondary"
							size="medium"
							onClick={() => setIsEditing(true)}
						>
							<PencilIcon aria-hidden="true" />
							{t('bookingfrontend.edit_group')}
						</Button>
					</div>
				)}
			</header>

			{/* Group Information */}
			<section className={styles.infoSection}>
				<div className={styles.infoGrid}>
					<div className={styles.infoItem}>
						<dt className={styles.label}>{t('bookingfrontend.group_name')}</dt>
						<dd className={styles.value}>{currentGroup.name}</dd>
					</div>

					{currentGroup.shortname && (
						<div className={styles.infoItem}>
							<dt className={styles.label}>{t('bookingfrontend.short_name')}</dt>
							<dd className={styles.value}>{currentGroup.shortname}</dd>
						</div>
					)}

					<div className={styles.infoItem}>
						<dt className={styles.label}>{t('common.status')}</dt>
						<dd className={styles.value}>
							<span className={currentGroup.active ? styles.activeStatus : styles.inactiveStatus}>
								{currentGroup.active ? t('bookingfrontend.active') : t('common.inactive')}
							</span>
						</dd>
					</div>

					<div className={styles.infoItem}>
						<dt className={styles.label}>{t('bookingfrontend.show_in_portal')}</dt>
						<dd className={styles.value}>
							{currentGroup.show_in_portal ? t('common.yes') : t('common.no')}
						</dd>
					</div>

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

					{currentGroup.description && (
						<div className={styles.infoItem}>
							<dt className={styles.label}>{t('common.description')}</dt>
							<dd className={styles.value}>{currentGroup.description}</dd>
						</div>
					)}
				</div>
			</section>

			{/* Group Contacts Section */}
			<section className={styles.contactsSection}>
				<h2 className={styles.sectionTitle}>{t('common.contacts')}</h2>
				{currentGroup.contacts && currentGroup.contacts.length > 0 ? (
					<div className={styles.contactsList}>
						{currentGroup.contacts
							.filter(contact => contact.name || contact.email || contact.phone) // Filter out empty contacts
							.map(contact => (
								<div key={contact.id} className={styles.contactItem}>
									{contact.name && (
										<div className={styles.contactName}>{contact.name}</div>
									)}
									{contact.email && (
										<div className={styles.contactDetail}>
											<span className={styles.contactLabel}>{t('common.email')}:</span>
											<a href={`mailto:${contact.email}`} className={styles.contactLink}>
												{contact.email}
											</a>
										</div>
									)}
									{contact.phone && (
										<div className={styles.contactDetail}>
											<span className={styles.contactLabel}>{t('common.phone')}:</span>
											<a href={`tel:${contact.phone}`} className={styles.contactLink}>
												{contact.phone}
											</a>
										</div>
									)}
								</div>
							))
						}
					</div>
				) : (
					<div className={styles.placeholder}>
						<p>{t('bookingfrontend.no_contacts_available')}</p>
					</div>
				)}
			</section>

			{/* Edit Group Modal */}
			{isEditing && (
				<EditGroupModal
					group={currentGroup}
					organizationId={organizationId}
					isOpen={isEditing}
					onClose={() => setIsEditing(false)}
					onSuccess={handleEditSuccess}
				/>
			)}
		</div>
	)
}

export default GroupDetailContent