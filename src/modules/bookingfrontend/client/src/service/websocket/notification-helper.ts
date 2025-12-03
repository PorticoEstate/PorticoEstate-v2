import {useEffect, useCallback} from 'react';
import {useWebSocketContext} from './websocket-context';
import type {WebSocketMessage} from './websocket.types';

/**
 * Hook to listen for specific WebSocket notification types
 *
 * @param type The notification type to listen for (e.g., 'application_update')
 * @param callback Function to call when a matching notification is received
 */
export const useWebSocketNotification = (
	type: string,
	callback: (data: any) => void
) => {
	const {lastMessage} = useWebSocketContext();

	useEffect(() => {
		if (lastMessage && lastMessage.type === 'notification' && lastMessage.notificationType === type) {
			callback(lastMessage);
		}
	}, [lastMessage, type, callback]);
};

/**
 * Hook to listen for WebSocket messages of a specific type
 *
 * @param messageType The message type to listen for (e.g., 'chat', 'notification')
 * @param callback Function to call when a matching message is received
 */
export const useWebSocketMessage = (
	messageType: string,
	callback: (data: any) => void
) => {
	const {lastMessage} = useWebSocketContext();

	useEffect(() => {
		if (lastMessage && lastMessage.type === messageType) {
			callback(lastMessage);
		}
	}, [lastMessage, messageType, callback]);
};

/**
 * Hook to send WebSocket notifications with a standard format
 *
 * @returns A function to send notifications through WebSocket
 */
export const useSendNotification = () => {
	const {sendMessage} = useWebSocketContext();

	const sendNotification = useCallback((
		notificationType: string,
		message: string,
		data: Record<string, any> = {}
	) => {
		return sendMessage('notification', message, {
			notificationType,
			...data
		});
	}, [sendMessage]);

	return sendNotification;
};

/**
 * Display a browser notification using the Notification API
 * This is separate from WebSockets but often used together
 *
 * @param title Notification title
 * @param options Notification options (body, icon, etc.)
 * @returns A Promise that resolves to true if notification was shown
 */
export async function showBrowserNotification(
	title: string,
	options: NotificationOptions = {}
): Promise<boolean> {
	// Check if browser supports notifications
	if (!('Notification' in window)) {
		console.error('This browser does not support notifications');
		return false;
	}

	// Check if we already have permission
	if (Notification.permission === 'granted') {
		// Create and show notification
		new Notification(title, options);
		return true;
	}

	// Request permission if not already granted
	if (Notification.permission !== 'denied') {
		const permission = await Notification.requestPermission();

		if (permission === 'granted') {
			new Notification(title, options);
			return true;
		}
	}

	return false;
}

/**
 * Process WebSocket messages and show browser notifications for important ones
 *
 * @param message The WebSocket message to process
 * @returns A Promise that resolves when processing is complete
 */
export async function processWebSocketMessageForNotification(
	message: WebSocketMessage
): Promise<void> {
	// Only process notification type messages
	if (message.type !== 'notification') return;

	// At this point TypeScript knows message is IWSNotificationMessage
	// Determine if this notification type should trigger a browser notification
	// You can customize this logic based on your application's needs
	const shouldNotify = (
		message.notificationType === 'application_update' ||
		message.notificationType === 'new_message' ||
		message.notificationType === 'booking_confirmation'
	);

	if (shouldNotify) {
		// Create a user-friendly title and body based on notification type
		let title = 'New Notification';
		let body = message.message || '';

		// Customize notification based on type
		switch (message.notificationType) {
			case 'application_update':
				title = 'Application Update';
				break;
			case 'new_message':
				title = 'New Message';
				break;
			case 'booking_confirmation':
				title = 'Booking Confirmed';
				break;
		}

		// Show the browser notification
		await showBrowserNotification(title, {
			body,
			icon: '/logo_aktiv_kommune.png',  // Use your app icon
			badge: '/favicon-32x32.png',       // Small icon for mobile devices
			// @ts-ignore
			vibrate: [200, 100, 200],          // Vibration pattern (mobile only)
			tag: message.notificationType,     // Group similar notifications
			requireInteraction: true,          // Keep notification visible until user interacts with it
			data: message                      // Store original message data
		});
	}
}