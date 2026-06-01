'use client'
import React, {FC, useEffect, useMemo, useRef, useState} from 'react';
import {Spinner} from "@digdir/designsystemet-react";
import {BellIcon, BellFillIcon} from "@navikt/aksel-icons";
import Link from "next/link";
import {useQueryClient} from "@tanstack/react-query";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {
	useNotifications,
	useUnreadNotificationCount,
	markNotificationsAsRead,
	markNotificationGroupsAsRead,
} from "@/service/hooks/api-hooks";
import {INotification} from "@/service/types/api/notification.types";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {useRouter} from "next/navigation";
import NotificationItem from "./notification-item";
import styles from "./notifications.module.scss";

interface NotificationBellProps {
}

const NotificationBell: FC<NotificationBellProps> = () => {
	const t = useTrans();
	const queryClient = useQueryClient();

	const isMobile = useIsMobile();
	const router = useRouter();

	const [open, setOpen] = useState(false);
	// Only fetch the list once the user has opened the bell at least once.
	const [hasOpened, setHasOpened] = useState(false);
	const wrapRef = useRef<HTMLDivElement>(null);

	const {data: unreadData} = useUnreadNotificationCount();
	const {data: listData, isLoading} = useNotifications({limit: 10, enabled: hasOpened});

	const totalUnread = unreadData?.total_unread ?? 0;
	const hasUnread = totalUnread > 0;
	const notifications = useMemo(() => listData?.notifications ?? [], [listData]);

	// Close on outside click + Escape.
	useEffect(() => {
		if (!open) return;
		const onClick = (e: MouseEvent) => {
			if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) {
				setOpen(false);
			}
		};
		const onKey = (e: KeyboardEvent) => {
			if (e.key === 'Escape') setOpen(false);
		};
		document.addEventListener('mousedown', onClick);
		document.addEventListener('keydown', onKey);
		return () => {
			document.removeEventListener('mousedown', onClick);
			document.removeEventListener('keydown', onKey);
		};
	}, [open]);

	const toggle = () => {
		// On mobile the bell lives inside the hamburger drawer where an inline
		// dropdown can't anchor; go straight to the full (mobile-optimized) page.
		if (isMobile) {
			router.push('/user/notifications');
			return;
		}
		setHasOpened(true);
		setOpen(prev => !prev);
	};

	const invalidate = () => {
		queryClient.invalidateQueries({queryKey: ['unreadNotificationCount']});
		queryClient.invalidateQueries({queryKey: ['notifications']});
	};

	const handleSelect = async (notification: INotification) => {
		setOpen(false);
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

	const badgeLabel = totalUnread > 9 ? '9+' : String(totalUnread);

	return (
		<div className={styles.bellWrap} ref={wrapRef}>
			<button
				type="button"
				className={styles.trigger}
				aria-label={t('bookingfrontend.notifications_title')}
				aria-haspopup="true"
				aria-expanded={open}
				onClick={toggle}
			>
				<span className={styles.badgeWrap}>
					{hasUnread
						? <BellFillIcon width="1.75rem" height="1.75rem" aria-hidden={true}/>
						: <BellIcon width="1.75rem" height="1.75rem" aria-hidden={true}/>}
					{hasUnread && (
						<span className={styles.badge} aria-hidden={true}>{badgeLabel}</span>
					)}
				</span>
			</button>

			{open && !isMobile && (
				<div className={styles.panel} role="dialog" aria-label={t('bookingfrontend.notifications_title')}>
					<div className={styles.panelHeader}>
						<h2 className={styles.panelTitle}>{t('bookingfrontend.notifications_title')}</h2>
						{hasUnread && (
							<button type="button" className={styles.markAll} onClick={handleMarkAllRead}>
								{t('bookingfrontend.mark_all_read')}
							</button>
						)}
					</div>

					{isLoading ? (
						<div className={styles.loading}>
							<Spinner data-size="sm" aria-label={t('common.loading')}/>
						</div>
					) : notifications.length > 0 ? (
						<ul className={styles.list}>
							{notifications.map((notification) => (
								<li key={notification.id}>
									<NotificationItem notification={notification} onSelect={handleSelect}/>
								</li>
							))}
						</ul>
					) : (
						<div className={styles.empty}>
							<BellIcon fontSize="1.75rem" aria-hidden={true}/>
							{t('bookingfrontend.no_notifications')}
						</div>
					)}

					<div className={styles.panelFooter}>
						<Link href="/user/notifications" className={styles.footerLink} onClick={() => setOpen(false)}>
							{t('bookingfrontend.see_all_notifications')}
						</Link>
					</div>
				</div>
			)}
		</div>
	);
};

export default NotificationBell;
