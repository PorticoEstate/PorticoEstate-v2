'use client'
import {useForm, Controller} from 'react-hook-form'
import {zodResolver} from '@hookform/resolvers/zod'
import {z} from 'zod'
import {Button, Textfield, Checkbox, Field, Label, ValidationMessage} from "@digdir/designsystemet-react"
import {useTrans} from "@/app/i18n/ClientTranslationProvider"
import {IShortOrganizationDelegate} from "@/service/types/api/organization.types"
import styles from './organization-delegates-content.module.scss'

// Zod schema for delegate form validation
const delegateSchema = z.object({
	ssn: z.string().regex(/^\d{11}$/, 'SSN must be 11 digits'),
	name: z.string().min(1, 'Name is required'),
	email: z.string().optional().refine((val) => !val || val === '' || z.string().email().safeParse(val).success, {
		message: 'Must be a valid email'
	}),
	phone: z.string().optional().refine((val) => !val || val === '' || /^[\+]?[0-9\s\-\(\)]{8,15}$/.test(val), {
		message: 'Invalid phone number format'
	}),
	active: z.boolean()
})

const delegateEditSchema = delegateSchema.omit({ ssn: true, active: true })

export type DelegateFormData = z.infer<typeof delegateSchema>
export type DelegateEditFormData = z.infer<typeof delegateEditSchema>

export interface DelegateFormProps {
	delegate?: IShortOrganizationDelegate
	onSubmit: (data: DelegateFormData | DelegateEditFormData) => void
	onCancel: () => void
	isSubmitting: boolean
	title?: string
	isEdit?: boolean
	hideActions?: boolean
	formId?: string
}

const DelegateForm = ({ delegate, onSubmit, onCancel, isSubmitting, title, isEdit = false, hideActions = false, formId }: DelegateFormProps) => {
	const t = useTrans()

	const schema = isEdit ? delegateEditSchema : delegateSchema

	const {
		register,
		handleSubmit,
		control,
		formState: { errors }
	} = useForm<any>({
		resolver: zodResolver(schema),
		defaultValues: {
			...(isEdit ? {} : { ssn: '' }),
			name: delegate?.name || '',
			email: delegate?.email || '',
			phone: delegate?.phone || '',
			...(isEdit ? {} : { active: true })
		}
	})

	const handleFormSubmit = (data: DelegateFormData | DelegateEditFormData) => {
		// Clean up empty optional strings
		const cleanedData = {
			...data,
			email: data.email?.trim() || undefined,
			phone: data.phone?.trim() || undefined
		}
		onSubmit(cleanedData)
	}

	return (
		<div className={styles.delegateForm}>
			{title && !hideActions && <h4>{title}</h4>}
			<form onSubmit={handleSubmit(handleFormSubmit)} id={formId}>
				{!isEdit && (
					<Field>
						<Label htmlFor="ssn" id="ssn-label">{t('bookingfrontend.ssn')} *</Label>
						<Textfield
							{...register('ssn' as any)}
							id="ssn"
							aria-labelledby="ssn-label"
							placeholder="12345678901"
							error={!!errors.ssn}
							disabled={isSubmitting}
						/>
						{errors.ssn && (
							<ValidationMessage>{String(errors.ssn.message)}</ValidationMessage>
						)}
					</Field>
				)}

				<Field>
					<Label htmlFor="name" id="name-label">{t('bookingfrontend.name')} *</Label>
					<Textfield
						{...register('name')}
						id="name"
						aria-labelledby="name-label"
						error={!!errors.name}
						disabled={isSubmitting}
					/>
					{errors.name && (
						<ValidationMessage>{String(errors.name.message)}</ValidationMessage>
					)}
				</Field>

				<Field>
					<Label htmlFor="email" id="email-label">{t('common.email')}</Label>
					<Textfield
						{...register('email')}
						id="email"
						aria-labelledby="email-label"
						type="email"
						error={!!errors.email}
						disabled={isSubmitting}
					/>
					{errors.email && (
						<ValidationMessage>{String(errors.email.message)}</ValidationMessage>
					)}
				</Field>

				<Field>
					<Label htmlFor="phone" id="phone-label">{t('bookingfrontend.phone')}</Label>
					<Textfield
						{...register('phone')}
						id="phone"
						aria-labelledby="phone-label"
						type="tel"
						error={!!errors.phone}
						disabled={isSubmitting}
					/>
					{errors.phone && (
						<ValidationMessage>{String(errors.phone.message)}</ValidationMessage>
					)}
				</Field>


				{!hideActions && (
					<div className={styles.formActions}>
						<Button
							type="submit"
							disabled={isSubmitting}
													>
							{isSubmitting ? t('common.saving') : t('common.save')}
						</Button>
						<Button
							type="button"
							variant="tertiary"
							onClick={onCancel}
							disabled={isSubmitting}
													>
							{t('common.cancel')}
						</Button>
					</div>
				)}
			</form>
		</div>
	)
}

export default DelegateForm