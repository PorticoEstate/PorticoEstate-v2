export interface IOrganization {
    id: number;
    name: string;
    organization_number: string;
    active: boolean;
}

export type CustomerType = 'ssn' | 'organization_number';