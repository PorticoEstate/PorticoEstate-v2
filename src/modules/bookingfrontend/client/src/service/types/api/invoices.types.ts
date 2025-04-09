export interface ICompletedReservation {
    /** Unique identifier for the reservation */
    id: number;

    /** Type of reservation, max length 70 chars */
    reservation_type: string;

    /** ID of the reservation */
    reservation_id: number;

    /** Optional ID of the season */
    season_id?: number;

    /** Cost of the reservation */
    cost: number;

    /** Start date/time of the reservation */
    from_: TDateISO;

    /** End date/time of the reservation */
    to_: TDateISO;

    /** Optional ID of the organization */
    organization_id?: number;

    /** Type of customer, max length 70 chars */
    customer_type: string;

    /** Optional organization number of customer, max length 9 chars */
    customer_organization_number?: string;

    /** Optional SSN of customer, max length 12 chars */
    customer_ssn?: string;

    /** Description of the reservation */
    description: string;

    /** Name of the building */
    building_name: string;

    /** Description of the article, max length 35 chars */
    article_description: string;

    /** ID of the building */
    building_id: number;

    /** Optional export status */
    exported?: number;

    /** Optional customer identifier type, max length 255 chars */
    customer_identifier_type?: string;

    /** Optional ID of the export file */
    export_file_id?: number;

    /** Optional invoice file order ID, max length 255 chars */
    invoice_file_order_id?: string;

    /** Optional customer number */
    customer_number?: string;
}


