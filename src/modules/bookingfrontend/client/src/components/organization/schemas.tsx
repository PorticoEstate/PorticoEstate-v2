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
            .string()
            .min(5, { message: "bookingfrontend.enter_name" })
            .max(255),
        phone: z.string().regex(phoneRegex, 'bookingfrontend.invalid_phone_number'),
        email: z.string().email()
    })

const patchOrganizationData: z.ZodType<UpdatingOrganization> = z
.object({
    name: z
        .string()
        .min(5, { message: "bookingfrontend.enter_name" })
        .max(255),
    shortname: z
        .string()
        .min(5, { message: "bookingfrontend.enter_shortname" })
        .max(255),
    homepage: z.string(),
    organization_number: z.string(),
    city: z.string(),
    district: z.string(),
    street: z.string(),
    zip_code: z.string(),
    email: z.string().email(),
    phone: z.string().regex(phoneRegex, 'bookingfrontend.invalid_phone_number'),
    activity_id: z.number(),
    show_in_portal: z.boolean()
});

export const patchOrganizationSchema: z.ZodType<UpdatingOrganization> = z
    .object({
        organization: patchOrganizationData,
        contacts: z.array(patchOrganizationContacts).length(2) 
    });