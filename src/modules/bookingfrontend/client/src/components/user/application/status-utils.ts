/**
 * Shared status color mapping for application statuses.
 * Labels come from existing i18n keys via t(`bookingfrontend.${status.toLowerCase()}`).
 */

export type StatusColor = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

export function getStatusColor(status: string): StatusColor {
    const s = status.toLowerCase();
    switch (s) {
        case 'approved':
        case 'confirmed':
        case 'accepted':
        case 'completed':
            return 'success';
        case 'rejected':
        case 'denied':
        case 'failed':
            return 'danger';
        case 'pending':
        case 'under_review':
        case 'processing':
        case 'waiting':
            return 'warning';
        case 'new':
        case 'submitted':
        case 'received':
            return 'info';
        default:
            // CANCELLED, DRAFT, unknown → neutral
            return 'neutral';
    }
}

/** Filter key type used in the applications list chip filter */
export type FilterKey = 'all' | 'new' | 'pending' | 'accepted' | 'rejected' | 'cancelled';
