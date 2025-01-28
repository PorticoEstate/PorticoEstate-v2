import {IShortResource} from "@/service/pecalendar.types";
import {IDocument} from "@/service/types/api.types";

export interface IApplication {
    id: number;
    id_string: string;
    active: number;
    display_in_dashboard: number;
    type: string;
    status: string;
    created: string;
    modified: string;
    building_name: string;
    building_id: number;
    frontend_modified: string | null;
    owner_id: number;
    case_officer_id: number | null;
    activity_id: number;
    customer_identifier_type: string;
    customer_ssn: string | null;
    customer_organization_number: string | null;
    name: string;
    secret?: string | null;
    organizer: string;
    homepage: string | null;
    description: string | null;
    equipment: string | null;
    contact_name: string;
    contact_email: string;
    contact_phone: string;
    audience: number[];
    dates: IApplicationDate[];
    resources: IShortResource[];
    orders: IOrder[];
    documents: IDocument[];
    responsible_street: string;
    responsible_zip_code: string;
    responsible_city: string;
    session_id: string | null;
    agreement_requirements: string | null;
    external_archive_key: string | null;
    customer_organization_name: string | null;
    customer_organization_id: number | null;
    agegroups: IApplicationAgeGroup[];
}


interface IApplicationDate {
    from_: string;
    to_: string;
    id: number;
}


export interface IOrder {
    order_id: number;
    sum: number;
    lines: IOrderLine[];
}

export interface IOrderLine {
    order_id: number;
    status: number;
    parent_mapping_id: number;
    article_mapping_id: number;
    quantity: number;
    unit_price: number;
    overridden_unit_price: number;
    currency: string;
    amount: number;
    unit: string;
    tax_code: number;
    tax: number;
    name: string;
}

interface IApplicationAgeGroup {
    id: number;
    name: string;
    description: string | null;
    sort: number;
    male: number;
    female: number;
}


export interface NewPartialApplication extends Pick<IApplication, 'name' | 'building_name' | 'building_id' | 'activity_id'>{
    dates: Array<{
        from_: string;    // ISO date string
        to_: string;      // ISO date string
    }>;
    resources: Array<number>;
    agegroups?: IApplicationAgeGroup[];
    audience?: number[];

}
export interface IUpdatePartialApplication extends Partial<Omit<IApplication, 'dates' | 'resources'>>{
    id: number;
    dates?: Array<{
        id?: number;
        from_: string;    // ISO date string
        to_: string;      // ISO date string
    }>;
    resources?: Array<IShortResource | IResource>;
    agegroups?: IApplicationAgeGroup[];
}