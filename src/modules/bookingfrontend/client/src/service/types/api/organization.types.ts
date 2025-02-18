export interface ShortActivity {
    id: number;
    name: string;
}

export interface Organization {
    id: string;
    active: number;
    city: string;
    co_address: string;
    delegaters: Delegate[];
    district: string;
    email: string;
    groups: Group[];
    homepage: string;
    name: string;
    phone: string;
    shortname: string;
    street: string;
    zip_code: string;
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

export interface Group {
    id: number;
    active: number;
    activity: ShortActivity;
    contact: Contact[];
    description: string;
    name: string;
}