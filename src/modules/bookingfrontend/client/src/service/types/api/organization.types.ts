export interface ShortActivity {
    id: number;
    name: string;
}
export interface OrganizationContact {
    id: number;
    email: string;
    phone: string;
    name: string;
    ssn: number;
}
export interface Organization {
    id: number;
    active: number;
    city: string;
    co_address: string;
    delegaters: Delegate[];
    contacts: OrganizationContact[];
    district: string;
    email: string;
    groups: Group[];
    homepage: string;
    name: string;
    phone: string;
    shortname: string;
    street: string;
    zip_code: string;
    organization_number: number;
    activity: ShortActivity;
    show_in_portal: boolean;
}

export interface Contact {
    id: number;
    email: string;
    phone: string;
    name: string;
}

export interface Delegate {
    id: number;
    email: string;
    phone: string;
    name: string;
}
export interface ViewDelegate extends Delegate {
    organization: string;
}

export interface Group {
    id: number;
    active: number;
    activity: ShortActivity;
    contact: Contact[];
    description: string;
    name: string;
    shortname: string;
    organization: {
        id: number
        name: string;
    };
}
