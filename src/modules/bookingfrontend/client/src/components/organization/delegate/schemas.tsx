import { z } from "zod";

export interface CreatingDelegate {
    name: string;
    ssn: string;
    email: string;
    phone: string;
}

const phoneRegex = new RegExp(
    /^([+]?[\s0-9]+)?(\d{3}|[(]?[0-9]+[)])?([-]?[\s]?[0-9])+$/
  );

export const createDelegateFormSchema: z.ZodType<CreatingDelegate> = z
    .object({
        name: z
            .string()
            .min(5, { message: "bookingfrontend.enter_name" })
            .max(255),
        ssn: z
            .string()
            .length(11, { message: 'bookingfrontend_incorrect_ssn' }),
        email: z.string().email(),
        phone: z.string().regex(phoneRegex, 'bookingfrontend.invalid_phone_number'),
    });