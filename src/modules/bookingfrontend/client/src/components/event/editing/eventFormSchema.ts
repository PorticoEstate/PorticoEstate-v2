import { z } from "zod";

export interface EditingEvent {
    name: string;
    from_: Date;
    to_: Date;
    organizer?: string;
    participant_limit: number;
    resources: {id: number, name: string}[]
}

const resourceSchema = z.object({
    id: z.number(),
    name: z.string()
})

export const eventFormSchema: z.ZodType<EditingEvent> = z
    .object({
        name: z
            .string()
            .min(5, { message: "bookingfrontend.enter_title" })
            .max(255),
        from_: z.date(),
        to_: z.date(),
        participant_limit: z
            .number()
            .min(1, {
                message: "bookingfrontend.number of participants is required",
            })
            .max(50),
        organizer: z
            .string()
            .min(5, { message: "bookingfrontend.please_enter_organizer" })
            .max(100),
        resources: z.array(resourceSchema).nonempty({
            message: "bookingfrontend.please_select_at_least_one_resource",
        }),
        building_name: z.string().readonly(),
    })
    .refine(({ from_, to_ }) => from_ <= to_, {
        path: ['from_'],
        message: "bookingfrontend.invalid_date_range",
    });