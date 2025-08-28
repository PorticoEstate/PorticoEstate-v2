// billing-form-schema.ts
import {z} from "zod";

// Type for translation function
type TranslationFunction = (key: string, options?: any) => string;

export const createBillingFormSchema = (t: TranslationFunction) => z.object({
    customerType: z.enum(['ssn', 'organization_number']),
    organizationNumber: z.string().optional(),
    organizationName: z.string().optional(),
    contactName: z.string().min(1, t('bookingfrontend.field_wild_required', {field: t('bookingfrontend.contact_name')})),
    contactEmail: z.string().email(t('bookingfrontend.field_wild_invalid', {field: t('bookingfrontend.contact_email')})),
    contactEmailConfirm: z.string().email(t('bookingfrontend.field_wild_invalid', {field: t('bookingfrontend.confirm_email')})),
    contactPhone: z.string().min(8, t('bookingfrontend.field_wild_invalid', {field: t('bookingfrontend.contact_phone')})),
    street: z.string().min(1, t('bookingfrontend.field_wild_required', {field: t('bookingfrontend.responsible_street')})),
    zipCode: z.string().length(4, t('bookingfrontend.field_wild_invalid', {field: t('bookingfrontend.zip code')})),
    city: z.string().min(1, t('bookingfrontend.field_wild_required', {field: t('bookingfrontend.responsible_city')})),
    documentsRead: z.boolean().refine(val => val === true, {
        message: t('bookingfrontend.documents_confirmation_required'),
    }),
}).refine((data) => data.contactEmail === data.contactEmailConfirm, {
    message: t('bookingfrontend.emails_dont_match'),
    path: ["contactEmailConfirm"],
}).refine((data) => {
    if (data.customerType === 'organization_number') {
        return !!data.organizationNumber;
    }
    return true;
}, {
    message: t('bookingfrontend.field_wild_required', {field: t('bookingfrontend.organization')}),
    path: ["organizationNumber"],
});

// Keep the original schema for backward compatibility
export const billingFormSchema = createBillingFormSchema((key: string, options?: any) => {
    // Fallback to English messages for backward compatibility
    const fallbacks: Record<string, string> = {
        'bookingfrontend.field_wild_required': '{{field}} is required',
        'bookingfrontend.field_wild_invalid': '{{field}} has invalid format',
        'bookingfrontend.contact_name': 'Contact name',
        'bookingfrontend.contact_email': 'Email',
        'bookingfrontend.confirm_email': 'Confirm email',
        'bookingfrontend.contact_phone': 'Phone',
        'bookingfrontend.responsible_street': 'Street',
        'bookingfrontend.zip code': 'Zip code',
        'bookingfrontend.responsible_city': 'City',
        'bookingfrontend.organization': 'Organization',
        'bookingfrontend.emails_dont_match': "Emails don't match",
        'bookingfrontend.documents_confirmation_required': "You must confirm that you have read all regulation documents",
    };
    
    let message = fallbacks[key] || key.split('.').pop() || key;
    
    // Simple interpolation for fallback (replace {{field}} with field value)
    if (options?.field && message.includes('{{field}}')) {
        message = message.replace('{{field}}', options.field);
    }
    
    return message;
});

export type BillingFormData = z.infer<ReturnType<typeof createBillingFormSchema>>;