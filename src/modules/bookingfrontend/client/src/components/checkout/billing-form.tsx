import {FC, FormEvent, useEffect, useState, useMemo, useRef} from 'react';
import {useForm, Controller, FieldErrors} from "react-hook-form";
import {zodResolver} from "@hookform/resolvers/zod";
import {useQueries, useQueryClient} from "@tanstack/react-query";
import {
	Alert,
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
import {BrregOrganization, IOrganization} from "@/service/types/api/organization.types";
import Dialog from '@/components/dialog/mobile-dialog';
import BillingPartyCards, {BillingPartyOption} from './billing-party-cards';
import {maskSSN} from '@/utils/mask-ssn';

/** The fields that say who is billed. documentsRead is confirmed outside the billing section. */
const BILLING_FIELDS = [
	'customerType',
	'organizationId',
	'organizationNumber',
	'organizationName',
	'contactName',
	'contactEmail',
	'contactEmailConfirm',
	'contactPhone',
	'street',
	'zipCode',
	'city',
] as const;
type BillingValues = Pick<BillingFormData, typeof BILLING_FIELDS[number]>;

const MODAL_FORM_ID = 'billing-information-modal-form';
const PRIVATE_PARTY = 'private';
const CUSTOM_PARTY = 'custom';

/** The billing a citizen starts with: themselves, with the details from their profile */
const profileBillingValues = (user: IBookingUser): BillingFormData => ({
	customerType: 'ssn',
	contactName: user?.name || '',
	contactEmail: user?.email || '',
	contactEmailConfirm: user?.email || '',
	contactPhone: user?.phone || '',
	street: user?.street || '',
	zipCode: user?.zip_code || '',
	city: user?.city || '',
	documentsRead: false,
});

const pickBilling = (values: Partial<BillingFormData>): BillingValues =>
	Object.fromEntries(BILLING_FIELDS.map(key => [key, values[key]])) as BillingValues;

/** Unset, null and '' all mean «no value» (a private billing has no organisation either way) */
const sameBilling = (a: BillingValues, b: BillingValues): boolean =>
	BILLING_FIELDS.every(key => String(a[key] ?? '') === String(b[key] ?? ''));

/** Billing the citizen privately: no organisation, the profile's contact details and address */
const privateBillingValues = (profile: BillingFormData): BillingValues => ({
	customerType: 'ssn',
	organizationId: undefined,
	organizationNumber: '',
	organizationName: '',
	contactName: profile.contactName,
	contactEmail: profile.contactEmail,
	contactEmailConfirm: profile.contactEmailConfirm,
	contactPhone: profile.contactPhone,
	street: profile.street,
	zipCode: profile.zipCode,
	city: profile.city,
});

/**
 * Billing an organisation the citizen can act for: the same values as picking it in the organisation
 * select (name and postal address from the Brønnøysund lookup), with the citizen as the contact person.
 */
const organizationBillingValues = (org: IOrganization, lookup: BrregOrganization | undefined, profile: BillingFormData): BillingValues => ({
	customerType: 'organization_number',
	organizationId: org.id,
	organizationNumber: org.organization_number,
	organizationName: lookup ? lookup.navn : org.name,
	contactName: profile.contactName,
	contactEmail: profile.contactEmail,
	contactEmailConfirm: profile.contactEmailConfirm,
	contactPhone: profile.contactPhone,
	street: lookup?.postadresse?.adresse?.[0] || '',
	zipCode: lookup?.postadresse?.postnummer || '',
	city: lookup?.postadresse?.poststed || '',
});

const organizationLookupKey = (organizationNumber: string) => ['organizationLookup', organizationNumber];

/** The Brønnøysund lookup, failing when the organisation was not found (the endpoint answers with an error body) */
const lookupOrganization = async (organizationNumber: string): Promise<BrregOrganization> => {
	const data = await validateOrgNum(organizationNumber);
	if (!data?.navn) {
		throw new Error(`Organization ${organizationNumber} not found`);
	}
	return data;
};

interface BillingFormProps {
	/**
	 * The billing the parent holds. Given when the form is mounted again (the page shows a spinner while
	 * submitting, so a failed submit remounts it): the citizen's choice is restored, never the profile's.
	 */
	initialValues?: BillingFormData;
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
	submitting?: boolean;
	applications?: IApplication[];
	/** Why the applications cannot be submitted yet, shown by the submit buttons */
	submitError?: string;
}

type OrganizationOption = {
	value: string;
	label: string;
	orgId?: number;
};

const BillingForm: FC<BillingFormProps> = ({
											   initialValues,
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
											   submitting = false,
											   applications = [],
											   submitError
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
	const billingSchema = useMemo(() => createBillingFormSchema(t), [t]);

	/** The schema's complaints about a billing, leaving out the documents, which are confirmed outside it */
	const billingIssues = (values: Partial<BillingFormData>) => {
		const result = billingSchema.safeParse({...values, documentsRead: true});
		return result.success ? [] : result.error.issues;
	};

	// The parent's billing at the moment this form was mounted. Present only when the form is mounted
	// again within the same checkout; it is then the citizen's own choice and is restored as it was.
	const [restoredValues] = useState(initialValues);
	const restored = !!restoredValues;

	// Set by the first change the citizen makes to the billing (typing, a choice, a card, the dialog).
	// From then on the profile never writes the billing again, also when it is fetched again.
	const editedRef = useRef(restored);

	const {
		control,
		handleSubmit,
		formState: {errors},
		watch,
		setValue,
		getValues,
		trigger,
		clearErrors
	} = useForm<BillingFormData>({
		resolver: zodResolver(billingSchema),
		defaultValues: restoredValues ?? profileBillingValues(user)
	});

	// The billing is shown as cards when the profile alone makes a valid billing (the same schema as
	// submit); otherwise the form is shown as before, for the citizen to fill in.
	const [billingMode] = useState<'cards' | 'inline'>(() =>
		billingIssues(profileBillingValues(user)).length === 0 ? 'cards' : 'inline'
	);

	// The profile seeds the parent's billing on a fresh form. A profile fetched again later (it is
	// fetched on window focus) only replaces the billing while nobody has touched it, and then in the
	// form as well, so the form and the parent never disagree.
	const seededUserRef = useRef<IBookingUser | null>(null);
	useEffect(() => {
		if (restored || editedRef.current) {
			return;
		}
		const defaultValues = profileBillingValues(user);
		if (seededUserRef.current && seededUserRef.current !== user) {
			seededUserRef.current = user;
			const privateValues = privateBillingValues(defaultValues);
			BILLING_FIELDS.forEach(key => setValue(key, privateValues[key]));
			return;
		}
		seededUserRef.current = user;
		onBillingChange(defaultValues);
	}, [user, onBillingChange, restored, setValue]);


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
	// Billing privately: no organisation, and the address from the profile
	const resetToPrivate = () => {
		setValue('organizationId', undefined);
		setValue('organizationNumber', '');
		setValue('organizationName', '');
		setValue('street', user?.street || '');
		setValue('zipCode', user?.zip_code || '');
		setValue('city', user?.city || '');
	};

	// A fresh form starts as a private billing. Later switches to private are made by the billing type
	// radio itself, so restoring a billing (a remount, a cancelled dialog) or fetching the profile again
	// is never taken for a switch that resets the organisation and the address.
	useEffect(() => {
		if (!restored) {
			resetToPrivate();
		}
		// Runs on mount only, see above
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, []);

	// While the dialog is open the parent keeps the billing as it was; «Lagre» hands the edit over
	const [modalOpen, setModalOpen] = useState(false);
	const modalOpenRef = useRef(false);

	// Update parent component whenever values change
	useEffect(() => {
		const subscription = watch((value, {type}) => {
			if (type === 'change') {
				editedRef.current = true;
			}
			if (modalOpenRef.current) {
				return;
			}
			onBillingChange(value as BillingFormData);
		});
		return () => subscription.unsubscribe();
	}, [watch, onBillingChange]);

	// Sync documentsRead field with parent's areAllDocumentsChecked state
	useEffect(() => {
		const currentDocumentsRead = getValues('documentsRead');
		if (currentDocumentsRead !== areAllDocumentsChecked) {
			setValue('documentsRead', areAllDocumentsChecked, { shouldValidate: true });
		}
	}, [areAllDocumentsChecked, getValues, setValue]);


	// While the organisation's details are fetched, its address is not filled in yet: the dialog cannot be saved then
	const [orgLookupPending, setOrgLookupPending] = useState(false);

	const handleOrgChange = async (orgNumber: string) => {
		if (!orgNumber) return;

		setOrgLookupPending(true);
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
		} finally {
			setOrgLookupPending(false);
		}
	};

	const profileValues = useMemo(() => profileBillingValues(user), [user]);

	// The organisations the citizen can act for (the same list as the organisation select), each with
	// its Brønnøysund details once it has been picked
	const organizationLookups = useQueries({
		queries: (myOrganizations ?? []).map(org => ({
			queryKey: organizationLookupKey(org.organization_number),
			queryFn: () => lookupOrganization(org.organization_number),
			enabled: false,
			staleTime: Infinity,
			retry: false,
		})),
	});
	const queryClient = useQueryClient();

	const parties = useMemo(() => [
		{key: PRIVATE_PARTY, organization: undefined, values: privateBillingValues(profileValues) as BillingValues | undefined},
		...(myOrganizations ?? []).map((org, index) => {
			const lookup = organizationLookups[index]?.data;
			return {
				key: `organization-${org.id}`,
				organization: org,
				values: lookup ? organizationBillingValues(org, lookup, profileValues) : undefined,
			};
		}),
	], [myOrganizations, organizationLookups, profileValues]);

	// The last billing saved in the dialog that is not one of the parties above («Tilpasset»)
	const [customValues, setCustomValues] = useState<BillingValues | null>(null);

	// What the citizen has chosen. While the dialog is open that is the billing from before it was opened.
	const snapshotRef = useRef<{values: BillingValues; createdOrg: OrganizationOption | null} | null>(null);
	const formValues = watch();
	const committedValues = modalOpen && snapshotRef.current ? snapshotRef.current.values : pickBilling(formValues);
	const matchedParty = parties.find(party => party.values && sameBilling(party.values, committedValues));
	const selectedPartyKey = matchedParty ? matchedParty.key : CUSTOM_PARTY;
	const customPartyValues = matchedParty ? customValues : committedValues;

	const applyBilling = (values: BillingValues) => {
		BILLING_FIELDS.forEach(key => setValue(key, values[key]));
	};

	const editButtonRef = useRef<HTMLButtonElement>(null);
	const returnFocusRef = useRef(false);

	const openModal = (prefill?: BillingValues) => {
		snapshotRef.current = {values: pickBilling(getValues()), createdOrg};
		modalOpenRef.current = true;
		if (prefill) {
			applyBilling(prefill);
		}
		setModalOpen(true);
	};

	const closeModal = () => {
		modalOpenRef.current = false;
		snapshotRef.current = null;
		returnFocusRef.current = true;
		setModalOpen(false);
	};

	// «Avbryt», ✕ and Escape: the billing goes back to what it was when the dialog was opened
	const cancelModal = () => {
		const snapshot = snapshotRef.current;
		if (snapshot) {
			applyBilling(snapshot.values);
			setCreatedOrg(snapshot.createdOrg);
		}
		clearErrors([...BILLING_FIELDS]);
		closeModal();
	};

	// «Lagre»: validated with the submit schema, and only then handed to the parent
	const saveModal = async (event?: FormEvent) => {
		event?.preventDefault();
		if (orgLookupPending || isValidatingOrg) {
			return;
		}
		const valid = await trigger([...BILLING_FIELDS], {shouldFocus: true});
		if (!valid) {
			return;
		}
		const values = getValues();
		const billing = pickBilling(values);
		editedRef.current = true;
		if (!parties.some(party => party.values && sameBilling(party.values, billing))) {
			setCustomValues(billing);
		}
		closeModal();
		onBillingChange(values);
	};

	// Focus moves into the dialog (to the chosen billing type) when it opens, and back to «Rediger» when it closes
	useEffect(() => {
		if (modalOpen) {
			const frame = requestAnimationFrame(() => {
				document.getElementById(MODAL_FORM_ID)
					?.querySelector<HTMLInputElement>('input[name="customerType"]:checked')
					?.focus();
			});
			return () => cancelAnimationFrame(frame);
		}
		if (returnFocusRef.current) {
			returnFocusRef.current = false;
			editButtonRef.current?.focus();
		}
	}, [modalOpen]);

	// Picking a party card sets the whole billing from that party at once. An organisation is looked up
	// first; until then the billing stays as it was, and only the newest pick is applied.
	const latestPickRef = useRef<string | null>(null);
	const [pendingPartyKey, setPendingPartyKey] = useState<string | null>(null);

	const pickParty = async (key: string) => {
		if (key === (pendingPartyKey ?? selectedPartyKey)) {
			return;
		}
		latestPickRef.current = key;
		editedRef.current = true;
		if (key === CUSTOM_PARTY) {
			setPendingPartyKey(null);
			if (customValues) {
				applyBilling(customValues);
			}
			return;
		}
		const party = parties.find(candidate => candidate.key === key);
		if (!party) {
			return;
		}
		if (!party.organization) {
			setPendingPartyKey(null);
			applyBilling(privateBillingValues(profileValues));
			return;
		}
		const organization = party.organization;
		setPendingPartyKey(key);
		let lookup: BrregOrganization | undefined;
		try {
			lookup = await queryClient.fetchQuery({
				queryKey: organizationLookupKey(organization.organization_number),
				queryFn: () => lookupOrganization(organization.organization_number),
				staleTime: Infinity,
				retry: false,
			});
		} catch (error) {
			console.error('Failed to fetch organization details:', error);
		}
		if (latestPickRef.current !== key) {
			return;
		}
		setPendingPartyKey(null);
		const values = organizationBillingValues(organization, lookup, profileValues);
		// Not enough saved about the organisation (no lookup, no postal address): the citizen completes it in the dialog
		if (!lookup || billingIssues(values).length > 0) {
			openModal(values);
			return;
		}
		applyBilling(values);
	};

	const formatAddress = (values: BillingValues) => {
		const address = [values.street, [values.zipCode, values.city].filter(Boolean).join(' ')].filter(Boolean).join(', ');
		return address ? `${t('bookingfrontend.billing_address')}: ${address}` : null;
	};
	const formatContact = (values: BillingValues) => [values.contactEmail, values.contactPhone].filter(Boolean).join(' · ');

	const partyOptions: BillingPartyOption[] = [
		...parties.map(party => {
			if (!party.organization) {
				return {
					key: party.key,
					title: t('bookingfrontend.private_event'),
					lines: [
						profileValues.contactName,
						user?.ssn ? `${t('bookingfrontend.birth_number')}: ${maskSSN(user.ssn)}` : null,
						formatContact(profileValues),
						party.values && formatAddress(party.values),
					],
				};
			}
			const selected = party.key === selectedPartyKey;
			return {
				key: party.key,
				title: party.organization.name,
				lines: [
					`${t('bookingfrontend.organization_number')}: ${party.organization.organization_number}`,
					party.values && formatAddress(party.values),
					selected ? `${t('bookingfrontend.contact_person')}: ${profileValues.contactName}` : null,
				],
			};
		}),
		...(customPartyValues ? [{
			key: CUSTOM_PARTY,
			title: t('bookingfrontend.billing_party_custom'),
			lines: [
				customPartyValues.customerType === 'organization_number'
					? `${customPartyValues.organizationName ?? ''} (${customPartyValues.organizationNumber ?? ''})`
					: t('bookingfrontend.private_event'),
				customPartyValues.contactName,
				formatContact(customPartyValues),
				formatAddress(customPartyValues),
			],
		}] : []),
	];

	// Recurring applications with an organisation keep it when the applications are sent in
	// (ApplicationService::updateApplicationsWithContactInfo), whatever is chosen here
	const recurringOrganizationApplications = useMemo(() => applications.filter(app =>
		!!app.recurring_info && app.recurring_info.trim() !== '' && app.customer_identifier_type === 'organization_number'
	), [applications]);

	const recurringOrganizationLabel = (app: IApplication) => {
		const organizationNumber = app.customer_organization_number;
		const name = myOrganizations?.find(org => org.organization_number === organizationNumber)?.name
			?? app.customer_organization_name;
		return [name, organizationNumber && `(${organizationNumber})`].filter(Boolean).join(' ');
	};

	// A validation error on a billing field with the billing shown as cards: the fields are in the
	// dialog, so it is opened and the errors are shown there. Otherwise scroll to the first error.
	const showValidationErrors = (validationErrors: FieldErrors<BillingFormData>) => {
		if (billingMode === 'cards' && Object.keys(validationErrors).some(key => (BILLING_FIELDS as readonly string[]).includes(key))) {
			openModal();
			return;
		}
		setTimeout(() => {
			for (const key of Object.keys(validationErrors)) {
				const el = document.querySelector(`[name="${key}"]`)
					|| document.querySelector(`[data-field="${key}"]`);
				if (el) {
					el.scrollIntoView({ behavior: 'smooth', block: 'center' });
					return;
				}
			}
		}, 0);
	};


	// Custom submit handler that runs after form validation passes
	const submitForm = (data: BillingFormData) => {
		// Only called if form validation passes
		// Now handle document validation and proceed
		if (process.env.NODE_ENV === 'development') {
			console.log('🐛 BillingForm: submitForm called with data:', data);
			console.log('🐛 BillingForm: documentsValidated:', documentsValidated);
			console.log('🐛 BillingForm: about to call onSubmit()');
		}
		onSubmit();
	};

	// Handler for Vipps payment that validates form first
	const handleVippsPaymentClick = () => {
		// Trigger form validation and call Vipps payment if valid
		handleSubmit(
			(data) => {
				// Only called if form validation passes
				if (onVippsPayment && !vippsLoading) {
					onVippsPayment();
				}
			},
			showValidationErrors
		)();
	};

	const billingFields = (
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
							onChange={(event) => {
								field.onChange(event);
								resetToPrivate();
							}}
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
						<Field data-field="organizationNumber">
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
										label: `${org.organization_number} [${org.name}]`,
										orgId: org.id
									}))}
									loadOptions={loadOptions}
									onChange={(newValue) => {
										const selected = newValue as OrganizationOption | null;
										const value = selected?.value || '';
										field.onChange(value);
										setValue('organizationId', selected?.orgId);
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
												label: `${org.organization_number} [${org.name}]`,
												orgId: org.id
											})).find(option => option.value === field.value)
											// A restored billing can hold an organisation that is not in the list
											?? (field.value ? {value: field.value, label: `${field.value} [${getValues('organizationName') ?? ''}]`} : undefined)
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
	);

	return (
		<>
			<form
				onSubmit={(e) => {
					// Prevent default form submission
					e.preventDefault();
					if (process.env.NODE_ENV === 'development') {
						console.log('🐛 BillingForm: Form submit event triggered');
						console.log('🐛 BillingForm: Current form errors:', errors);
						console.log('🐛 BillingForm: Current form values:', getValues());
					}
					// Run form validation
					handleSubmit(
						submitForm,
						(validationErrors) => {
							if (process.env.NODE_ENV === 'development') {
								console.error('🐛 BillingForm: Form validation failed with errors:', validationErrors);
							}
							showValidationErrors(validationErrors);
						}
					)(e);
				}}
				className={styles.checkoutForm}>
				<h2>{t('bookingfrontend.billing_information')}</h2>

				{recurringOrganizationApplications.length > 0 && (
					<Alert data-color="info" className={styles.recurringBillingNote}>
						{recurringOrganizationApplications.map(app => (
							<Paragraph key={app.id}>
								{t('bookingfrontend.recurring_billing_note')
									.replace('%1', app.name)
									.replace('%2', recurringOrganizationLabel(app))}
							</Paragraph>
						))}
					</Alert>
				)}

				{billingMode === 'cards' ? (
					<BillingPartyCards
						options={partyOptions}
						selectedKey={selectedPartyKey}
						pendingKey={pendingPartyKey}
						onSelect={(key) => void pickParty(key)}
						onEdit={() => openModal()}
						editButtonRef={editButtonRef}
					/>
				) : billingFields}

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
						{(errors.documentsRead || (!areAllDocumentsChecked && showDocumentsError)) && (
							<ValidationMessage>
								{errors.documentsRead?.message || t('bookingfrontend.you_must_confirm_all_documents')}
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
					{submitError && <ValidationMessage>{submitError}</ValidationMessage>}
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
									loading={submitting}
									disabled={vippsLoading || submitting}
									className={styles.invoiceButton}
								>
									{t('bookingfrontend.pay_later_with_invoice')}
								</Button>
							</div>
							{normalApplications.length > 0 && directApplications.length > 0 &&
								paymentEligibility.payment_methods?.some(m => m.method.toLowerCase() === 'vipps') && (
								<Paragraph className={styles.mixedBookingNote}>
									{t('bookingfrontend.vipps_only_for_direct_bookings')}
								</Paragraph>
							)}
						</>
					) : (
						/* Show single submit button when no Vipps eligibility */
						<Button
							variant="primary"
							type="submit"
							loading={submitting}
							disabled={submitting}
						>
							{t('bookingfrontend.submit_application')}
						</Button>
					)}
				</div>
			</form>

			{/* Outside the form above, so Enter in the dialog saves the dialog and never sends the applications */}
			{billingMode === 'cards' && (
				<Dialog
					open={modalOpen}
					onClose={cancelModal}
					dialogId="billing-information-modal"
					title={<Heading level={2} data-size="xs">{t('bookingfrontend.edit_billing_information')}</Heading>}
					stickyFooter
					footer={(attemptClose) => (
						<div className={styles.billingModalFooter}>
							<Button type="button" variant="tertiary" onClick={attemptClose}>
								{t('common.cancel')}
							</Button>
							<Button
								type="submit"
								form={MODAL_FORM_ID}
								variant="primary"
								disabled={orgLookupPending || isValidatingOrg}
							>
								{t('common.save')}
							</Button>
						</div>
					)}
				>
					<form id={MODAL_FORM_ID} onSubmit={saveModal} noValidate>
						{billingFields}
					</form>
				</Dialog>
			)}
		</>
	);
};

export default BillingForm;