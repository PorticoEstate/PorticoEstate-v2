import {z} from "zod";

export const checkoutEventDetailsSchema = z.object({
    organizerName: z.string().min(1, 'Organizer name is required'),
});

export type CheckoutEventDetailsData = z.infer<typeof checkoutEventDetailsSchema>;