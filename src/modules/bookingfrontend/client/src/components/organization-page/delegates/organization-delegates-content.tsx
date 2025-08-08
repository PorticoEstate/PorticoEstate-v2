'use client'
import {useOrganizationDelegates} from "@/service/hooks/organization"
import {useBookingUser} from "@/service/hooks/api-hooks"
import GSAccordion from "@/components/gs-accordion/g-s-accordion"
import {IShortOrganizationDelegate} from "@/service/types/api/organization.types"
import {PersonIcon, EnvelopeClosedIcon, PlusIcon, PencilIcon, TrashIcon, ArrowUndoIcon} from "@navikt/aksel-icons"
import {GSTable} from "@/components/gs-table"
import {ColumnDef} from "@/components/gs-table/table.types"
import styles from './organization-delegates-content.module.scss'
import {useClientTranslation, useTrans} from "@/app/i18n/ClientTranslationProvider"
import {Button, Checkbox} from "@digdir/designsystemet-react"
import {useState, useMemo} from "react"
import {addOrganizationDelegate, updateOrganizationDelegate, deleteOrganizationDelegate} from "@/service/api/api-utils"
import {useQueryClient} from "@tanstack/react-query"
import MobileDialog from "@/components/dialog/mobile-dialog"
import DelegateForm, {DelegateFormData, DelegateEditFormData} from './delegate-form'


interface OrganizationDelegatesContentProps {
	organizationId: string | number
	className?: string
}

const OrganizationDelegatesContent = (props: OrganizationDelegatesContentProps) => {
	const {organizationId, className} = props
	const {data: user} = useBookingUser()
	const {data: delegates, isLoading, error, refetch} = useOrganizationDelegates(organizationId)
	const t = useTrans();
	const queryClient = useQueryClient()

	const [showAddForm, setShowAddForm] = useState(false)
	const [editingDelegate, setEditingDelegate] = useState<IShortOrganizationDelegate | null>(null)
	const [isSubmitting, setIsSubmitting] = useState(false)
	const [formError, setFormError] = useState<string | null>(null)
	const [showInactive, setShowInactive] = useState(false)

	// Check if user has access to this organization's delegates (only active delegates)
	const hasAccess = user?.delegates?.some(delegate => delegate.org_id === Number(organizationId) && delegate.active)

	// Filter delegates based on show inactive toggle
	const filteredDelegates = useMemo(() => {
		if (!delegates) return []
		if (showInactive) return delegates
		return delegates.filter(delegate => delegate.active)
	}, [delegates, showInactive])

	// Helper function to check if a delegate is the current user
	const isCurrentUser = (delegate: IShortOrganizationDelegate): boolean => {
		return delegate.is_self === true
	}

	// CRUD handlers
	const handleAddDelegate = async (data: DelegateFormData) => {
		setIsSubmitting(true)
		setFormError(null)
		try {
			await addOrganizationDelegate(organizationId, data)
			await queryClient.invalidateQueries({queryKey: ['organizationDelegates', organizationId]})
			await refetch()
			setShowAddForm(false)
		} catch (error) {
			setFormError(error instanceof Error ? error.message : 'Failed to add delegate')
		} finally {
			setIsSubmitting(false)
		}
	}

	const handleRestoreDelegate = async (delegateId: number, delegateName: string) => {
		if (!confirm(t('bookingfrontend.confirm_restore_delegate', { name: delegateName }))) {
			return
		}

		setIsSubmitting(true)
		setFormError(null)
		try {
			await updateOrganizationDelegate(organizationId, delegateId, { active: true })
			await queryClient.invalidateQueries({queryKey: ['organizationDelegates', organizationId]})
			await refetch()
		} catch (error) {
			setFormError(error instanceof Error ? error.message : 'Failed to restore delegate')
		} finally {
			setIsSubmitting(false)
		}
	}

	const handleUpdateDelegate = async (delegateId: number, data: DelegateEditFormData) => {
		setIsSubmitting(true)
		setFormError(null)
		try {
			await updateOrganizationDelegate(organizationId, delegateId, data)
			await queryClient.invalidateQueries({queryKey: ['organizationDelegates', organizationId]})
			await refetch()
			setEditingDelegate(null)
		} catch (error) {
			setFormError(error instanceof Error ? error.message : 'Failed to update delegate')
		} finally {
			setIsSubmitting(false)
		}
	}

	const handleDeleteDelegate = async (delegateId: number, delegateName: string) => {
		if (!confirm(t('bookingfrontend.confirm_delete_delegate', { name: delegateName }))) {
			return
		}

		setIsSubmitting(true)
		setFormError(null)
		try {
			await deleteOrganizationDelegate(organizationId, delegateId)
			await queryClient.invalidateQueries({queryKey: ['organizationDelegates', organizationId]})
			await refetch()
		} catch (error) {
			const errorMessage = error instanceof Error ? error.message : 'Failed to delete delegate'
			setFormError(errorMessage)

			// If it's a self-deletion error, show a more user-friendly message
			if (errorMessage.includes('cannot remove yourself')) {
				setFormError(t('bookingfrontend.cannot_delete_yourself'))
			}
		} finally {
			setIsSubmitting(false)
		}
	}

	// Table columns definition
	const columns: ColumnDef<IShortOrganizationDelegate>[] = [
		{
			id: 'name',
			accessorFn: (row) => row.name,
			meta: {
				size: 2
			},
			header: t('bookingfrontend.name'),
			cell: info => {
				const delegate = info.row.original
				return <span>{delegate.name}</span>
			},
		},
		{
			id: 'email',
			accessorFn: (row) => row.email,
			meta: {
				size: 2
			},
			header: t('common.email'),
			cell: info => {
				const email = info.getValue<string>()
				return email ? (
					<a
						href={`mailto:${email}`}
						className={styles.contactValue}
					>
						{email}
					</a>
				) : '-'
			},
		},
		{
			id: 'phone',
			accessorFn: (row) => row.phone,
			header: t('bookingfrontend.phone'),
			cell: info => {
				const phone = info.getValue<string>()
				return phone ? (
					<a
						href={`tel:${phone}`}
						className={styles.contactValue}
					>
						{phone}
					</a>
				) : '-'
			},
		},
		{
			id: 'status',
			accessorFn: (row) => row.active,
			header: t('common.status'),
			cell: info => {
				const delegate = info.row.original
				return (
					<span className={delegate.active ? styles.activeStatus : styles.inactiveStatus}>
						{delegate.active ? t('bookingfrontend.active') : t('common.inactive')}
					</span>
				)
			},
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
				const delegate = info.row.original
				const isSelf = isCurrentUser(delegate)

				if (!delegate.active) {
					return (
						<Button
							variant="tertiary"
							onClick={() => handleRestoreDelegate(delegate.id, delegate.name)}
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
							onClick={() => setEditingDelegate(delegate)}
							disabled={isSubmitting}
							aria-label={t('bookingfrontend.edit_delegate')}
						>
							<PencilIcon />
						</Button>
						<Button
							variant="tertiary"
							color="danger"
							onClick={() => handleDeleteDelegate(delegate.id, delegate.name)}
							disabled={isSubmitting || isSelf}
							aria-label={isSelf ? t('bookingfrontend.cannot_delete_yourself') : t('bookingfrontend.delete_delegate')}
							title={isSelf ? t('bookingfrontend.cannot_delete_yourself') : undefined}
						>
							<TrashIcon />
						</Button>
					</div>
				)
			},
		},
	]

	if (!hasAccess) {
		return null // Don't show delegates section if user doesn't have access
	}

	if (isLoading) {
		return (
			<GSAccordion data-color={'neutral'} className={className}>
				<GSAccordion.Heading>
					<h3>{t('bookingfrontend.delegates')}</h3>
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
					<h3>{t('bookingfrontend.delegates')}</h3>
				</GSAccordion.Heading>
				<GSAccordion.Content>
					<div className={styles.error}>{t('common.error')}</div>
				</GSAccordion.Content>
			</GSAccordion>
		)
	}

	// Show the main content with table (table will handle empty state)

	return (
		<GSAccordion data-color={'neutral'} className={className}>
			<GSAccordion.Heading>
				<h3>{t('booking.delegates')}</h3>
			</GSAccordion.Heading>
			<GSAccordion.Content>
				{formError && (
					<div className={styles.error}>{formError}</div>
				)}

				<GSTable<IShortOrganizationDelegate>
					data={filteredDelegates}
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
									{t('booking.new delegate')}
								</Button>
							</>
						)
					}}
				/>

				<MobileDialog
					dialogId={'add-delegate-dialog'}
					open={showAddForm}
					onClose={() => setShowAddForm(false)}
					title={t('booking.new delegate')}
					footer={(attemptClose) => (
						<div className={styles.formActions}>
							<Button
								type="submit"
								form="add-delegate-form"
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
						onSubmit={(data) => handleAddDelegate(data as DelegateFormData)}
						onCancel={() => setShowAddForm(false)}
						isSubmitting={isSubmitting}
						hideActions={true}
						formId="add-delegate-form"
					/>
				</MobileDialog>

				{editingDelegate && (
					<MobileDialog
						dialogId={'edit-delegate-dialog'}
						open={!!editingDelegate}
						onClose={() => setEditingDelegate(null)}
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
							delegate={editingDelegate}
							onSubmit={(data) => handleUpdateDelegate(editingDelegate.id, data as DelegateEditFormData)}
							onCancel={() => setEditingDelegate(null)}
							isSubmitting={isSubmitting}
							hideActions={true}
							formId="edit-delegate-form"
							isEdit={true}
						/>
					</MobileDialog>
				)}
			</GSAccordion.Content>
		</GSAccordion>
	)
}


export default OrganizationDelegatesContent