'use client'
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
