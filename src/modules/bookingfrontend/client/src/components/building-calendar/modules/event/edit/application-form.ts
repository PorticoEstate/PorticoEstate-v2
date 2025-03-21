// application-form.ts
import {z} from "zod";
import { ArticleOrder } from "@/service/types/api/order-articles.types";

export const applicationFormSchema = z.object({
	title: z.string().min(1, ('bookingfrontend.enter_title')),
	start: z.date(),
	end: z.date(),
	resources: z.array(z.string()).min(1, ('bookingfrontend.please_select_at_least_one_resource')),
	homepage: z.union([z.literal(""), z.string().trim().url()]),
	description: z.string().optional(),
	equipment: z.string().optional(),
	organizer: z.string().optional(),
	audience: z.array(z.number()),
	articles: z.array(z.object({
		id: z.number(),
		quantity: z.number().min(0),
		parent_id: z.number().nullable().optional(),
	})).optional(),
	agegroups: z.array(z.object({
		id: z.number(),
		male: z.number().min(0),
		female: z.number().default(0),
		name: z.string(),
		description: z.string().nullable(),
		sort: z.number()
	})).refine(
		(agegroups) => agegroups.some(group => group.male > 0),
		{
			message: ("bookingfrontend.number of participants is required")
		}
	)
}).refine(
	(data) => data.end > data.start,
	{
		message: "bookingfrontend.end_time_must_be_after_start_time",
		path: ["end"]
	}
);

export type ApplicationFormData = z.infer<typeof applicationFormSchema>;