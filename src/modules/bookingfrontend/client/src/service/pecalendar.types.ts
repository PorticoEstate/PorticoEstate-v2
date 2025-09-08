import {IResource} from './types/resource.types';

export type IEvent = IAPIEvent | IAPIBooking | IAPIAllocation;

export const IEventIsAPIEvent = (event: IEvent): event is IAPIEvent => {
	return event.type === 'event';
}
export const IEventIsAPIBooking = (event: IEvent): event is IAPIBooking => {
	return event.type === 'booking';
}

export const IEventIsAPIAllocation = (event: IEvent): event is IAPIAllocation => {
	return event.type === 'allocation';
}

export interface IAPIScheduleEntity {
	type: 'booking' | 'allocation' | 'event';
	id: number;
	active: number;  // @Expose + default 1
	from_: TDateISO;  // @Expose + NOT NULL
	to_: TDateISO;    // @Expose + NOT NULL
	cost?: number;     // nullable + no @Expose
	completed: number; // @Expose + default 0
	application_id?: number;  // nullable + no @Expose
	building_name: string;    // @Expose + NOT NULL + default
	skip_bas: number;        // @Expose + default 0
	resources: IShortResource[];  // @Expose
	edit_link?: string;
	cancel_link?: string;
}

export interface IAPIAllocation extends IAPIScheduleEntity {
	type: 'allocation';  // @Default
	organization_id: number;  // @Expose + NOT NULL
	season_id: number;       // @Expose + NOT NULL
	id_string: string;       // @Expose + default
	additional_invoice_information?: string;  // @Expose + nullable
	organization_name: string;    // @Expose (computed)
	organization_shortname: string;  // @Expose (computed)
}

export interface IAPIBooking extends IAPIScheduleEntity {
	type: 'booking';  // @Default
	group_id: number;        // @Expose + NOT NULL
	allocation_id?: number;  // @Expose + nullable
	season_id: number;       // @Expose + NOT NULL
	activity_id: number;     // @Expose + NOT NULL
	reminder: number;        // @Expose + default 0
	secret: string;         // @Expose + NOT NULL
	sms_total?: number;     // @Expose + nullable
	group_name: string;     // @Expose (computed)
	activity_name: string;  // @Expose (computed)
}

export interface IAPIEvent extends IAPIScheduleEntity {
	type: 'event';  // @Default
	activity_id: number;     // @Expose + NOT NULL
	description?: string;    // conditional @Expose + nullable
	contact_name?: string;   // conditional @Expose + NOT NULL
	contact_email?: string;  // conditional @Expose + nullable
	contact_phone?: string;  // conditional @Expose + NOT NULL
	reminder: number;        // @Expose + default 0
	secret?: string;        // conditional @Expose + NOT NULL
	customer_identifier_type?: 'organization_number' | 'ssn';  // no @Expose + nullable
	customer_organization_number?: string;  // no @Expose + nullable
	customer_ssn?: string;   // no @Expose + nullable
	customer_internal?: number;  // no @Expose + default 1
	is_public: number;      // @Expose + default 1
	customer_organization_id?: number;  // conditional @Expose + nullable
	customer_organization_name?: string;  // conditional @Expose + nullable
	id_string: string;      // @Expose + default
	building_id: number;    // @Expose + nullable
	name: string;           // @Expose + @Default("PRIVATE EVENT")
	organizer?: string;     // conditional @Expose + nullable
	homepage?: string;      // conditional @Expose + nullable
	equipment?: string;     // conditional @Expose + nullable
	access_requested?: number;  // no @Expose + default 0
	participant_limit?: number;  // conditional @Expose + nullable
}


export type IShortEvent = Pick<IAPIEvent, 'id' | 'customer_organization_number' | 'customer_organization_id' |  'customer_organization_name' | 'name' | 'building_id' | 'organizer' | 'resources' | 'from_' | 'to_' | 'building_name'>

export interface IEventOLD {
	type: 'booking' | 'allocation' | 'event'
	// allocation -> booking -> event | temporary
	id: number
	id_string?: string
	active: number
	building_id: any
	application_id?: number
	completed: number
	name: string
	shortname?: string
	organization_id?: number
	resources: IShortResource[]
	season_id?: number
	season_name?: string
	// from: string
	// to: string
	// date: string
	from_: string;
	to_: string;
	building_name: string
	allocation_id?: number
	group_id?: number
	activity_id?: number
	activity_name?: string
	group_name?: string
	group_shortname?: string
	reminder?: number
	dates?: IEventDate[]
	homepage?: string
	description?: string
	equipment?: string
	access_requested?: number
	is_public?: number
}

export type IShortResource = Pick<IResource, 'active' | 'name' | 'id' | 'activity_id' | 'simple_booking' | 'building_id' | 'activate_prepayment' | 'deactivate_application' | 'deactivate_calendar' | 'simple_booking_start_date' | 'direct_booking'>;

export interface IEventDate {
	from_: string
	to_: string
	id: number
}


export interface IFreeTimeSlot {
	when: string
	start: string
	end: string
	start_iso: TDateISO;
	end_iso: TDateISO;
	overlap: false | 1 | 2 | 3 // false = ledig | 1 = bestilt av ein anna | 2 = påbegynt/reservert | 3 = fortid
	overlap_reason?: 'time_in_past' | 'complete_overlap' | 'complete_containment' |
		'start_overlap' | 'end_overlap';
	overlap_type?: 'disabled' | 'complete' | 'partial';
	resource_id?: number;
	overlap_event?: {
		id?: number;
		type?: 'application' | null;
		status?: "NEWPARTIAL1" | string | null;
	}
}

export interface ApplicationLink {
	menuaction: string
	resource_id: number
	building_id: number
	"from_[]": string
	"to_[]": string
	simple: boolean
}
