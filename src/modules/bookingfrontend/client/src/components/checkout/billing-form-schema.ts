// billing-form-schema.ts
import {z} from "zod";

export const billingFormSchema = z.object({
    customerType: z.enum(['ssn', 'organization_number']),
    organizationNumber: z.string().optional(),
    organizationName: z.string().optional(),
    contactName: z.string().min(1, 'Contact name is required'),
    contactEmail: z.string().email('Invalid email'),
    contactEmailConfirm: z.string().email('Invalid email'),
    contactPhone: z.string().min(8, 'Invalid phone number'),
    street: z.string().min(1, 'Street is required'),
    zipCode: z.string().length(4, 'Invalid zip code'),
    city: z.string().min(1, 'City is required'),
    documentsRead: z.boolean().refine(val => val === true, {
        message: "You must confirm that you have read all regulation documents",
    }),
}).refine((data) => data.contactEmail === data.contactEmailConfirm, {
    message: "Emails don't match",
    path: ["contactEmailConfirm"],
}).refine((data) => {
    if (data.customerType === 'organization_number') {
        return !!data.organizationNumber;
    }
    return true;
}, {
    message: "Organization number is required for organization bookings",
    path: ["organizationNumber"],
});

export type BillingFormData = z.infer<typeof billingFormSchema>;