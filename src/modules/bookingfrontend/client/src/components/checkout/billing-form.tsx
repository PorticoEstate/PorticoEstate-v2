import {FC, useEffect, useState, useMemo} from 'react';
import {useForm, Controller} from "react-hook-form";
import {zodResolver} from "@hookform/resolvers/zod";
import {
	Button,
	Field,
	Fieldset, Heading,
	Label, Paragraph,
	Radio,
	Select,
	Textfield,
	ValidationMessage
} from "@digdir/designsystemet-react";
import {useClientTranslation, useTrans} from "@/app/i18n/ClientTranslationProvider";
import styles from './checkout.module.scss';
import {BillingFormData, createBillingFormSchema} from "@/components/checkout/billing-form-schema";
import {IBookingUser} from "@/service/types/api.types";
import {useMyOrganizations} from "@/service/hooks/organization";
import {searchOrganizations, validateOrgNum} from "@/service/api/api-utils";
import AsyncCreatableSelect from "react-select/async-creatable";
import RegulationDocuments from './regulation-documents';
import {ExternalPaymentEligibilityResponse} from "@/service/api/api-utils";
import VippsCheckoutButton from './VippsCheckoutButton';
import {IApplication} from "@/service/types/api/application.types";

interface BillingFormProps {
	onBillingChange: (data: BillingFormData) => void;
	onSubmit: () => void;
	onVippsPayment?: () => void;
	paymentEligibility?: ExternalPaymentEligibilityResponse;
	user: IBookingUser;
	documentsValidated?: boolean;
	documentsSectionRef?: React.RefObject<HTMLDivElement>;
	showDocumentsSection?: boolean;
	documents?: any[];
	checkedDocuments?: Record<number, boolean>;
	onDocumentCheck?: (documentId: number, checked: boolean) => void;
	areAllDocumentsChecked?: boolean;
	showDocumentsError?: boolean;
	vippsLoading?: boolean;
	applications?: IApplication[];
}

type OrganizationOption = {
	value: string;
	label: string;
};

const BillingForm: FC<BillingFormProps> = ({
											   onBillingChange,
											   onSubmit,
											   onVippsPayment,
											   paymentEligibility,
											   user,
											   documentsValidated = true,
											   documentsSectionRef,
											   showDocumentsSection = false,
											   documents = [],
											   checkedDocuments = {},
											   onDocumentCheck = () => {
											   },
											   areAllDocumentsChecked = true,
											   showDocumentsError = false,
											   vippsLoading = false,
											   applications = []
										   }) => {
	const t = useTrans();
	const {data: myOrganizations, isLoading: orgLoading} = useMyOrganizations();
	const {i18n} = useClientTranslation()
	// Determine language for Vipps button (en or fallback to no)
	const vippsLanguage = i18n.language === 'en' ? 'en' : 'no';

	// State for validation loading
	const [isValidatingOrg, setIsValidatingOrg] = useState(false);

	// Validate Norwegian organization number format (9 digits)
	const validateOrgNumberFormat = (orgNum: string): boolean => {
		// Remove spaces and check if it's exactly 9 digits
		const cleaned = orgNum.replace(/\s/g, '');
		return /^\d{9}$/.test(cleaned);
	};

	// State to store the created organization for display
	const [createdOrg, setCreatedOrg] = useState<OrganizationOption | null>(null);

	// State to store resolved org name for preview
	const [resolvedOrgName, setResolvedOrgName] = useState<string>('');

	// Handle when user creates a new organization number
	const handleCreateOrganization = async (inputValue: string) => {
		const cleaned = inputValue.replace(/\s/g, '');

		// First validate format
		if (!validateOrgNumberFormat(cleaned)) {
			alert(t('bookingfrontend.invalid_org_number_format'));
			return;
		}

		setIsValidatingOrg(true);

		try {
			const data = await validateOrgNum(cleaned);

			// Create the option object
			const newOption: OrganizationOption = {
				value: cleaned,
				label: `${cleaned} [${data.navn}]`
			};

			// Set the form values with the validated org data
			setValue('organizationNumber', cleaned);
			setValue('organizationName', data.navn);
			setValue('street', data.postadresse?.adresse?.[0] || '');
			setValue('zipCode', data.postadresse?.postnummer || '');
			setValue('city', data.postadresse?.poststed || '');

			// Store the created org so it shows as selected
			setCreatedOrg(newOption);
		} catch (error) {
			console.error('Failed to validate organization:', error);
			alert(t('bookingfrontend.org_not_found'));
		} finally {
			setIsValidatingOrg(false);
		}
	};

	// Separate applications by type and calculate totals
	const {normalApplications, directApplications, normalTotal, directTotal} = useMemo(() => {
		const normal: any[] = [];
		const direct: any[] = [];
		let normalSum = 0;
		let directSum = 0;

		const currentUnixTime = Math.floor(Date.now() / 1000);

		applications.forEach((app) => {
			let isDirectBooking = false;
			if (app.resources && Array.isArray(app.resources)) {
				isDirectBooking = app.resources.some((resource) => {
					if (!resource.direct_booking) {
						return false;
					}
					// Check if current time is past the direct booking start time
					return currentUnixTime > resource.direct_booking;
				});
			}

			// Calculate application total
			let appTotal = 0;
			if (app.orders && Array.isArray(app.orders)) {
				appTotal = app.orders.reduce((sum: number, order: any) => sum + (parseFloat(order.sum) || 0), 0);
			}

			if (isDirectBooking) {
				direct.push({ ...app, total: appTotal });
				directSum += appTotal;
			} else {
				normal.push({ ...app, total: appTotal });
				normalSum += appTotal;
			}
		});

		return {
			normalApplications: normal,
			directApplications: direct,
			normalTotal: normalSum,
			directTotal: directSum
		};
	}, [applications]);
	const {
		control,
		handleSubmit,
		formState: {errors},
		watch,
		setValue,
		getValues
	} = useForm<BillingFormData>({
		resolver: zodResolver(createBillingFormSchema(t)),
		defaultValues: {
			customerType: 'ssn',
			contactName: user?.name || '',
			contactEmail: user?.email || '',
			contactEmailConfirm: user?.email || '',
			contactPhone: user?.phone || '',
			street: user?.street || '',
			zipCode: user?.zip_code || '',
			city: user?.city || '',
			documentsRead: false,
		}
	});
	useEffect(() => {
		const defaultValues: BillingFormData = {
			customerType: 'ssn',
			contactName: user?.name || '',
			contactEmail: user?.email || '',
			contactEmailConfirm: user?.email || '',
			contactPhone: user?.phone || '',
			street: user?.street || '',
			zipCode: user?.zip_code || '',
			city: user?.city || '',
			documentsRead: false,
		};
		onBillingChange(defaultValues);
	}, [user, onBillingChange]);

	// Effect to refill blank fields when user data refreshes
	useEffect(() => {
		const currentValues = getValues();
		const customerType = currentValues.customerType;
		
		// Only refill fields if they are currently blank and user has the data
		// Also only refill personal fields when customerType is 'ssn' (private)
		if (customerType === 'ssn') {
			if (!currentValues.contactName && user?.name) {
				setValue('contactName', user.name);
			}
			if (!currentValues.contactEmail && user?.email) {
				setValue('contactEmail', user.email);
				setValue('contactEmailConfirm', user.email);
			}
			if (!currentValues.contactPhone && user?.phone) {
				setValue('contactPhone', user.phone);
			}
			if (!currentValues.street && user?.street) {
				setValue('street', user.street);
			}
			if (!currentValues.zipCode && user?.zip_code) {
				setValue('zipCode', user.zip_code);
			}
			if (!currentValues.city && user?.city) {
				setValue('city', user.city);
			}
		}
	}, [user, setValue, getValues]);


	const customerType = watch('customerType');
	const selectedOrg = watch('organizationNumber');


	const loadOptions = async (inputValue: string): Promise<OrganizationOption[]> => {
		if (inputValue.length < 2) {
			return [];
		}

		// If user typed a valid org number, try to resolve it from Brreg
		const cleaned = inputValue.replace(/\s/g, '');
		if (validateOrgNumberFormat(cleaned)) {
			try {
				const data = await validateOrgNum(cleaned);
				setResolvedOrgName(data.navn);
			} catch (error) {
				setResolvedOrgName('');
			}
		} else {
			setResolvedOrgName('');
		}

		try {
			const organizations = await searchOrganizations(inputValue);
			return organizations.map(org => ({
				value: org.organization_number,
				label: `${org.organization_number} [${org.name}]`
			}));
		} catch (error) {
			console.error('Error loading organizations:', error);
			return [];
		}
	};
	// Watch customerType changes
	useEffect(() => {
		// If switching to private (ssn)
		if (customerType === 'ssn') {
			// Reset org-related fields
			setValue('organizationNumber', '');
			setValue('organizationName', '');
			// Reset address fields to user's default values
			setValue('street', user?.street || '');
			setValue('zipCode', user?.zip_code || '');
			setValue('city', user?.city || '');
		}
	}, [customerType, setValue, user]);

	// Update parent component whenever values change
	useEffect(() => {
		const subscription = watch((value) => onBillingChange(value as BillingFormData));
		return () => subscription.unsubscribe();
	}, [watch, onBillingChange]);

	// Sync documentsRead field with parent's areAllDocumentsChecked state
	useEffect(() => {
		const currentDocumentsRead = getValues('documentsRead');
		if (currentDocumentsRead !== areAllDocumentsChecked) {
			setValue('documentsRead', areAllDocumentsChecked, { shouldValidate: true });
		}
	}, [areAllDocumentsChecked, getValues, setValue]);


	const handleOrgChange = async (orgNumber: string) => {
		if (!orgNumber) return;

		try {
			const data = await validateOrgNum(orgNumber);
			// const data = await response.json();

			setValue('organizationName', data.navn);
			setValue('street', data.postadresse?.adresse?.[0] || '');
			setValue('zipCode', data.postadresse?.postnummer || '');
			setValue('city', data.postadresse?.poststed || '');
			// console.log('organizationName', data.navn);
			// console.log('street', data.postadresse.adresse[0]);
			// console.log('zipCode', data.postadresse.postnummer);
			// console.log('city', data.postadresse.poststed);
		} catch (error) {
			console.error('Failed to fetch organization details:', error);
		}
	};


	// Custom submit handler that runs after form validation passes
	const submitForm = (data: BillingFormData) => {
		// Only called if form validation passes
		// Now handle document validation and proceed
		onSubmit();
	};

	// Handler for Vipps payment that validates form first
	const handleVippsPaymentClick = () => {
		// Trigger form validation and call Vipps payment if valid
		handleSubmit((data) => {
			// Only called if form validation passes
			if (onVippsPayment && !vippsLoading) {
				onVippsPayment();
			}
		})();
	};

	return (
		<>
			<form
				onSubmit={(e) => {
					// Prevent default form submission
					e.preventDefault();
					// Run form validation
					handleSubmit(submitForm)(e);
				}}
				className={styles.checkoutForm}>
				<h2>{t('bookingfrontend.billing_information')}</h2>

				<div className={styles.formFields}>
					<Controller
						name="customerType"
						control={control}
						render={({field}) => (
							<Fieldset>
								<Fieldset.Legend>
									{t('bookingfrontend.billing_information')}
								</Fieldset.Legend>
								<Fieldset.Description>
									{t('bookingfrontend.choose_a')}
								</Fieldset.Description>
								<Radio
									name={field.name}
									value="ssn"
									checked={field.value === 'ssn'}
									onChange={field.onChange}
									label={t('bookingfrontend.private_event')}
								/>
								<Radio
									name={field.name}
									value="organization_number"
									checked={field.value === 'organization_number'}
									onChange={field.onChange}
									label={t('bookingfrontend.organization')}
								/>
								{errors.customerType && (
									<ValidationMessage>
										{errors.customerType.message}
									</ValidationMessage>
								)}
							</Fieldset>
						)}
					/>

					{customerType === 'organization_number' && (
						<Controller
							name="organizationNumber"
							control={control}
							render={({field}) => (
								<Field>
									<Label>
										{t('bookingfrontend.organization')} <span className="required-asterisk">*</span>
									</Label>
									<AsyncCreatableSelect
										onCreateOption={handleCreateOrganization}
										isDisabled={isValidatingOrg}
										isValidNewOption={(inputValue) => validateOrgNumberFormat(inputValue)}
										formatCreateLabel={(inputValue) => {
											const displayText = resolvedOrgName || inputValue;
											return `${t('bookingfrontend.add_organization')}: ${displayText}`;
										}}
										cacheOptions
											defaultOptions={myOrganizations?.map(org => ({
												value: org.organization_number,
												label: `${org.organization_number} [${org.name}]`
											}))}
											loadOptions={loadOptions}
											onChange={(newValue) => {
												const value = (newValue as OrganizationOption)?.value || '';
												field.onChange(value);
												void handleOrgChange(value);
												// Clear created org when user selects a different option
												if (createdOrg && value !== createdOrg.value) {
													setCreatedOrg(null);
												}
											}}
											value={
												createdOrg && field.value === createdOrg.value
													? createdOrg
													: myOrganizations?.map(org => ({
														value: org.organization_number,
														label: `${org.organization_number} [${org.name}]`
													})).find(option => option.value === field.value)
											}
											isClearable
											placeholder={t('bookingfrontend.select_organization')}
											classNamePrefix="react-select"
											styles={{
												container: (base) => ({
													...base,
													width: '100%'
												}),
												control: (base) => ({
													...base,
													minHeight: '48px',
													borderRadius: '4px',
													borderColor: errors.organizationNumber ? '#c30000' : '#ccc',
													width: '100%'
												}),
												menu: (base) => ({
													...base,
													zIndex: 2
												})
											}}
										/>
										<Paragraph style={{ marginTop: '0.5rem', fontSize: '0.875rem', color: '#666' }}>
											{t('bookingfrontend.org_create_hint')}
										</Paragraph>
										{errors.organizationNumber && (
											<ValidationMessage>
												{errors.organizationNumber.message}
											</ValidationMessage>
										)}
									</Field>
								)}
							/>
					)}

					<Controller
						name="contactName"
						control={control}
						render={({field}) => (
							<Textfield
								label={t('bookingfrontend.contact_name')}
								{...field}
								error={errors.contactName?.message as string}
								required
							/>
						)}
					/>

					<Controller
						name="contactEmail"
						control={control}
						render={({field}) => (
							<Textfield
								label={t('bookingfrontend.contact_email')}
								type="email"
								{...field}
								error={errors.contactEmail?.message as string}
								required
							/>
						)}
					/>

					<Controller
						name="contactEmailConfirm"
						control={control}
						render={({field}) => (
							<Textfield
								label={t('bookingfrontend.confirm_email')}
								type="email"
								{...field}
								error={errors.contactEmailConfirm?.message as string}
								required
							/>
						)}
					/>

					<Controller
						name="contactPhone"
						control={control}
						render={({field}) => (
							<Textfield
								label={t('bookingfrontend.contact_phone')}
								type="tel"
								{...field}
								error={errors.contactPhone?.message as string}
								required
							/>
						)}
					/>

					<Controller
						name="street"
						control={control}
						render={({field}) => (
							<Textfield
								label={t('bookingfrontend.responsible_street')}
								{...field}
								error={errors.street?.message as string}
								required
							/>
						)}
					/>

					<Controller
						name="zipCode"
						control={control}
						render={({field}) => (
							<Textfield
								label={t('bookingfrontend.responsible_zip_code')}
								{...field}
								error={errors.zipCode?.message as string}
								required
							/>
						)}
					/>

					<Controller
						name="city"
						control={control}
						render={({field}) => (
							<Textfield
								label={t('bookingfrontend.responsible_city')}
								{...field}
								error={errors.city?.message as string}
								required
							/>
						)}
					/>
				</div>

				{/* Display regulation documents if any are found */}
				{showDocumentsSection && documents.length > 0 && (
					<div ref={documentsSectionRef} className={styles.documentsInBilling}>
						<RegulationDocuments
							documents={documents}
							checkedDocuments={checkedDocuments}
							onDocumentCheck={onDocumentCheck}
							areAllChecked={areAllDocumentsChecked}
							showError={showDocumentsError || !!errors.documentsRead}
						/>
						{(!areAllDocumentsChecked && showDocumentsError) && (
							<ValidationMessage>
								{t('bookingfrontend.you_must_confirm_all_documents')}
							</ValidationMessage>
						)}
					</div>
				)}

				{/* Summary Section - Always show */}
				{/*<div className={styles.checkoutSummary}>*/}
				{/*	<Heading data-size={'md'} level={3}>{t('bookingfrontend.summary')}</Heading>*/}

				{/*	/!* Normal Applications Section - Show if there are normal applications *!/*/}
				{/*	{normalApplications.length > 0 && (*/}
				{/*		<div className={styles.summarySection}>*/}
				{/*			<Heading data-size={'sm'} level={4}>{t('bookingfrontend.applications')}</Heading>*/}
				{/*			<Paragraph className={styles.sectionSubtext}>{t('bookingfrontend.payment_if_approved')}</Paragraph>*/}

				{/*			{normalApplications.map((app, index) => (*/}
				{/*				<div key={app.id || index} className={styles.summaryItem}>*/}
				{/*					<div className={styles.itemDetails}>*/}
				{/*						<span className={styles.itemLabel}>*/}
				{/*							{app.activity || app.event_name || app.name || app.title || t('bookingfrontend.application_title')}*/}
				{/*						</span>*/}
				{/*						<div className={styles.itemRight}>*/}
				{/*							/!*<span className={styles.itemBadge}>{t('bookingfrontend.application')}</span>*!/*/}
				{/*							{app.total > 0 && (*/}
				{/*								<span className={styles.itemCost}>*/}
				{/*									{new Intl.NumberFormat("nb-NO", { style: "currency", currency: "NOK" }).format(app.total)}*/}
				{/*								</span>*/}
				{/*							)}*/}
				{/*						</div>*/}
				{/*					</div>*/}
				{/*				</div>*/}
				{/*			))}*/}

				{/*			{normalTotal > 0 && (*/}
				{/*				<div className={styles.summaryTotal}>*/}
				{/*					<span className={styles.totalLabel}>{t('bookingfrontend.total_incl_vat')}:</span>*/}
				{/*					<span className={styles.totalAmount}>{new Intl.NumberFormat("nb-NO", { style: "currency", currency: "NOK" }).format(normalTotal)}</span>*/}
				{/*				</div>*/}
				{/*			)}*/}
				{/*		</div>*/}
				{/*	)}*/}

				{/*	/!* Direct Applications Section - Show if there are direct applications *!/*/}
				{/*	{directApplications.length > 0 && (*/}
				{/*		<div className={styles.summarySection}>*/}
				{/*			<Heading data-size={'sm'} level={4}>{t('bookingfrontend.pay_now_or_later')}</Heading>*/}

				{/*			{directApplications.map((app, index) => (*/}
				{/*				<div key={app.id || index} className={styles.summaryItem}>*/}
				{/*					<div className={styles.itemDetails}>*/}
				{/*						<span className={styles.itemLabel}>*/}
				{/*							{app.activity || app.event_name || app.name || app.title || t('bookingfrontend.direct_booking_title')}:*/}
				{/*						</span>*/}
				{/*						<div className={styles.itemRight}>*/}
				{/*							/!*<span className={styles.itemBadge}>{t('bookingfrontend.direct_booking')}</span>*!/*/}
				{/*							{app.total > 0 && (*/}
				{/*								<span className={styles.itemCost}>*/}
				{/*									{new Intl.NumberFormat("nb-NO", { style: "currency", currency: "NOK" }).format(app.total)}*/}
				{/*								</span>*/}
				{/*							)}*/}
				{/*						</div>*/}
				{/*					</div>*/}
				{/*				</div>*/}
				{/*			))}*/}

				{/*			{directTotal > 0 && (*/}
				{/*				<div className={styles.summaryTotal}>*/}
				{/*					<span className={styles.totalLabel}>{t('bookingfrontend.total_incl_vat')}:</span>*/}
				{/*					<span className={styles.totalAmount}>{new Intl.NumberFormat("nb-NO", { style: "currency", currency: "NOK" }).format(directTotal)}</span>*/}
				{/*				</div>*/}
				{/*			)}*/}
				{/*		</div>*/}
				{/*	)}*/}
				{/*</div>*/}

				<div className={styles.submitSection}>
					{/* Show two options when user has both Vipps and normal applications */}
					{paymentEligibility?.eligible ? (
						<>
							<div className={styles.checkoutButtons}>
								{/* Vipps Payment Button */}
								{paymentEligibility.payment_methods?.map((method) => {
									if (method.method.toLowerCase() === 'vipps') {
										return (
											<VippsCheckoutButton
												key={method.method}
												type="button"
												onClick={handleVippsPaymentClick}
												brand="vipps"
												language={vippsLanguage}
												variant="primary"
												rounded={false}
												verb="continue"
												stretched={false}
												branded={true}
												loading={vippsLoading}
												disabled={vippsLoading}
											/>
										);
									}
									return null;
								})}

								{/* Invoice Payment Button */}
								<Button
									type="submit"
									disabled={vippsLoading}
									className={styles.invoiceButton}
								>
									{t('bookingfrontend.pay_later_with_invoice')}
								</Button>
							</div>
						</>
					) : (
						/* Show single submit button when no Vipps eligibility */
						<Button
							variant="primary"
							type="submit"
						>
							{t('bookingfrontend.submit_application')}
						</Button>
					)}
				</div>
			</form>
		</>
	);
};

export default BillingForm;