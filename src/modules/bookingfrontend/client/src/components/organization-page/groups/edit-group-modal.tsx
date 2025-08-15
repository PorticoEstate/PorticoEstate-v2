'use client'

import {useState} from 'react'
import {IShortOrganizationGroup, IOrganizationGroup} from "@/service/types/api/organization.types"
import {useUpdateOrganizationGroup} from "@/service/hooks/organization"
import {useTrans} from "@/app/i18n/ClientTranslationProvider"
import {Button} from "@digdir/designsystemet-react"
import MobileDialog from "@/components/dialog/mobile-dialog"
import GroupForm, {GroupEditFormData} from './group-form'
import styles from './edit-group-modal.module.scss'

interface EditGroupModalProps {
	group: IShortOrganizationGroup
	organizationId: string | number
	isOpen: boolean
	onClose: () => void
	onSuccess?: () => void
}

const EditGroupModal = ({group, organizationId, isOpen, onClose, onSuccess}: EditGroupModalProps) => {
	const t = useTrans()
	const [isSubmitting, setIsSubmitting] = useState(false)
	const [formError, setFormError] = useState<string | null>(null)

	// Mutation hook for updating group
	const updateGroupMutation = useUpdateOrganizationGroup(organizationId)

	// Handle group update
	const handleUpdateGroup = async (data: GroupEditFormData) => {
		setIsSubmitting(true)
		setFormError(null)
		try {
			await updateGroupMutation.mutateAsync({
				groupId: group.id,
				data
			})
			onClose()
			onSuccess?.()
		} catch (error) {
			setFormError(error instanceof Error ? error.message : 'Failed to update group')
		} finally {
			setIsSubmitting(false)
		}
	}

	const handleClose = () => {
		if (!isSubmitting) {
			setFormError(null)
			onClose()
		}
	}

	return (
		<MobileDialog
			dialogId={'edit-group-dialog'}
			open={isOpen}
			onClose={handleClose}
			title={t('bookingfrontend.edit_group')}
			footer={(attemptClose) => (
				<div className={styles.formActions}>
					<Button
						type="submit"
						form="edit-group-form"
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
			{formError && (
				<div className={styles.error}>{formError}</div>
			)}
			
			<GroupForm
				group={group as IOrganizationGroup}
				organizationId={organizationId}
				onSubmit={(data) => handleUpdateGroup(data as GroupEditFormData)}
				onCancel={handleClose}
				isSubmitting={isSubmitting}
				hideActions={true}
				formId="edit-group-form"
				isEdit={true}
			/>
		</MobileDialog>
	)
}

export default EditGroupModal