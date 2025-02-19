import { z } from "zod";


export interface CreatingGroup {
    name: string;
    shortname: string;
    activity_id: number;
    description: string;
}

export interface CreateOrConnectLeader {
    name: string;
    phone: string;
    email: string;
}


const phoneRegex = new RegExp(
    /^([+]?[\s0-9]+)?(\d{3}|[(]?[0-9]+[)])?([-]?[\s]?[0-9])+$/
  );


export const groupLeaderSchema: z.ZodType<CreateOrConnectLeader> = z
    .object({
        name: z
            .string()
            .min(5, { message: "bookingfrontend.enter_name" })
            .max(255),
        phone: z.string().regex(phoneRegex, 'bookingfrontend.invalid_phone_number'),
        email: z.string().email()
    });
export const groupDataSchema: z.ZodType<CreatingGroup> = z
    .object({
        name: z
            .string()
            .min(5, { message: "bookingfrontend.enter_name" })
            .max(255),
        shortname: z
            .string()
            .min(5, { message: "bookingfrontend.enter_shortname" })
            .max(255),
        activity_id: z.string(),
        description: z
            .string()
            .min(5, { message: "bookingfrontend.enter_description" })
            .max(255),
    })
export const createGroupFormSchema: z.ZodType<CreatingGroup> = z
    .object({
        groupLeaders: z.array(groupLeaderSchema).max(2),
        groupData: groupDataSchema
    });