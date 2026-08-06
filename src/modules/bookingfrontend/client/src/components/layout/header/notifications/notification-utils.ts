'use client'
import {DateTime} from "luxon";
import {INotification} from "@/service/types/api/notification.types";

/**
 * Resolve a notification's display title.
 *
 * When `data.title_is_key` is true, `title` is a lang key (e.g.
 * "new_comment_notification" -> "%1 har skrevet en kommentar"). We resolve it
 * through the translation function and then substitute the positional
 * placeholders (%1, %2, ...) from `data.title_params`, because the lang strings
 * use phpGW-style %N placeholders rather than i18next interpolation.
 *
 * When `title_is_key` is false/absent, the title is rendered literally.
 */
export function renderNotificationTitle(
	notification: INotification,
	t: (key: string, options?: any) => string,
): string {
	const {title, data} = notification;

	if (!data?.title_is_key) {
		return title;
	}

	// Lang keys in this project live under the `bookingfrontend.` namespace.
	// getFixedT returns the key unchanged when it is missing, so fall back to
	// the bare key and finally the raw title.
	const namespaced = `bookingfrontend.${title}`;
	let resolved = t(namespaced);
	if (resolved === namespaced) {
		const bare = t(title);
		resolved = bare === title ? title : bare;
	}

	return substituteParams(resolved, data.title_params);
}

/**
 * Normalize a notification timestamp to an absolute instant.
 *
 * The bell receives `created` from two sources with different formats:
 *  - REST (`GET /bookingfrontend/notifications`): a naive SQL timestamp in UTC
 *    with no zone marker, e.g. "2026-07-01 08:57:28.88399" — the
 *    `bb_notification.created` column is a `timestamp` (no tz) and the database
 *    runs in UTC.
 *  - WebSocket (`notification_event`): an ISO-8601 string WITH an offset from
 *    PHP `date('c')`, e.g. "2026-07-01T08:57:28+00:00".
 *
 * timeago.js parses a marker-less string as *local* time, which renders the
 * REST timestamps 2h off in CEST. Parsing the naive value as UTC — while
 * respecting an explicit offset when one is present — yields the correct
 * instant for both sources.
 */
export function notificationDate(raw: string): Date {
	// ISO path (WebSocket): `setZone` respects an embedded offset; `zone: 'utc'`
	// is only the fallback zone for a marker-less ISO value.
	const iso = DateTime.fromISO(raw, {zone: 'utc', setZone: true});
	if (iso.isValid) {
		return iso.toJSDate();
	}
	// SQL path (REST): "YYYY-MM-DD HH:mm:ss[.SSS]" — naive UTC, space separator.
	const sql = DateTime.fromSQL(raw, {zone: 'utc'});
	return sql.isValid ? sql.toJSDate() : new Date(raw);
}

/**
 * Replace %1, %2, ... in `text` with values from `params`.
 * Accepts both "1" and "%1" as parameter keys for resilience.
 */
function substituteParams(text: string, params?: Record<string, string>): string {
	if (!params) return text;
	return text.replace(/%(\d+)/g, (match, n: string) => {
		if (params[n] !== undefined) return params[n];
		if (params[`%${n}`] !== undefined) return params[`%${n}`];
		return match;
	});
}
