import React from 'react';
import NotificationsPageClient from './notifications-page-client';

/**
 * Full notification history, reached from the bell's "Se alle varsler" footer
 * link and the user-page "Varsler" tab.
 *
 * The list is client-fetched (react-query + WS invalidation), so this server
 * component is a thin wrapper — same split as the delegates page. The
 * (user-main) layout already gates on login and renders the tab nav.
 */
export default function NotificationsPage() {
	return <NotificationsPageClient/>;
}
