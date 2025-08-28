import {z} from "zod";

// Type for translation function
type TranslationFunction = (key: string, options?: any) => string;

export const createCheckoutEventDetailsSchema = (t: TranslationFunction) => z.object({
    organizerName: z.string().min(1, t('bookingfrontend.organizer_required')),
});

// Keep the original schema for backward compatibility
export const checkoutEventDetailsSchema = createCheckoutEventDetailsSchema((key: string, options?: any) => {
    const fallbacks: Record<string, string> = {
        'bookingfrontend.organizer_required': 'Organizer name is required',
    };
    return fallbacks[key] || key.split('.').pop() || key;
});

export type CheckoutEventDetailsData = z.infer<ReturnType<typeof createCheckoutEventDetailsSchema>>;