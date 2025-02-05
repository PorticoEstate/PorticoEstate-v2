import { z } from "zod";

export interface EditingEvent {
    name: string;
    from_: Date;
    to_: Date;
    organizer: string;
    participant_limit: number;
    resources: {id: number, name: string}[]
}

const resourceSchema = z.object({
    id: z.number(),
    name: z.string()
})

export const eventFormSchema: z.ZodType<EditingEvent> = z.object({
    name: z.string().min(5).max(255),
    from_: z.date(),
    to_: z.date(),
    participant_limit: z.number().min(1).max(50),
    organizer: z.string().min(5).max(100),
    resources: z.array(resourceSchema),
    building_name: z.string().readonly()
});