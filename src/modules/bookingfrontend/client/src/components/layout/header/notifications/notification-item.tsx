'use client'
import React, {FC} from 'react';
import {Paragraph} from "@digdir/designsystemet-react";
import {BellIcon, ChatIcon, FileTextIcon, CalendarIcon} from "@navikt/aksel-icons";
import Link from "next/link";
import TimeAgo from 'timeago-react';
import * as timeago from 'timeago.js';
import nb from 'timeago.js/lib/lang/nb_NO';
import nn from 'timeago.js/lib/lang/nn_NO';
import en from 'timeago.js/lib/lang/en_US';
import {INotification} from "@/service/types/api/notification.types";
import {useTrans, useClientTranslation} from "@/app/i18n/ClientTranslationProvider";
import {renderNotificationTitle, notificationDate} from "./notification-utils";
import styles from "./notifications.module.scss";

// Same locale registration as application-comment.tsx so TimeAgo renders in NB/NN/EN.
timeago.register('no', nb);
timeago.register('nn', nn);
timeago.register('en', en);

interface NotificationItemProps {
	notification: INotification;
	onSelect: (notification: INotification) => void;
}

/** Pick a leading icon from the notification's source/entity type. */
const iconFor = (notification: INotification) => {
	const key = `${notification.source_type} ${notification.entity_type}`.toLowerCase();
	if (key.includes('comment')) return ChatIcon;
	if (key.includes('application')) return FileTextIcon;
	if (key.includes('event') || key.includes('booking') || key.includes('allocation')) return CalendarIcon;
	return BellIcon;
};

const NotificationItem: FC<NotificationItemProps> = ({notification, onSelect}) => {
	const t = useTrans();
	const {i18n} = useClientTranslation();

	const title = renderNotificationTitle(notification, t);
	const Icon = iconFor(notification);
	const isUnread = !notification.is_read;

	const className = `${styles.item} ${isUnread ? styles.itemUnread : ''}`;

	const inner = (
		<>
			<span className={styles.avatar} aria-hidden={true}>
				<Icon fontSize="1.25rem"/>
			</span>
			<span className={styles.body}>
				<span className={styles.titleRow}>
					<Paragraph data-size="sm" className={styles.title}>{title}</Paragraph>
					{isUnread && <span className={styles.dot} aria-hidden={true}/>}
				</span>
				{notification.message && (
					<Paragraph data-size="sm" className={styles.message}>
						{notification.message}
					</Paragraph>
				)}
				<Paragraph data-size="xs" className={styles.time}>
					<TimeAgo datetime={notificationDate(notification.created)} locale={i18n.language}/>
				</Paragraph>
			</span>
		</>
	);

	// Navigable items render as a next/link; non-navigable ones as a button so
	// the row is still keyboard-operable and can mark itself read.
	if (notification.link) {
		return (
			<Link
				href={notification.link}
				className={className}
				onClick={() => onSelect(notification)}
				aria-label={title}
			>
				{inner}
			</Link>
		);
	}

	return (
		<button type="button" className={className} onClick={() => onSelect(notification)} aria-label={title}>
			{inner}
		</button>
	);
};

export default NotificationItem;
