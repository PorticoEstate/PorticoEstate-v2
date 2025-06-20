import {IShortResource} from "@/service/pecalendar.types";

export interface IBuilding {
    /** Unique identifier for the building */
    id: number;

	/** Town id link */
    town_id: number;

    /** Name of the building */
    name: string;

    /** Homepage of the building */
    homepage?: string;

    /** Contact phone number */
    phone: string;

    /** Contact email */
    email?: string;

    /** Status of the building, 1 for active, 0 for inactive */
    active: number;

    /** Street address of the building */
    street: string;

    /** Zip code of the building */
    zip_code: string;

    /** City where the building is located */
    city: string;

    /** District or part of town */
    district: string;

    /** Location code of the building */
    location_code?: string;

    /** Whether the calendar is deactivated */
    deactivate_calendar: boolean;

    /** Whether applications are deactivated */
    deactivate_application: boolean;

    /** Whether sending messages is deactivated, 0 or 1 */
    deactivate_sendmessage: number;

    /** Name of the inspector */
    tilsyn_name?: string;

    /** Email of the inspector */
    tilsyn_email?: string;

    /** Phone number of the inspector */
    tilsyn_phone?: string;

    /** Text for the calendar */
    calendar_text?: string;

    /** Second name of the inspector */
    tilsyn_name2?: string;

    /** Second email of the inspector */
    tilsyn_email2?: string;

    /** Second phone number of the inspector */
    tilsyn_phone2?: string;

    /** Whether there is an extra calendar, 0 or 1 */
    extra_kalendar: number;

    /** Activity ID associated with the building */
    activity_id: number;

    /** Opening hours of the building */
    opening_hours?: string;

    /** Description in JSON format */
    description_json?: string; // JSON object, can use a more specific type if needed
}


export interface IAgeGroup {
    id: number;
    name: string;
    description: string | undefined;
    active: number;
    sort: number;
    activity_id: number;
}

export interface IAudience {
    id: number;
    name: string;
    description: string | null;
    active: number;
    sort: number;
    activity_id: number;
}


interface SeasonBoundary {
	from_: string;  // Format: "HH:mm:ss"
	to_: string;    // Format: "HH:mm:ss"
	wday: number;   // 1-7 where 1=Monday through 7=Sunday
}

export interface Season {
	id: number;
	name: string;
	building_id: number;
	from_: string;  // ISO 8601 format: "2025-01-01T00:00:00+01:00"
	to_: string;    // ISO 8601 format: "2025-06-30T23:59:59+02:00"
	active: number;
	status: string;
	resources: IShortResource[];
	boundaries: SeasonBoundary[];
}