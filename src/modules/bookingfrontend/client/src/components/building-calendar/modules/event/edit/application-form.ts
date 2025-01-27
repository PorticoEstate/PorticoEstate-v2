import {z} from "zod";

export const applicationFormSchema = z.object({
    title: z.string().min(1, ('bookingfrontend.enter_title')),
    start: z.date(),
    end: z.date(),
    resources: z.array(z.string()).min(1, ('bookingfrontend.please_select_at_least_one_resource')),
    homepage: z.union([z.literal(""), z.string().trim().url()]),
    description: z.string().optional(),
    equipment: z.string().optional(),
    // Add validation for audience and agegroups
    audience: z.array(z.number()),
    agegroups: z.array(z.object({
        id: z.number(),
        male: z.number().min(0),
        female: z.literal(0), // Still tracking only male counts
        name: z.string(),
        description: z.string().nullable(),
        sort: z.number()
    })).refine(
        (agegroups) => agegroups.some(group => group.male > 0),
        {
            message: ("bookingfrontend.number of participants is required")
        }
    )
});
export type ApplicationFormData = z.infer<typeof applicationFormSchema>;