export interface IOrganization {
    id: number;
    name: string;
    organization_number: string;
    active: boolean;
}

export type CustomerType = 'ssn' | 'organization_number';


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

