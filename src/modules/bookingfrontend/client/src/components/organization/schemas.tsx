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

export const patchOrganizationSchema: z.ZodType<UpdatingOrganization> = z
    .object({
        organization_number: z.string(),
        city: z.string(),
        district: z.string(),
        street: z.string(),
        zip_code: z.string(),
        name: z.string(),
        shortname: z.string(),
        email: z.string().email(),
        phone: z.string(),
        homepage: z.string(),
        activity_id: z.string(),
    });