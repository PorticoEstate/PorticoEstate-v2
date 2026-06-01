/**
 * Notification domain types for the bookingfrontend client.
 *
 * Mirrors the REST contract exposed by the backend:
 *   GET /bookingfrontend/notifications?unread=&limit=&offset=
 *   GET /bookingfrontend/notifications/unread-count
 *   PUT /bookingfrontend/notifications/{entity_type}/{entity_id}/mark-read
 */

export interface INotificationData {
	/** When true, `title` is a translation key (e.g. "new_comment_notification"). */
	title_is_key?: boolean;
	/** Positional params substituted into the resolved title (%1, %2, ...). */
	title_params?: Record<string, string>;
	/** Allow extra, forward-compatible fields without breaking the type. */
	[key: string]: unknown;
}

export interface INotification {
	id: number;
	source_type: string;
	source_id: number;
	entity_type: string;
	entity_id: number;
	/** A translation KEY when data.title_is_key is true, otherwise literal text. */
	title: string;
	/** Literal preview text. */
	message: string | null;
	/** e.g. "/user/applications/83728". */
	link: string | null;
	is_read: boolean;
	read_at: string | null;
	data: INotificationData | null;
	/** ISO-ish timestamp. */
	created: string;
}

export interface INotificationListResponse {
	notifications: INotification[];
	total: number;
	limit: number;
	offset: number;
}

export interface IUnreadCountApplication {
	application_id: number;
	unread_count: number;
}

export interface IUnreadCountResponse {
	total_unread: number;
	applications: IUnreadCountApplication[];
}

export interface IMarkReadResponse {
	status: string;
	updated: number;
}
