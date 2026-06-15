'use client'
import React, {FC, useMemo, useState} from 'react';
import {Button, Heading, Spinner, ToggleGroup} from "@digdir/designsystemet-react";
import {BellIcon} from "@navikt/aksel-icons";
import {useQueryClient} from "@tanstack/react-query";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {
	useNotifications,
	useUnreadNotificationCount,
	markNotificationsAsRead,
	markNotificationGroupsAsRead,
} from "@/service/hooks/api-hooks";
import {INotification} from "@/service/types/api/notification.types";
import NotificationItem from "@/components/layout/header/notifications/notification-item";
import styles from "./notifications-page.module.scss";

type Filter = 'all' | 'unread';

const PAGE_SIZE = 20;

interface NotificationsPageClientProps {
}

const NotificationsPageClient: FC<NotificationsPageClientProps> = (props) => {
	const t = useTrans();
	const queryClient = useQueryClient();

	const [filter, setFilter] = useState<Filter>('all');
	// "Load more" grows the page size; react-query keeps previous data while refetching.
	const [limit, setLimit] = useState(PAGE_SIZE);

	const {data: unreadData} = useUnreadNotificationCount();
	const {data, isLoading, isFetching} = useNotifications({
		unread: filter === 'unread' ? true : undefined,
		limit,
	});

	const totalUnread = unreadData?.total_unread ?? 0;
	const notifications = useMemo(() => data?.notifications ?? [], [data]);
	const total = data?.total ?? 0;
	const hasMore = notifications.length < total;

	const invalidate = () => {
		queryClient.invalidateQueries({queryKey: ['unreadNotificationCount']});
		queryClient.invalidateQueries({queryKey: ['notifications']});
	};

	const handleSelect = async (notification: INotification) => {
		if (notification.is_read) return;
		try {
			await markNotificationsAsRead(notification.entity_type, notification.entity_id);
		} catch (error) {
			console.error('Failed to mark notification as read:', error);
		} finally {
			invalidate();
		}
	};

	const handleMarkAllRead = async () => {
		const unread = notifications.filter(n => !n.is_read);
		if (unread.length === 0) return;
		try {
			await markNotificationGroupsAsRead(unread);
		} catch (error) {
			console.error('Failed to mark all notifications as read:', error);
		} finally {
			invalidate();
		}
	};

	const changeFilter = (next: Filter) => {
		setFilter(next);
		setLimit(PAGE_SIZE);
	};

	return (
		<main className={styles.page}>
			<div className={styles.header}>
				<Heading level={1} data-size="md" className={styles.title}>
					{t('bookingfrontend.notifications_title')}
				</Heading>
				{totalUnread > 0 && (
					<Button variant="tertiary" data-size="sm" onClick={handleMarkAllRead}>
						{t('bookingfrontend.mark_all_read')}
					</Button>
				)}
			</div>

			<div className={styles.toolbar}>
				<ToggleGroup value={filter} onChange={(v) => changeFilter(v as Filter)} data-size="sm">
					<ToggleGroup.Item value="all">
						{t('bookingfrontend.all')}
					</ToggleGroup.Item>
					<ToggleGroup.Item value="unread">
						{t('bookingfrontend.unread')}{totalUnread > 0 ? ` (${totalUnread})` : ''}
					</ToggleGroup.Item>
				</ToggleGroup>
			</div>

			{isLoading ? (
				<div className={styles.loading}>
					<Spinner data-size="md" aria-label={t('common.loading')}/>
				</div>
			) : notifications.length > 0 ? (
				<>
					<ul className={styles.list}>
						{notifications.map((notification) => (
							<li key={notification.id}>
								<NotificationItem notification={notification} onSelect={handleSelect}/>
							</li>
						))}
					</ul>
					{hasMore && (
						<div className={styles.loadMore}>
							<Button
								variant="secondary"
								data-size="sm"
								onClick={() => setLimit((l) => l + PAGE_SIZE)}
								loading={isFetching}
							>
								{t('bookingfrontend.load_more')}
							</Button>
						</div>
					)}
				</>
			) : (
				<div className={styles.empty}>
					<BellIcon fontSize="2.5rem" aria-hidden={true}/>
					<p>{filter === 'unread'
						? t('bookingfrontend.no_unread_notifications')
						: t('bookingfrontend.no_notifications')}</p>
				</div>
			)}
		</main>
	);
}

export default NotificationsPageClient;
