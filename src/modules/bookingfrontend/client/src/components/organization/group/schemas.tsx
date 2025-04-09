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
            .string({ message: "bookingfrontend.field_is_required" })
            .min(5, { message: "bookingfrontend.field_is_too_short" })
            .max(255),
        email: z
            .string({ message: "bookingfrontend.field_is_required" })
            .email({ message: "bookingfrontend.invalid_email" }),
        phone: z
            .string({ message: "bookingfrontend.field_is_required" })
            .regex(phoneRegex, 'bookingfrontend.invalid_phone_number'),
    });
export const groupLeaderUpdate = groupLeaderSchema.extend({
    id: z.optional(z.number().readonly())
})
export const groupDataSchema: z.ZodType<CreatingGroup> = z
    .object({
        name: z
            .string({ message: "bookingfrontend.field_is_required" })
            .min(5, { message: "bookingfrontend.field_is_too_short" })
            .max(255),
        shortname: z
            .string({ message: "bookingfrontend.field_is_required" })
            .max(11, { message: 'bookingfrontend.field_is_too_long' }),
        activity_id: z.number({ message: "bookingfrontend.field_is_required" }),
        description: z
            .string({ message: "bookingfrontend.field_is_required" })
            .min(5, { message: "bookingfrontend.enter_description" })
            .max(255),
    })
export const createGroupFormSchema: z.ZodType<CreatingGroup> = z
    .object({
        groupLeaders: z.array(groupLeaderSchema).max(2),
        groupData: groupDataSchema
    });
export const updateGroupFormSchema: z.ZodType<CreatingGroup> = z
    .object({
        groupLeaders: z.array(groupLeaderUpdate).min(1).max(2),
        groupData: groupDataSchema
    });
