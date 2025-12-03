import { IResource } from "@/service/types/resource.types";
import {IShortResource} from "@/service/pecalendar.types";

export const ResourceUsesTimeSlots = (resource: IResource | IShortResource): boolean => {
	return resource.simple_booking === 1 && resource.simple_booking_start_date !== null;
}