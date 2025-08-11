'use client'
import {FC} from 'react'
import {useForm, Controller} from 'react-hook-form'
import {zodResolver} from '@hookform/resolvers/zod'
import {z} from 'zod'
import {
	Button,
	Checkbox,
	Field,
	Fieldset,
	Heading,
	Label,
	Textfield,
	Textarea,
	ValidationMessage
} from '@digdir/designsystemet-react'
import {useTrans} from '@/app/i18n/ClientTranslationProvider'
import {IOrganization} from '@/service/types/api/organization.types'
import {useRouter} from 'next/navigation'
import MultilingualMarkdownEditor from './multilingual-markdown-editor'
import styles from './organization-edit-form.module.scss'

// Zod schema for organization edit form
const organizationEditSchema = z.object({
	name: z.string().min(1, 'Organization name is required'),
	shortname: z.string().nullable().optional(),
	homepage: z.string().url('Must be a valid URL').nullable().optional().or(z.literal('')),
	phone: z.string().nullable().optional(),
	email: z.string().email('Must be a valid email').nullable().optional().or(z.literal('')),
	activity_id: z.number().nullable().optional(),
	show_in_portal: z.boolean(),
	street: z.string().nullable().optional(),
	zip_code: z.string().nullable().optional(),
	city: z.string().nullable().optional(),
	description_json: z.string().nullable().optional(),
})

type OrganizationEditFormData = z.infer<typeof organizationEditSchema>

interface OrganizationEditFormProps {
	organization: IOrganization
	onSave?: (data: OrganizationEditFormData) => Promise<void>
	isLoading?: boolean
}

const OrganizationEditForm: FC<OrganizationEditFormProps> = ({
	organization,
	onSave,
	isLoading = false
}) => {
	const t = useTrans()
	const router = useRouter()

	const {
		register,
		handleSubmit,
		control,
		formState: { errors, isSubmitting }
	} = useForm<OrganizationEditFormData>({
		resolver: zodResolver(organizationEditSchema),
		defaultValues: {
			name: organization.name,
			shortname: organization.shortname || '',
			homepage: organization.homepage || '',
			phone: organization.phone || '',
			email: organization.email || '',
			activity_id: organization.activity_id,
			show_in_portal: Boolean(organization.show_in_portal),
			street: organization.street || '',
			zip_code: organization.zip_code || '',
			city: organization.city || '',
			description_json: organization.description_json || '',
		}
	})

	const onSubmit = async (data: OrganizationEditFormData) => {
		try {
			if (onSave) {
				await onSave(data)
			}
			// Navigate back to organization page after successful save
			router.push(`/organization/${organization.id}`)
		} catch (error) {
			console.error('Failed to save organization:', error)
		}
	}

	const handleCancel = () => {
		router.push(`/organization/${organization.id}`)
	}

	return (
		<div className={styles.editFormContainer}>
			<Heading level={1} data-size="lg" className={styles.title}>
				{t('bookingfrontend.edit_organization')}
			</Heading>

			<form onSubmit={handleSubmit(onSubmit)} className={styles.form}>
				{/* Basic Information */}
				<Fieldset>
					<Fieldset.Legend>
						<Heading level={2} data-size="md">
							{t('bookingfrontend.basic_information')}
						</Heading>
					</Fieldset.Legend>

					<div className={styles.fieldGrid}>
						{/* Organization Number - READ ONLY */}
						<Field>
							<Label htmlFor="organization_number" id="organization_number-label">{t('booking.organization_number')}</Label>
							<Textfield
								id="organization_number"
								aria-labelledby="organization_number-label"
								value={organization.organization_number}
								readOnly
								disabled
								className={styles.readOnlyField}
							/>
						</Field>

						{/* Name */}
						<Field>
							<Label htmlFor="name" id="name-label">{t('bookingfrontend.name')} *</Label>
							<Textfield
								{...register('name')}
								id="name"
								aria-labelledby="name-label"
								error={!!errors.name}
							/>
							{errors.name && (
								<ValidationMessage>{errors.name.message}</ValidationMessage>
							)}
						</Field>

						{/* Short Name */}
						<Field>
							<Label htmlFor="shortname" id="shortname-label">{t('bookingfrontend.short_name')}</Label>
							<Textfield
								{...register('shortname')}
								id="shortname"
								aria-labelledby="shortname-label"
								error={!!errors.shortname}
							/>
							{errors.shortname && (
								<ValidationMessage>{errors.shortname.message}</ValidationMessage>
							)}
						</Field>

						{/* Homepage */}
						<Field>
							<Label htmlFor="homepage" id="homepage-label">{t('bookingfrontend.homepage')}</Label>
							<Textfield
								{...register('homepage')}
								id="homepage"
								type="url"
								placeholder="https://example.com"
								aria-labelledby="homepage-label"
								error={!!errors.homepage}
							/>
							{errors.homepage && (
								<ValidationMessage>{errors.homepage.message}</ValidationMessage>
							)}
						</Field>

						{/* Phone */}
						<Field>
							<Label htmlFor="phone" id="phone-label">{t('bookingfrontend.phone')}</Label>
							<Textfield
								{...register('phone')}
								id="phone"
								type="tel"
								aria-labelledby="phone-label"
								error={!!errors.phone}
							/>
							{errors.phone && (
								<ValidationMessage>{errors.phone.message}</ValidationMessage>
							)}
						</Field>

						{/* Email */}
						<Field>
							<Label htmlFor="email" id="email-label">{t('common.email')}</Label>
							<Textfield
								{...register('email')}
								id="email"
								type="email"
								aria-labelledby="email-label"
								error={!!errors.email}
							/>
							{errors.email && (
								<ValidationMessage>{errors.email.message}</ValidationMessage>
							)}
						</Field>
					</div>
				</Fieldset>

				{/* Address Information */}
				<Fieldset>
					<Fieldset.Legend>
						<Heading level={2} data-size="md">
							{t('bookingfrontend.address')}
						</Heading>
					</Fieldset.Legend>

					<div className={styles.fieldGrid}>
						{/* Street */}
						<Field className={styles.fullWidthField}>
							<Label htmlFor="street" id="street-label">{t('bookingfrontend.street')}</Label>
							<Textfield
								{...register('street')}
								id="street"
								aria-labelledby="street-label"
								error={!!errors.street}
							/>
							{errors.street && (
								<ValidationMessage>{errors.street.message}</ValidationMessage>
							)}
						</Field>

						{/* Postal Code */}
						<Field>
							<Label htmlFor="zip_code" id="zip_code-label">{t('bookingfrontend.zip code')}</Label>
							<Textfield
								{...register('zip_code')}
								id="zip_code"
								aria-labelledby="zip_code-label"
								error={!!errors.zip_code}
							/>
							{errors.zip_code && (
								<ValidationMessage>{errors.zip_code.message}</ValidationMessage>
							)}
						</Field>

						{/* Postal Area (City) */}
						<Field>
							<Label htmlFor="city" id="city-label">{t('bookingfrontend.postal city')}</Label>
							<Textfield
								{...register('city')}
								id="city"
								aria-labelledby="city-label"
								error={!!errors.city}
							/>
							{errors.city && (
								<ValidationMessage>{errors.city.message}</ValidationMessage>
							)}
						</Field>
					</div>
				</Fieldset>

				{/* Description */}
				<Fieldset>
					<Fieldset.Legend>
						<Heading level={2} data-size="md">
							{t('bookingfrontend.description')}
						</Heading>
					</Fieldset.Legend>

					<MultilingualMarkdownEditor
						name="description_json"
						control={control}
						label={t('bookingfrontend.description')}
						error={errors.description_json?.message as string}
						initialHtmlValue={organization.description_json}
						className={styles.descriptionField}
					/>
				</Fieldset>

				{/* Settings */}
				<Fieldset>
					<Fieldset.Legend>
						<Heading level={2} data-size="md">
							{t('bookingfrontend.settings')}
						</Heading>
					</Fieldset.Legend>

					<Field>
						<Controller
							name="show_in_portal"
							control={control}
							render={({ field }) => (
								<Checkbox
									checked={field.value}
									onChange={field.onChange}
									error={!!errors.show_in_portal}
									label={t('bookingfrontend.show_in_portal')}
								/>
							)}
						/>
						{errors.show_in_portal && (
							<ValidationMessage>{errors.show_in_portal.message}</ValidationMessage>
						)}
					</Field>
				</Fieldset>

				{/* Action Buttons */}
				<div className={styles.actionButtons}>
					<Button
						type="button"
						variant="secondary"
						onClick={handleCancel}
						disabled={isSubmitting || isLoading}
					>
						{t('common.cancel')}
					</Button>
					<Button
						type="submit"
						disabled={isSubmitting || isLoading}
					>
						{isSubmitting || isLoading ? t('common.saving') : t('common.save')}
					</Button>
				</div>
			</form>
		</div>
	)
}

export default OrganizationEditForm