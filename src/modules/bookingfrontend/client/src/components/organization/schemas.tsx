import { z } from "zod";

export interface UpdatingOrganization {
    name: string;
    phone: string;
    email: string;
    shortname: string,
    homepage: string,
    city: string;
    district: string;
    street: string,
    zip_code: string,
    activity_id: number;
    organization_number: number;
}

interface UpdatingOrganizationContacts {
    name: string;
    phone: string;
    email: string;
}

const phoneRegex = new RegExp(
    /^([+]?[\s0-9]+)?(\d{3}|[(]?[0-9]+[)])?([-]?[\s]?[0-9])+$/
  );


const patchOrganizationContacts: z.ZodType<UpdatingOrganizationContacts> = z.
    object({
        id: z.number().readonly(),
        name: z
            .string({ message: "bookingfrontend.field_is_required" })
            .min(5, { message: "bookingfrontend.name_is_too_short" })
            .max(255),
        email: z
            .string({ message: "bookingfrontend.field_is_required" })
            .email({ message: "bookingfrontend.invalid_email" }),
        phone: z
            .string({ message: "bookingfrontend.field_is_required" })
            .regex(phoneRegex, 'bookingfrontend.invalid_phone_number'),
    })

const patchOrganizationData: z.ZodType<UpdatingOrganization> = z
.object({
    organization_number: z
        .string({ message: "bookingfrontend.field_is_required" })
        .min(5, { message: "bookingfrontend.field_is_too_short" }),
    name: z
        .string({ message: "bookingfrontend.field_is_required" })
        .min(5, { message: "bookingfrontend.field_is_too_short" })
        .max(255),
    shortname: z
        .string({ message: "bookingfrontend.field_is_required" })
        .min(5, { message: "bookingfrontend.field_is_too_short" })
        .max(255),
    homepage: z
        .string({ message: "bookingfrontend.field_is_required" })
        .min(1, { message: 'bookingfrontend.field_is_too_short' }),
    city: z
        .string({ message: "bookingfrontend.field_is_required" })
        .min(1, { message: 'bookingfrontend.field_is_too_short' }),
    district: z
        .string({ message: "bookingfrontend.field_is_required" })
        .min(1, { message: 'bookingfrontend.field_is_too_short' }),
    street: z
        .string({ message: "bookingfrontend.field_is_required" })
        .min(1, { message: 'bookingfrontend.field_is_too_short' }),
    zip_code: z
        .string({ message: "bookingfrontend.field_is_required" })
        .min(1, { message: 'bookingfrontend.field_is_too_short' }),
    email: z
        .string({ message: "bookingfrontend.field_is_required" })
        .email({ message: "bookingfrontend.invalid_email" }),
    phone: z
        .string({ message: "bookingfrontend.field_is_required" })
        .regex(phoneRegex, 'bookingfrontend.invalid_phone_number'),
    activity_id: z.number({ message: "bookingfrontend.field_is_required" }),
    show_in_portal: z.boolean({ message: "bookingfrontend.field_is_required" })
});

export const patchOrganizationSchema: z.ZodType<UpdatingOrganization> = z
    .object({
        organization: patchOrganizationData,
        contacts: z.array(patchOrganizationContacts).length(2) 
    });