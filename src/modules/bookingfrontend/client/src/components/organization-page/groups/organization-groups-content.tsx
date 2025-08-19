'use client'
import {useOrganizationGroups, useCreateOrganizationGroup, useToggleOrganizationGroupActive} from "@/service/hooks/organization"
import Link from 'next/link'
import {useBookingUser} from "@/service/hooks/api-hooks"
import GSAccordion from "@/components/gs-accordion/g-s-accordion"
import {IShortOrganizationGroup} from "@/service/types/api/organization.types"
import {PlusIcon, PencilIcon, TrashIcon, ArrowUndoIcon} from "@navikt/aksel-icons"
import {GSTable} from "@/components/gs-table"
import {ColumnDef} from "@/components/gs-table/table.types"
import {Button, Checkbox} from "@digdir/designsystemet-react"
import {useState, useMemo} from "react"
import MobileDialog from "@/components/dialog/mobile-dialog"
import GroupForm, {GroupFormData} from './group-form'
import EditGroupModal from './edit-group-modal'
import styles from './organization-groups-content.module.scss'
import {useTrans} from "@/app/i18n/ClientTranslationProvider";

interface OrganizationGroupsContentProps {
	organizationId: string | number
	className?: string
}

const OrganizationGroupsContent = (props: OrganizationGroupsContentProps) => {
	const {organizationId, className} = props
	const {data: user} = useBookingUser()
	const {data: groups, isLoading, error, refetch} = useOrganizationGroups(organizationId)
	const t = useTrans()

	const [showAddForm, setShowAddForm] = useState(false)
	const [editingGroup, setEditingGroup] = useState<IShortOrganizationGroup | null>(null)
	const [isSubmitting, setIsSubmitting] = useState(false)
	const [formError, setFormError] = useState<string | null>(null)
	const [showInactive, setShowInactive] = useState(false)

	// Check if user has access to this organization's groups (only active delegates)
	const hasAccess = user?.delegates?.some(delegate => delegate.org_id === Number(organizationId) && delegate.active)

	// Filter groups based on show inactive toggle
	const filteredGroups = useMemo(() => {
		if (!groups) return []
		if (showInactive) return groups
		return groups.filter(group => group.active)
	}, [groups, showInactive])

	// Mutation hooks
	const createGroupMutation = useCreateOrganizationGroup(organizationId)
	const toggleGroupActiveMutation = useToggleOrganizationGroupActive(organizationId)

	// CRUD handlers
	const handleAddGroup = async (data: GroupFormData) => {
		setIsSubmitting(true)
		setFormError(null)
		try {
			await createGroupMutation.mutateAsync(data)
			setShowAddForm(false)
		} catch (error) {
			setFormError(error instanceof Error ? error.message : 'Failed to create group')
		} finally {
			setIsSubmitting(false)
		}
	}


	const handleToggleGroupActive = async (groupId: number, groupName: string, currentActive: boolean) => {
		const action = currentActive ? 'deactivate' : 'activate'
		const confirmMessage = currentActive
			? t('bookingfrontend.confirm_deactivate_group', { name: groupName })
			: t('bookingfrontend.confirm_activate_group', { name: groupName })

		if (!confirm(confirmMessage)) {
			return
		}

		setIsSubmitting(true)
		setFormError(null)
		try {
			await toggleGroupActiveMutation.mutateAsync({ groupId, active: !currentActive })
		} catch (error) {
			setFormError(error instanceof Error ? error.message : `Failed to ${action} group`)
		} finally {
			setIsSubmitting(false)
		}
	}

	// Table columns definition
	const columns: ColumnDef<IShortOrganizationGroup>[] = [
		{
			id: 'name',
			accessorFn: (row) => row.name,
			meta: {
				size: 2
			},
			header: t('bookingfrontend.group_name'),
			cell: info => {
				const group = info.row.original
				return (
					<div>
						<Link 
							href={`/organization/${organizationId}/group/${group.id}`} 
							className={styles.groupTitle}
						>
							{group.name}
						</Link>
						{group.shortname && (
							<div className={styles.groupShortname}>{group.shortname}</div>
						)}
					</div>
				)
			}
		},
		{
			id: 'status',
			accessorFn: (row) => row.active,
			header: t('common.status'),
			cell: info => {
				const group = info.row.original
				return (
					<span className={group.active ? styles.activeStatus : styles.inactiveStatus}>
						{group.active ? t('bookingfrontend.active') : t('common.inactive')}
					</span>
				)
			}
		},
		{
			id: 'portal',
			accessorFn: (row) => row.show_in_portal,
			header: t('bookingfrontend.show_in_portal'),
			cell: info => {
				const group = info.row.original
				return group.show_in_portal ? t('common.yes') : t('common.no')
			}
		},
		{
			id: 'actions',
			header: '',
			meta: {
				size: 1,
				align: 'end'
			},
			enableSorting: false,
			enableColumnFilter: false,
			enableHiding: false,
			cell: info => {
				const group = info.row.original

				if (!group.active) {
					return (
						<Button
							variant="tertiary"
							onClick={() => handleToggleGroupActive(group.id, group.name, !!group.active)}
							disabled={isSubmitting}
							aria-label={t('common.restore')}
							title={t('common.restore')}
						>
							<ArrowUndoIcon />
						</Button>
					)
				}

				return (
					<div className={styles.actionButtons}>
						<Button
							variant="tertiary"
							onClick={() => setEditingGroup(group)}
							disabled={isSubmitting}
							aria-label={t('bookingfrontend.edit_group')}
						>
							<PencilIcon />
						</Button>
						<Button
							variant="tertiary"
							color="danger"
							onClick={() => handleToggleGroupActive(group.id, group.name, !!group.active)}
							disabled={isSubmitting}
							aria-label={t('bookingfrontend.deactivate_group')}
						>
							<TrashIcon />
						</Button>
					</div>
				)
			}
		}
	]

	// Show read-only view for users without management access
	const isReadOnly = !hasAccess

	if (isLoading) {
		return (
			<GSAccordion data-color={'neutral'} className={className}>

				<GSAccordion.Heading>
					<h3>{t('common.groups')}</h3>
				</GSAccordion.Heading>
				<GSAccordion.Content>
					<div className={styles.loading}>{t('common.loading')}</div>
				</GSAccordion.Content>
			</GSAccordion>
		)
	}

	if (error) {
		return (
			<GSAccordion data-color={'neutral'} className={className}>

				<GSAccordion.Heading>
					<h3>{t('common.groups')}</h3>
				</GSAccordion.Heading>
				<GSAccordion.Content>
					<div className={styles.error}>{t('common.error')}</div>
				</GSAccordion.Content>
			</GSAccordion>
		)
	}


	// Read-only view for unauthenticated users
	if (isReadOnly) {
		const activeGroups = filteredGroups.filter(group => group.active)

		return (
			<GSAccordion data-color={'neutral'} className={className}>
				<GSAccordion.Heading>
					<h3>{t('common.groups')}</h3>
				</GSAccordion.Heading>
				<GSAccordion.Content>
					{activeGroups.length > 0 ? (
						<div className={styles.readOnlyGroups}>
							{activeGroups.map(group => (
								<div key={group.id} className={styles.groupItem}>
									<Link 
										href={`/organization/${organizationId}/group/${group.id}`} 
										className={styles.groupName}
									>
										{group.name}
									</Link>
									{group.shortname && (
										<span className={styles.groupShortname}>({group.shortname})</span>
									)}
								</div>
							))}
						</div>
					) : (
						<div className={styles.noGroups}>
							<p>{t('bookingfrontend.no_groups_available')}</p>
						</div>
					)}
				</GSAccordion.Content>
			</GSAccordion>
		)
	}

	return (
		<GSAccordion data-color={'neutral'} className={className}>
			<GSAccordion.Heading>
				<h3>{t('common.groups')}</h3>
			</GSAccordion.Heading>
			<GSAccordion.Content>
				{formError && (
					<div className={styles.error}>{formError}</div>
				)}

				<GSTable<IShortOrganizationGroup>
					data={filteredGroups}
					columns={columns}
					enableSorting={true}
					disableColumnHiding={true}
					enableSearch
					enablePagination={false}
					isLoading={isLoading}
					utilityHeader={{
						right: (
							<>
								<Checkbox
									checked={showInactive}
									onChange={(e) => setShowInactive(e.target.checked)}
									label={t('bookingfrontend.show_inactive')}
									className={styles.showInactiveToggle}
								/>
								<Button
									variant="tertiary"
									onClick={() => setShowAddForm(true)}
									disabled={isSubmitting}
								>
									<PlusIcon />
									{t('bookingfrontend.new_group')}
								</Button>
							</>
						)
					}}
				/>

				<MobileDialog
					dialogId={'add-group-dialog'}
					open={showAddForm}
					onClose={() => setShowAddForm(false)}
					title={t('bookingfrontend.new_group')}
					footer={(attemptClose) => (
						<div className={styles.formActions}>
							<Button
								type="submit"
								form="add-group-form"
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
					<GroupForm
						organizationId={organizationId}
						onSubmit={(data) => handleAddGroup(data as GroupFormData)}
						onCancel={() => setShowAddForm(false)}
						isSubmitting={isSubmitting}
						hideActions={true}
						formId="add-group-form"
					/>
				</MobileDialog>

				{editingGroup && (
					<EditGroupModal
						group={editingGroup}
						organizationId={organizationId}
						isOpen={!!editingGroup}
						onClose={() => setEditingGroup(null)}
					/>
				)}
			</GSAccordion.Content>
		</GSAccordion>
	)
}

export default OrganizationGroupsContent