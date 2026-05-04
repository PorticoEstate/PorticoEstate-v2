export interface IHospitality {
    id: number;
    name: string;
    resource_id: number;
    resource_name: string;
    remote_serving_enabled: number;
    allow_on_site_hospitality: number;
    include_in_checkout_payment: number;
    order_by_time_value: number | null;
    order_by_time_unit: 'hours' | 'days' | null;
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
    unit: string;
    base_price: string;
    base_tax_code: number;
    effective_price: string;
    effective_tax_code: number;
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
    lines: IHospitalityOrderLine[];
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
