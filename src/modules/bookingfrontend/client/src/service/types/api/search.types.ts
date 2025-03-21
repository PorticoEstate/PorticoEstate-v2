import {IBuilding} from "@/service/types/Building";



export interface ISearchDataAll {
	activities: ISearchDataActivity[];
	buildings: ISearchDataBuilding[];
	building_resources: ISearchDataBuildingResource[];
	facilities: unknown[];
	resources: IResource[];
	resource_activities: unknown[];
	resource_facilities: unknown[];
	resource_categories: unknown[];
	resource_category_activity: unknown[];
	towns: ISearchDataTown[];
	organizations: ISearchOrganization[];
}

export interface ISearchDataTown {
	b_id: number; // Link -> building->id
	b_name: string; // Link -> building->name
	id: number;
	name: string;
}
export interface ISearchDataBuildingResource {
	building_id: number; // Link -> building->id
	resource_id: number; // Link -> resource->id
}
export interface ISearchDataActivity {
	id: number;
	parent_id: number; // can be related to other activity
	name: string;
	description: string;
	active: 1 | 0;
}

export interface ISearchDataBuilding extends Pick<IBuilding,'id' | 'activity_id' | 'deactivate_calendar' | 'deactivate_application' | 'deactivate_sendmessage' | 'extra_kalendar' | 'name' | 'homepage' | 'location_code' | 'phone' | 'email' | 'tilsyn_name' | 'tilsyn_phone' | 'tilsyn_email' | 'tilsyn_name2' | 'tilsyn_phone2' | 'tilsyn_email2' | 'street' | 'zip_code' | 'district' | 'city' | 'calendar_text' | 'opening_hours'
> {}

export interface ISearchOrganization {
	id: number;
	organization_number?: string;
	name: string;
	homepage?: string;
	phone?: string;
	email?: string;
	co_address?: string;
	zip_code?: string;
	district?: string;
	city?: string;
	activity_id?: number;
	show_in_portal: boolean;
}