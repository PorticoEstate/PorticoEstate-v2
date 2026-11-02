export interface IHospitality {
    id: number;
    name: string;
    resource_id: number;
    /**
     * Building that owns `resource_id`, resolved server-side via bb_building_resource.
     * NOT the application's building: 2 of 15 live orders have a kitchen in a different
     * building from the booking it serves, so the two are not interchangeable.
     * Null when the kitchen maps to no building -- callers must fail closed, never
     * fall back to the application's building.
     */
    building_id: number | null;
    resource_name: string;
    remote_serving_enabled: number;
    allow_on_site_hospitality: number;
    include_in_checkout_payment: number;
    order_by_time_value: number | null;
    order_by_time_unit: 'hours' | 'days' | null;
    resource_cancellation_deadline_value: number | null;
    resource_cancellation_deadline_unit: 'hours' | 'days' | 'weeks' | null;
    /** Open-days bitmask (bit0=Mon..bit6=Sun; 127=all open). Raw value, kept for completeness. */
    open_days?: number | null;
    /** Decoded open weekdays as ISO numbers (1=Mon..7=Sun), e.g. [1,2,3,4,5] for Mon–Fri. Provided by the API so the client needs no bit-ops. */
    open_days_list?: number[] | null;
    delivery_locations: IDeliveryLocation[];
}

export interface IDeliveryLocation {
    id: number;
    name: string;
    location_type: 'main' | 'remote';
}

export interface IHospitalityMenu {
    hospitality_id: number;
    hospitality_name: string;
    /** Hospitality-level admin info/routine text shown to the applicant (#374). */
    hospitality_description?: string | null;
    groups: IHospitalityArticleGroup[];
    ungrouped_articles: IHospitalityArticle[];
}

export interface IHospitalityArticleGroup {
    id: number;
    hospitality_id: number;
    name: string;
    sort_order: number;
    active: number;
    articles: IHospitalityArticle[];
}

export interface IHospitalityArticle {
    id: number;
    hospitality_id: number;
    article_group_id: number | null;
    article_mapping_id: number;
    description: Record<string, string> | null;
    sort_order: number;
    active: number;
    article_name: string;
    article_code: string;
    service_name_json: Record<string, string> | null;
    service_description_json: Record<string, string> | null;
    unit: string;
    base_price: string;
    base_tax_code: number;
    effective_price: string;
    effective_tax_code: number;
}

/** Why an action on an order is refused (HospitalityOrderGuard::REASON_*). */
export type HospitalityActionReason =
    | 'order_cutoff_passed'
    | 'cancellation_deadline_passed'
    | 'serving_time_passed'
    | 'order_billed'
    | 'order_paid_at_checkout'
    | 'application_closed'
    | 'order_status'
    | 'no_deadline_basis';

/** What the applicant may do with an order RIGHT NOW, as decided by the server. */
export interface IHospitalityOrderAction {
    allowed: boolean;
    reason: HospitalityActionReason | null;
    /** Last instant the action is open (ISO-8601); when refused, the instant that closed it. */
    until: string | null;
}

/** Who to contact about an order once it is locked. */
export interface IHospitalityOrderContact {
    /** The kitchen resource's own contact_info, as stored (HTML-ish free text). */
    info: string | null;
    building_name: string | null;
    building_email: string | null;
    building_phone: string | null;
}

export interface IHospitalityOrder {
    id: number;
    application_id: number;
    hospitality_id: number;
    location_resource_id: number;
    status: 'pending' | 'confirmed' | 'cancelled' | 'delivered';
    comment: string | null;
    special_requirements: string | null;
    serving_time_iso: string | null;
    hospitality_name: string;
    location_name: string;
    total_amount: number;
    /** Always present from GET (an order without lines comes back as []). */
    lines: IHospitalityOrderLine[];
    /** Only on GET /hospitality-orders; the write endpoints do not return it. */
    actions?: { change: IHospitalityOrderAction; cancel: IHospitalityOrderAction };
    contact?: IHospitalityOrderContact;
}

export interface IHospitalityOrderLine {
    id: number;
    order_id: number;
    hospitality_article_id: number;
    quantity: string;
    unit_price: string;
    tax_code: number;
    amount: string;
    comment: string | null;
    article_name: string;
    unit: string;
}

export interface CreateHospitalityOrderRequest {
    hospitality_id: number;
    location_resource_id: number;
    serving_time_iso?: string;
    comment?: string;
    special_requirements?: string;
    lines: { hospitality_article_id: number; quantity: number; comment?: string }[];
}

export interface UpdateHospitalityOrderRequest {
    comment?: string;
    special_requirements?: string;
    serving_time_iso?: string;
    location_resource_id?: number;
    lines?: { hospitality_article_id: number; quantity: number; comment?: string }[];
}
