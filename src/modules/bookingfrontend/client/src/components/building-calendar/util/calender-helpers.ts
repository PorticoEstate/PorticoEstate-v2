import { IResource } from "@/service/types/resource.types";
import {IShortResource} from "@/service/pecalendar.types";

export const ResourceUsesTimeSlots = (resource: IResource | IShortResource): boolean => {
	return resource.simple_booking === 1 && resource.simple_booking_start_date !== null;
}

// Mirrors ApplicationService::isEligibleForDirectBooking() on the server: a resource
// auto-accepts once its direct_booking activation timestamp has passed. Some resources
// carry both simple_booking and direct_booking, so this must be combined with
// !ResourceUsesTimeSlots() to identify a "direct booking, not timeslot" resource.
export const ResourceIsDirectBooking = (resource: IResource | IShortResource): boolean => {
	return !!resource.direct_booking && Math.floor(Date.now() / 1000) > resource.direct_booking;
}