import { z } from "zod";

export interface EditingEvent {
    name?: string;
    from_?: Date;
    to_?: Date;
    organizer?: string;
    participant_limit?: number;
    resources: Map<number, string>
}

export const eventFormSchema: z.ZodType<EditingEvent> = z.object({
    name: z.string().optional(),
    from_: z.date().optional(),
    to_: z.date().optional(),
    participant_limit: z.number().optional(),
    organizer: z.string().optional(),
    resources: z.map(z.number(), z.string()),
    building_name: z.string().readonly()
});