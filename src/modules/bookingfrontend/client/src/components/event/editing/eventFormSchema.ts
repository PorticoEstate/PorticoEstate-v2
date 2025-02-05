import { z } from "zod";

export interface EditingEvent {
    name: string;
    from_: Date;
    to_: Date;
    organizer: string;
    participant_limit: number;
    resources: Map<number, string>
}

export const eventFormSchema: z.ZodType<EditingEvent> = z.object({
    name: z.string().min(5).max(255),
    from_: z.date(),
    to_: z.date(),
    participant_limit: z.number().min(1).max(50),
    organizer: z.string().min(5).max(100),
    resources: z.map(z.number(), z.string()),
    building_name: z.string().readonly()
});