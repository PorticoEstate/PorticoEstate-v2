import { z } from "zod";

export interface CreatingDelegate {
    name: string;
    ssn: string;
    email: string;
    phone: string;
}

export interface UpdatingDelegate {
    name: string;
    email: string;
    phone: string;
}

const phoneRegex = new RegExp(
    /^([+]?[\s0-9]+)?(\d{3}|[(]?[0-9]+[)])?([-]?[\s]?[0-9])+$/
  );

export const createDelegateFormSchema: z.ZodType<CreatingDelegate> = z
    .object({
        name: z
            .string({ message: "bookingfrontend.field_required" })
            .min(5, { message: "bookingfrontend.name_is_too_short" })
            .max(255),
        ssn: z
            .string({ message: "bookingfrontend.field_required" })
            .length(11, { message: 'bookingfrontend.ssn_length' }),
        email: z
            .string({ message: "bookingfrontend.field_required" })
            .email({ message: "bookingfrontend.invalid_email" }),
        phone: z
            .string({ message: "bookingfrontend.field_required" })
            .regex(phoneRegex, 'bookingfrontend.invalid_phone_number'),
    });

export const updateDelegateFormSchema: z.ZodType<UpdatingDelegate> = z
.object({
    name: z
        .string({ message: "bookingfrontend.field_required" })
        .min(5, { message: "bookingfrontend.name_is_too_short" })
        .max(255),
    email: z
        .string({ message: "bookingfrontend.field_required" })
        .email({ message: "bookingfrontend.invalid_email" }),
    phone: z
        .string({ message: "bookingfrontend.field_required" })
        .regex(phoneRegex, 'bookingfrontend.invalid_phone_number'),
});