import {IShortResource} from "@/service/pecalendar.types";
import {IDocument} from "@/service/types/api.types";
import {IResource} from "@/service/types/resource.types";
import {ArticleOrder} from "@/service/types/api/order-articles.types";

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
    building_id: number;
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
    documents: IDocument[];
    responsible_street: string;
    responsible_zip_code: string;
    responsible_city: string;
    session_id: string | null;
    agreement_requirements: string | null;
    external_archive_key: string | null;
    customer_organization_name: string | null;
    customer_organization_id: number | null;
    agegroups: IApplicationAgeGroup[];
    articles?: ArticleOrder[];
    application_type?: 'personal' | 'organization';
    recurring_info?: string | null; // JSON string of RecurringInfo
}

// Interface for the parsed recurring_info JSON
export interface RecurringInfo {
    repeat_until?: string; // ISO date string (YYYY-MM-DD)
    field_interval?: number; // Week intervals between repetitions (default: 1)
    outseason?: boolean; // Repeat until end of season, xor repeat_until / outseason
}

// Utility functions for handling recurring_info
export const RecurringInfoUtils = {
    parse: (recurring_info: string | null | undefined): RecurringInfo | null => {
        if (!recurring_info) return null;
        try {
            return JSON.parse(recurring_info);
        } catch {
            return null;
        }
    },

    stringify: (recurringInfo: RecurringInfo | null | undefined): string | null => {
        if (!recurringInfo) return null;
        return JSON.stringify(recurringInfo);
    },

    isRecurring: (application: IApplication): boolean => {
        return !!RecurringInfoUtils.parse(application.recurring_info);
    }
};

export interface IApplicationDate {
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

interface IApplicationAgeGroup {
    id: number;
    name: string;
    description: string | null;
    sort: number;
    male: number;
    female: number;
}


export interface NewPartialApplication extends Pick<IApplication, 'name' | 'building_name' | 'building_id' | 'activity_id' | 'organizer' | 'homepage' | 'description'>{
    dates: Array<{
        from_: string;    // ISO date string
        to_: string;      // ISO date string
    }>;
    resources: Array<number>;
    agegroups?: IApplicationAgeGroup[];
    audience?: number[];
	articles?: ArticleOrder[];
    recurring_info?: RecurringInfo; // For convenience, will be JSON.stringify'd
}
export interface IUpdatePartialApplication extends Partial<Omit<IApplication, 'dates' | 'resources' | 'recurring_info'>>{
    id: number;
    dates?: Array<{
        id?: number;
        from_: string;    // ISO date string
        to_: string;      // ISO date string
    }>;
    resources?: Array<IShortResource | IResource>;
    agegroups?: IApplicationAgeGroup[];
	articles?: ArticleOrder[];
    recurring_info?: RecurringInfo; // For convenience, will be JSON.stringify'd
}

export interface ApplicationComment {
    id: number;
    application_id: number;
    time: string; // ISO datetime string
    author: string;
    comment: string;
    type: "comment" | "ownership" | "status";
}

export interface CommentStats {
    total: number;
    by_type: {
        comment: number;
        ownership: number;
        status: number;
    };
}

export interface GetCommentsResponse {
    comments: ApplicationComment[];
    stats: CommentStats;
}

export interface AddCommentRequest {
    comment: string; // Required, max 10000 characters
    type?: "comment" | "ownership"; // Optional, defaults to "comment"
}

export interface AddCommentResponse {
    comment: ApplicationComment;
    message: string; // "Comment added successfully"
}

export interface UpdateStatusRequest {
    status: "NEW" | "PENDING" | "ACCEPTED" | "REJECTED" | "CANCELLED";
    comment?: string; // Optional additional comment, max 10000 characters
}

export interface UpdateStatusResponse {
    comments: ApplicationComment[]; // Status change comment(s) created
    status: string; // The new status
    message: string; // "Application status updated successfully"
}