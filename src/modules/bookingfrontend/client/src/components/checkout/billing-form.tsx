import {FC, useEffect, useState} from 'react';
import {useForm, Controller} from "react-hook-form";
import {zodResolver} from "@hookform/resolvers/zod";
import {
    Button,
    Field,
    Fieldset,
    Label,
    Radio,
    Select,
    Textfield,
    ValidationMessage
} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import styles from './checkout.module.scss';
import {BillingFormData, billingFormSchema} from "@/components/checkout/billing-form-schema";
import {IBookingUser} from "@/service/types/api.types";
import {useMyOrganizations} from "@/service/hooks/organization";
import {searchOrganizations, validateOrgNum} from "@/service/api/api-utils";
import AsyncSelect from "react-select/async";

interface BillingFormProps {
    onBillingChange: (data: BillingFormData) => void;
    onSubmit: () => void;
    user: IBookingUser;
}

type OrganizationOption = {
    value: string;
    label: string;
};

const BillingForm: FC<BillingFormProps> = ({onBillingChange, onSubmit, user}) => {
    const t = useTrans();
    const {data: myOrganizations, isLoading: orgLoading} = useMyOrganizations();
    const {
        control,
        handleSubmit,
        formState: {errors},
        watch,
        setValue
    } = useForm<BillingFormData>({
        resolver: zodResolver(billingFormSchema),
        defaultValues: {
            customerType: 'ssn',
            contactName: user?.name || '',
            contactEmail: user?.email || '',
            contactEmailConfirm: user?.email || '',
            contactPhone: user?.phone || '',
            street: user?.street || '',
            zipCode: user?.zip_code || '',
            city: user?.city || '',
        }
    });

    const customerType = watch('customerType');
    const selectedOrg = watch('organizationNumber');


    const loadOptions = async (inputValue: string): Promise<OrganizationOption[]> => {
        if (inputValue.length < 2) {
            return [];
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


    const handleOrgChange = async (orgNumber: string) => {
        if (!orgNumber) return;

        try {
            const data = await validateOrgNum(orgNumber);
            // const data = await response.json();

            setValue('organizationName', data.navn);
            setValue('street', data.postadresse.adresse[0]);
            setValue('zipCode', data.postadresse.postnummer);
            setValue('city', data.postadresse.poststed);
            console.log('organizationName', data.navn);
            console.log('street', data.postadresse.adresse[0]);
            console.log('zipCode', data.postadresse.postnummer);
            console.log('city', data.postadresse.poststed);
        } catch (error) {
            console.error('Failed to fetch organization details:', error);
        }
    };


    return (
        <form onSubmit={handleSubmit(onSubmit)} className={styles.checkoutForm}>
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
                        render={({ field }) => (
                            <Field>
                                <Label>
                                    {t('bookingfrontend.organization')}
                                </Label>
                                <AsyncSelect
                                    cacheOptions
                                    defaultOptions={myOrganizations?.map(org => ({
                                        value: org.organization_number,
                                        label: `${org.organization_number} [${org.name}]`
                                    }))}
                                    loadOptions={loadOptions}
                                    onChange={(newValue) => {
                                        const value = (newValue as OrganizationOption)?.value || '';
                                        field.onChange(value);
                                        handleOrgChange(value);
                                    }}
                                    value={myOrganizations?.map(org => ({
                                        value: org.organization_number,
                                        label: `${org.organization_number} [${org.name}]`
                                    })).find(option => option.value === field.value)}
                                    isClearable
                                    placeholder={t('bookingfrontend.select_organization')}
                                    classNamePrefix="react-select"
                                    styles={{
                                        container :(base) => ({
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
                            error={errors.contactName?.message}
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
                            error={errors.contactEmail?.message}
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
                            error={errors.contactEmailConfirm?.message}
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
                            error={errors.contactPhone?.message}
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
                            error={errors.street?.message}
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
                            error={errors.zipCode?.message}
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
                            error={errors.city?.message}
                        />
                    )}
                />
            </div>

            <div className={styles.submitSection}>
                <Button type="submit">
                    {t('bookingfrontend.submit_application')}
                </Button>
            </div>
        </form>
    );
};

export default BillingForm;