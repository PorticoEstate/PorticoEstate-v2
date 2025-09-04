'use client'
import {useForm, useFieldArray, Controller} from 'react-hook-form'
import {zodResolver} from '@hookform/resolvers/zod'
import {z} from 'zod'
import {Button, Textfield, Textarea, Checkbox, Field, Label, ValidationMessage, Select} from '@digdir/designsystemet-react'
import {useTrans} from '@/app/i18n/ClientTranslationProvider'
import {IOrganizationGroup, IShortOrganizationDelegate} from '@/service/types/api/organization.types'
import {useOrganizationDelegates} from '@/service/hooks/organization'
import {PlusIcon, TrashIcon} from '@navikt/aksel-icons'
import styles from './group-form.module.scss'

const contactSchema = z.object({
	name: z.string().min(1, 'Contact name is required'),
	email: z.string().optional().refine((val) => !val || val === '' || z.string().email().safeParse(val).success, {
		message: 'Must be a valid email'
	}),
	phone: z.string().optional().refine((val) => !val || val === '' || /^[\+]?[0-9\s\-\(\)]{8,15}$/.test(val), {
		message: 'Invalid phone number format'
	})
})

const groupSchema = z.object({
	name: z.string().min(1, 'Group name is required'),
	shortname: z.string().optional(),
	description: z.string().optional(),
	show_in_portal: z.boolean(),
	contacts: z.array(contactSchema).max(2, 'Maximum 2 contacts allowed')
})

const groupEditSchema = groupSchema

export type GroupFormData = z.infer<typeof groupSchema>
export type GroupEditFormData = z.infer<typeof groupEditSchema>

export interface GroupFormProps {
	group?: IOrganizationGroup
	organizationId: string | number
	onSubmit: (data: GroupFormData | GroupEditFormData) => void
	onCancel: () => void
	isSubmitting: boolean
	isEdit?: boolean
	hideActions?: boolean
	formId?: string
}

const GroupForm = ({group, organizationId, onSubmit, onCancel, isSubmitting, isEdit = false, hideActions = false, formId}: GroupFormProps) => {
	const t = useTrans()
	const {data: delegates} = useOrganizationDelegates(organizationId)
	
	const schema = isEdit ? groupEditSchema : groupSchema

	const {
		register,
		handleSubmit,
		control,
		setValue,
		formState: { errors }
	} = useForm<any>({
		resolver: zodResolver(schema),
		defaultValues: {
			name: group?.name || '',
			shortname: group?.shortname || '',
			description: group?.description || '',
			show_in_portal: group?.show_in_portal === 1,
			contacts: group?.contacts?.map(contact => ({
				name: contact.name,
				email: contact.email || '',
				phone: contact.phone || ''
			})) || []
		}
	})

	const { fields, append, remove } = useFieldArray({
		control,
		name: 'contacts'
	})

	const handleFormSubmit = (data: GroupFormData | GroupEditFormData) => {
		const cleanedData = {
			...data,
			shortname: data.shortname?.trim() || undefined,
			description: data.description?.trim() || undefined,
			contacts: data.contacts?.map(contact => ({
				...contact,
				email: contact.email?.trim() || undefined,
				phone: contact.phone?.trim() || undefined
			})).filter(contact => contact.name.trim())
		}
		onSubmit(cleanedData)
	}

	const addContact = () => {
		if (fields.length < 2) {
			append({ name: '', email: '', phone: '' })
		}
	}

	const removeContact = (index: number) => {
		remove(index)
	}

	const fillContactFromDelegate = (index: number, delegateId: string) => {
		if (!delegateId || !delegates) return
		
		const delegate = delegates.find(d => d.id === parseInt(delegateId))
		if (!delegate) return

		// Update the contact fields with delegate information
		setValue(`contacts.${index}.name`, delegate.name)
		setValue(`contacts.${index}.email`, delegate.email || '')
		setValue(`contacts.${index}.phone`, delegate.phone || '')
	}

	return (
		<div className={styles.groupForm}>
			<form onSubmit={handleSubmit(handleFormSubmit)} id={formId}>
				<Field>
					<Label htmlFor="name" id="name-label">{t('bookingfrontend.group_name')} *</Label>
					<Textfield
						{...register('name')}
						id="name"
						aria-labelledby="name-label"
						error={!!errors.name}
						disabled={isSubmitting}
						maxLength={150}
					/>
					{errors.name && (
						<ValidationMessage>{String(errors.name.message)}</ValidationMessage>
					)}
				</Field>

				<Field>
					<Label htmlFor="shortname" id="shortname-label">{t('bookingfrontend.group_shortname')}</Label>
					<Textfield
						{...register('shortname')}
						id="shortname"
						aria-labelledby="shortname-label"
						error={!!errors.shortname}
						disabled={isSubmitting}
						maxLength={11}
					/>
					{errors.shortname && (
						<ValidationMessage>{String(errors.shortname?.message)}</ValidationMessage>
					)}
				</Field>

				<Field>
					<Label htmlFor="description" id="description-label">{t('bookingfrontend.description')}</Label>
					<Textarea
						{...register('description')}
						id="description"
						aria-labelledby="description-label"
						rows={3}
						disabled={isSubmitting}
					/>
					{errors.description && (
						<ValidationMessage>{String(errors.description?.message)}</ValidationMessage>
					)}
				</Field>

				<Field>
					<Controller
						name="show_in_portal"
						control={control}
						render={({ field }) => (
							<Checkbox
								{...field}
								checked={field.value}
								onChange={(e) => field.onChange(e.target.checked)}
								disabled={isSubmitting}
								label={t('bookingfrontend.show_in_portal')}
							/>
						)}
					/>
				</Field>


				<div className={styles.contactsSection}>
					<div className={styles.contactsHeader}>
						<h4>{t('bookingfrontend.group_contacts')}</h4>
						{fields.length < 2 && (
							<Button
								type="button"
								variant="tertiary"
								data-size="sm"
								onClick={addContact}
								disabled={isSubmitting}
							>
								<PlusIcon />
								{t('bookingfrontend.add_contact')}
							</Button>
						)}
					</div>

					{errors.contacts && typeof errors.contacts === 'object' && 'message' in errors.contacts && (
						<ValidationMessage>{String(errors.contacts.message)}</ValidationMessage>
					)}

					<div className={styles.contactsContainer}>
						{fields.map((field, index) => (
						<div key={field.id} className={styles.contactForm}>
							<div className={styles.contactHeader}>
								<h5>{t('bookingfrontend.contact')} {index + 1}</h5>
								<Button
									type="button"
									variant="tertiary"
									color="danger"
									data-size="sm"
										onClick={() => removeContact(index)}
									disabled={isSubmitting}
								>
									<TrashIcon />
								</Button>
							</div>

							{delegates && delegates.length > 0 && (
								<Field>
									<Label htmlFor={`delegate-select-${index}`}>
										{t('bookingfrontend.fill_from_delegate')}
									</Label>
									<Select
										id={`delegate-select-${index}`}
										disabled={isSubmitting}
										onChange={(e) => fillContactFromDelegate(index, e.target.value)}
									>
										<option value="">{t('bookingfrontend.select_delegate')}</option>
										{delegates.filter(d => d.active).map(delegate => (
											<option key={delegate.id} value={delegate.id}>
												{delegate.name} {delegate.email ? `(${delegate.email})` : ''}
											</option>
										))}
									</Select>
								</Field>
							)}

							<Field>
								<Label htmlFor={`contacts.${index}.name`} id={`contact-${index}-name-label`}>
									{t('bookingfrontend.contact_name')} *
								</Label>
								<Textfield
									{...register(`contacts.${index}.name` as const)}
									id={`contacts.${index}.name`}
									aria-labelledby={`contact-${index}-name-label`}
									error={!!(errors.contacts as any)?.[index]?.name}
									disabled={isSubmitting}
								/>
								{(errors.contacts as any)?.[index]?.name && (
									<ValidationMessage>
										{String((errors.contacts as any)[index].name.message)}
									</ValidationMessage>
								)}
							</Field>

							<Field>
								<Label htmlFor={`contacts.${index}.email`} id={`contact-${index}-email-label`}>
									{t('common.email')}
								</Label>
								<Textfield
									{...register(`contacts.${index}.email` as const)}
									id={`contacts.${index}.email`}
									aria-labelledby={`contact-${index}-email-label`}
									type="email"
									error={!!(errors.contacts as any)?.[index]?.email}
									disabled={isSubmitting}
								/>
								{(errors.contacts as any)?.[index]?.email && (
									<ValidationMessage>
										{String((errors.contacts as any)[index].email.message)}
									</ValidationMessage>
								)}
							</Field>

							<Field>
								<Label htmlFor={`contacts.${index}.phone`} id={`contact-${index}-phone-label`}>
									{t('bookingfrontend.phone')}
								</Label>
								<Textfield
									{...register(`contacts.${index}.phone` as const)}
									id={`contacts.${index}.phone`}
									aria-labelledby={`contact-${index}-phone-label`}
									type="tel"
									error={!!(errors.contacts as any)?.[index]?.phone}
									disabled={isSubmitting}
								/>
								{(errors.contacts as any)?.[index]?.phone && (
									<ValidationMessage>
										{String((errors.contacts as any)[index].phone.message)}
									</ValidationMessage>
								)}
							</Field>
						</div>
					))}
				</div>
			</div>

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

export default GroupForm