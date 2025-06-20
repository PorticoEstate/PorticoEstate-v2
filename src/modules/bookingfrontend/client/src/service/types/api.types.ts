export interface IServerSettings {
    account_max_id?: number;
    account_min_id?: number;
    account_repository?: string;
    acl_default?: string;
    addressmaster?: number;
    asyncservice?: string;
    auth_type?: string;
    auto_create_expire?: number;
    bakcground_image?: string;
    block_time?: number;
    cache_refresh_token?: number;
    cookie_domain?: string;
    daytime_port?: string;
    Debugoutput?: string;
    disable_autoload_langfiles?: boolean;
    encryption_type?: string;
    encryptkey?: string;
    file_repository?: string;
    files_dir?: string;
    file_store_contents?: string;
    group_max_id?: number;
    group_min_id?: number;
    hostname?: string;
    install_id?: string;
    lang_ctimes?: Record<string, unknown>;
    ldap_account_home?: string;
    ldap_account_shell?: string;
    ldap_encryption_type?: string;
    ldap_host?: string;
    ldap_root_pw?: string;
    log_levels?: Record<string, unknown>;
    logo_url: string;
    logo_title: string;
    mapping?: string;
    max_access_log_age?: number;
    max_history?: number;
    mcrypt_algo?: string;
    mcrypt_mode?: string;
    num_unsuccessful_id?: number;
    num_unsuccessful_ip?: number;
    password_level?: string;
    sessions_app_timeout?: number;
    sessions_timeout?: number;
    showpoweredbyon?: string;
    site_title?: string;
    SMTPDebug?: number;
    smtp_server?: string;
    support_address?: string;
    temp_dir?: string;
    usecookies?: boolean;
    useframes?: string;
    webserver_url?: string;
    bookingfrontend_config: IBookingfrontendConfig | null;
    booking_config: IBookingConfig | null;
}

// User types


export interface IBookingUser {
    id?: number;
    name: string  | null;
    ssn: string | null;
    orgnr: string | null;
    orgname: string | null;
    org_id: number | null;
    is_logged_in: true;
    homepage: string | null;
    phone: string | null;
    email: string | null;
    street: string | null;
    zip_code: string | null;
    city: string | null;
    delegates?: IDelegate[];
    customer_number?: string;
}
export interface IDelegate {
    name: string;
    org_id: number;
    organization_number: string;
    active: boolean;
}
export interface IAPIQueryResponse<T> {
    total_records: number;
    start: number;
    sort: string;
    dir: string;
    results: T[];
}

export interface IBookingfrontendConfig {
    anonymous_passwd?: string;
    anonymous_user?: string;
    authentication_method?: string;
    develope_mode?: boolean;
    footer_info?: string;
    footer_privacy_link?: string;
    site_title?: string;
    soap_password?: string;
    test_ssn?: string;
    usecookies?: boolean;
	url_uustatus?: string;
	url_system_feedback?: string;
}


export interface IBookingConfig {
    activate_application_articles?: boolean;
    allocation_canceled_mail?: string;
    allocation_canceled_mail_subject?: string;
    application_comment_mail_subject?: string;
    application_comment_mail_subject_caseofficer?: string;
    application_contact_information?: string;
    application_description?: string;
    application_equipment?: string;
    application_howmany?: string;
    application_invoice_information?: string;
    application_mail_accepted?: string;
    application_mail_created?: string;
    application_mail_pending?: string;
    application_mail_rejected?: string;
    application_mail_signature?: string;
    application_mail_subject?: string;
    application_mail_systemname?: string;
    application_new_application?: string;
    application_notify_on_accepted?: boolean;
    application_responsible_applicant?: string;
    application_terms?: string;
    application_terms2?: string;
    application_when?: string;
    application_where?: string;
    application_who?: string;
    article?: string;
    booking_canceled_mail?: string;
    booking_canceled_mail_subject?: string;
    customer_list_format?: string;
    dim_1?: string;
    dim_2?: string;
    dim_3?: string;
    dim_5?: string;
    dim_value_1?: string;
    dim_value_4?: string;
    dim_value_5?: string;
    emails?: string;
    email_sender?: string;
    enable_upload_attachment?: boolean;
    event_canceled_mail?: string;
    event_canceled_mail_subject?: string;
    event_change_mail?: string;
    event_change_mail_subject?: string;
    event_conflict_mail_subject?: string;
    event_edited_mail?: string;
    event_edited_mail_subject?: string;
    event_mail_building?: string;
    event_mail_building_subject?: string;
    event_mail_conflict_contact_active_collision?: string;
    external_format?: string;
    external_format_linebreak?: string;
    external_site_address?: string;
    extra_schedule?: string;
    frontimagetext?: string;
    frontpage_filterboxdata?: string;
    frontpagetext?: string;
    frontpagetitle?: string;
    image_maxheight?: number;
    image_maxwidth?: number;
    internal_format?: string;
    invoice_export_method?: string;
    invoice_export_path?: string;
    invoice_ftp_host?: string;
    invoice_ftp_password?: string;
    invoice_ftp_user?: string;
    invoice_last_id?: number;
    landing_sections?: Array<string>;
    logopath_frontend?: string;
    mail_users_season?: boolean;
    metatag_author?: string;
    metatag_robots?: string;
    organization_value?: string;
    output_files?: string;
    participant_limit_sms?: boolean;
    participanttext?: string;
    proxy?: string;
    split_pool?: string;
    split_pool4_ids?: string;
    support_address?: string;
    user_can_delete?: boolean;
    user_can_delete_allocations?: boolean;
    user_can_delete_bookings?: boolean;
    user_can_delete_events?: boolean;
    voucher_client?: string;
    voucher_responsible?: string;
    voucher_type?: string;
}




export interface IDocument {
    id: number;
    name: string;
    description: string;
    category: 'picture' | 'regulation' | 'HMS_document' | 'picture_main' | 'drawing' | 'price_list' | 'other';
    owner_id: number;
    url: string;
    owner_type?: 'resource' | 'building';
}
export type IDocumentCategoryQuery = IDocument['category'] | 'images';

/**
 * Response interface for multi domain list endpoint
 */
export interface IMultiDomainListResponse {
    /** Array of multi domain objects */
    results: IMultiDomain[];
    /** Total number of records */
    total_records: number;
}

/**
 * Multi Domain model interface
 */
export interface IMultiDomain {
    /** Unique identifier for the multi domain */
    id: number;
    /** Name of the domain */
    name: string;
    /** Web service host URL */
    webservicehost: string;
    /** User ID who created this domain */
    user_id?: number | null;
    /** Entry date as timestamp */
    entry_date?: number | null;
    /** Modified date as timestamp */
    modified_date?: number | null;
}
