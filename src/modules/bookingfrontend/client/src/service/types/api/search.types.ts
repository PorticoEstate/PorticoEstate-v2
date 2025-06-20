import {IBuilding} from "@/service/types/Building";
import { IResource } from "@/service/types/resource.types";

/**
 * Full search data interface including all fields returned by the API
 */
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
}



/**
 * Optimized search data interface with only the fields used by React components
 */
export interface ISearchDataOptimized {
	activities: ISearchDataActivity[];
	buildings: ISearchDataBuilding[];
	building_resources: ISearchDataBuildingResource[];
	resources: ISearchResource[];
	towns: ISearchDataTown[];
	facilities: ISearchDataFacility[];
	resource_activities: ISearchDataResourceActivity[];
	resource_facilities: ISearchDataResourceFacility[];
	resource_categories: ISearchDataResourceCategory[];
	resource_category_activity: ISearchDataResourceCategoryActivity[];
}

/**
 * Optimized resource interface with only the fields actually used in search component
 */
export interface ISearchResource {
	id: number;
	name: string;
	activity_id: number | null;
	active: number;
	simple_booking: number | null;
	deactivate_calendar: boolean;
	deactivate_application: boolean;
	rescategory_id: number | null; // Link to resource category
	domain_name?: string; // Multi-domain name for cross-domain search results
	domain_url?: string; // Multi-domain base URL for redirects
	original_id?: number; // Original ID before domain transformation
}

export interface ISearchDataTown {
	// b_id: number; // Link -> building->id
	// b_name: string; // Link -> building->name
	id: number;
	name: string;
	original_id?: number; // Original ID before domain transformation
	domain_name?: string; // Multi-domain name for cross-domain search results
}
export interface ISearchDataBuildingResource {
	building_id: number; // Link -> building->id
	resource_id: number; // Link -> resource->id
}
export interface ISearchDataActivity {
	id: number;
	parent_id: number; // can be related to other activity
	name: string;
	// description: string;
	active: 1 | 0;
	original_id?: number; // Original ID before domain transformation
	domain_name?: string; // Multi-domain name for cross-domain search results
}

export interface ISearchDataBuilding extends Pick<IBuilding,'id' | 'town_id' | 'activity_id' | 'deactivate_calendar' | 'deactivate_application' | 'deactivate_sendmessage' | 'extra_kalendar' | 'name' | 'location_code' | 'street' | 'zip_code' | 'district' | 'city'
> {
	original_id?: number; // Original ID before domain transformation
	domain_name?: string; // Multi-domain name for cross-domain search results
}

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


export interface ISearchDataFacility {
	id: number;
	name: string;
	original_id?: number; // Original ID before domain transformation
	domain_name?: string; // Multi-domain name for cross-domain search results
}

export interface ISearchDataResourceActivity {
	resource_id: number; // Link from resource
	activity_id: number; // link to activity
}

export interface ISearchDataResourceFacility {
	resource_id: number; // Link from resource
	facility_id: number; // link to facility
}

export interface ISearchDataResourceCategory {
	id: number;
	name: number;
	parent_id: number; // link to parent ResCategory
	original_id?: number; // Original ID before domain transformation
	domain_name?: string; // Multi-domain name for cross-domain search results
}

export interface ISearchDataResourceCategoryActivity {
	rescategory_id: number; // Link from resource category
	activity_id: number; // link to activity
}
