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
	),
	// Recurring booking fields
	isRecurring: z.boolean().default(false),
	recurring_info: z.object({
		repeat_until: z.string().optional(),
		field_interval: z.number().refine((val) => [1, 2, 3, 4].includes(val), {
			message: 'Interval must be 1, 2, 3, or 4 weeks'
		}).default(1),
		outseason: z.boolean().default(false)
	}).optional(),
	// Organization selection for recurring bookings
	organization_id: z.number().optional(),
	organization_number: z.string().optional(),
	organization_name: z.string().optional()
}).refine(
	(data) => data.end > data.start,
	{
		message: "bookingfrontend.end_time_must_be_after_start_time",
		path: ["end"]
	}
).refine(
	(data) => {
		// If recurring is enabled, recurring_info is required
		if (data.isRecurring) {
			return !!data.recurring_info;
		}
		return true;
	},
	{
		message: "Recurring booking settings are required",
		path: ["recurring_info"]
	}
).refine(
	(data) => {
		// If recurring is enabled, organization is required
		if (data.isRecurring) {
			return !!data.organization_id;
		}
		return true;
	},
	{
		message: "Organization selection is required for recurring bookings",
		path: ["organization_id"]
	}
).refine(
	(data) => {
		// If recurring is enabled, either repeat_until OR outseason must be set
		if (data.isRecurring && data.recurring_info) {
			return !!(data.recurring_info.repeat_until && data.recurring_info.repeat_until.length > 0) || data.recurring_info.outseason;
		}
		return true;
	},
	{
		message: "Either select a repeat until date or choose to repeat until end of season",
		path: ["recurring_info", "repeat_until"]
	}
);

export type ApplicationFormData = z.infer<typeof applicationFormSchema>;