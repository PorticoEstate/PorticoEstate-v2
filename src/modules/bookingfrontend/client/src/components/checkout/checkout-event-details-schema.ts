import {z} from "zod";

export const checkoutEventDetailsSchema = z.object({
    title: z.string().min(1, 'Event title is required'),
    organizerName: z.string().min(1, 'Organizer name is required'),
});

export type CheckoutEventDetailsData = z.infer<typeof checkoutEventDetailsSchema>;