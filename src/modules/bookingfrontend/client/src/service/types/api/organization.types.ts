export interface IShortOrganization extends Pick<IOrganization, 'id' | 'name' | 'organization_number' | 'active'>{
}

export interface IOrganization {
	id: number;
	name: string;
	organization_number: string;
	homepage?: string | null;
	phone?: string | null;
	email?: string | null;
	active: number;
	street?: string | null;
	zip_code?: string | null;
	city?: string | null;
	district?: string | null;
	activity_id?: number | null;
	customer_number?: string | null;
	customer_identifier_type?: string | null;
	customer_organization_number?: string | null;
	customer_ssn?: string | null;
	customer_internal: number;
	shortname?: string | null;
	show_in_portal: number;
	in_tax_register?: number | null;
	co_address?: string | null;
	description_json?: string;
}
export type CustomerType = 'ssn' | 'organization_number';

// Organization Group interfaces
export interface IOrganizationGroupContact {
	id: number;
	name: string;
	email?: string | null;
	phone?: string | null;
	group_id: number;
}

export interface IOrganizationGroup {
	id: number;
	name: string;
	organization_id: number;
	parent_id?: number | null;
	description?: string | null;
	activity_id?: number | null;
	shortname?: string | null;
	active: number;
	show_in_portal: number;
	contacts?: IOrganizationGroupContact[];
}

export interface IShortOrganizationGroup extends Pick<IOrganizationGroup, 'id' | 'name' | 'organization_id' | 'parent_id' | 'activity_id' | 'shortname' | 'active' | 'show_in_portal'> {
}

// Organization Delegate interfaces
export interface IOrganizationDelegate {
	id: number;
	name: string;
	organization_id: number;
	email?: string | null;
	ssn?: string | null; // Only visible when user has access
	phone?: string | null;
	active: number;
	is_self?: boolean; // Whether this delegate is the current logged-in user
}

export interface IShortOrganizationDelegate extends Pick<IOrganizationDelegate, 'id' | 'name' | 'organization_id' | 'email' | 'phone' | 'active' | 'is_self'> {
}


interface Address {
    kommune: string | null;
    landkode: string | null;
    postnummer: string | null;
    adresse: string[] | null;
    land: string | null;
    kommunenummer: string | null;
    poststed: string | null;
}

interface KodeBeskrivelseType {
    kode: string | null;
    beskrivelse: string | null;
}

interface Links {
    overordnetEnhet: object;
    self: object;
}

export interface BrregOrganization {
    // Required fields
    organisasjonsnummer: string;
    navn: string;
    maalform: string;
    registrertIMvaregisteret: boolean;
    underAvvikling: boolean;
    registrertIStiftelsesregisteret: boolean;
    konkurs: boolean;
    registrertIFrivillighetsregisteret: boolean;
    registrertIForetaksregisteret: boolean;
    registreringsdatoEnhetsregisteret: string;
    underTvangsavviklingEllerTvangsopplosning: boolean;
    harRegistrertAntallAnsatte: boolean;

    // Complex objects
    organisasjonsform: {
        _links: object;
        kode: string;
        beskrivelse: string;
        utgaatt: string | null;
    };
    postadresse: Address;
    forretningsadresse: Address;
    naeringskode1: KodeBeskrivelseType | null;
    naeringskode2: KodeBeskrivelseType | null;
    naeringskode3: KodeBeskrivelseType | null;
    hjelpeenhetskode: KodeBeskrivelseType;
    institusjonellSektorkode: KodeBeskrivelseType;
    _links: Links;

    // Date fields
    underAvviklingDato?: string;
    konkursdato?: string;
    tvangsavvikletPgaManglendeSlettingDato?: string;
    tvangsopplostPgaManglendeDagligLederDato?: string;
    tvangsopplostPgaManglendeRevisorDato?: string;
    tvangsopplostPgaManglendeRegnskapDato?: string;
    tvangsopplostPgaMangelfulltStyreDato?: string;
    vedtektsdato?: string;
    stiftelsesdato: string | null;
    registreringsdatoAntallAnsatteNAVAaregisteret?: string;
    registreringsdatoAntallAnsatteEnhetsregisteret?: string;
    registreringsdatoMerverdiavgiftsregisteret?: string;
    registreringsdatoMerverdiavgiftsregisteretEnhetsregisteret?: string;
    registreringsdatoFrivilligMerverdiavgiftsregisteret?: string;
    registreringsdatoForetaksregisteret?: string;
    registreringsdatoFrivillighetsregisteret?: string;
    registreringsdatoPartiregisteret?: string;

    // Arrays
    vedtektsfestetFormaal: string[];
    aktivitet: string[];
    frivilligMvaRegistrertBeskrivelser: string[] | null;

    // Optional fields
    hjemmeside: string | null;
    sisteInnsendteAarsregnskap: string | null;
    antallAnsatte?: number;
    overordnetEnhet: string | null;
    registrertIPartiregisteret?: boolean;
    epostadresse?: string;
    telefon?: string;
    mobil?: string;
}

