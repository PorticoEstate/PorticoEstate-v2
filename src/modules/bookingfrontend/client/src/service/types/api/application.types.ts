import {IShortResource} from "@/service/pecalendar.types";

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
    responsible_street: string;
    responsible_zip_code: string;
    responsible_city: string;
    session_id: string | null;
    agreement_requirements: string | null;
    external_archive_key: string | null;
    customer_organization_name: string | null;
    customer_organization_id: number | null;
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