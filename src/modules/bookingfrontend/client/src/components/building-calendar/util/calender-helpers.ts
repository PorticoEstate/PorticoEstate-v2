import { IResource } from "@/service/types/resource.types";

export const ResourceUsesTimeSlots = (resource: IResource): boolean => {
	return resource.simple_booking === 1 && resource.simple_booking_start_date !== null;
}