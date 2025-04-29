'use client';

import {
	WebSocketMessage,
	WebSocketStatus,
	ServiceWorkerWebSocketOptions,
	WebSocketServiceEvent,
	IWSEntitySubscribeMessage,
	IWSEntityUnsubscribeMessage
} from './websocket.types';
import {
	SubscriptionManager,
	EntitySubscription,
	SubscriptionCallback
} from './subscription-manager';

// Toggle for client-side logging
export const WEBSOCKET_CLIENT_DEBUG = false;

// WebSocket Service Class
export class WebSocketService {
	private static instance: WebSocketService | null = null;
	private serviceWorkerRegistration: ServiceWorkerRegistration | null = null;
	private status: WebSocketStatus = 'CLOSED';
	private lastMessage: WebSocketMessage | null = null;
	private eventListeners: Map<string, ((event: any) => void)[]> = new Map();
	private messageChannel: MessageChannel | null = null;
	private isInitialized: boolean = false;
	private basePath = process.env.NEXT_PUBLIC_BASE_PATH || '';
	private subscriptionManager: SubscriptionManager;
	private pendingSubscriptions: EntitySubscription[] = [];
	private heartbeatInterval: NodeJS.Timeout | null = null;

	// Singleton pattern
	static getInstance(): WebSocketService {
		if (!WebSocketService.instance) {
			WebSocketService.instance = new WebSocketService();
		}
		return WebSocketService.instance;
	}

	private constructor() {
		// Private constructor to enforce singleton pattern
		this.subscriptionManager = SubscriptionManager.getInstance();

		// Make sure we're in a browser environment
		if (typeof window !== 'undefined') {
			// Check for service worker support
			if ('serviceWorker' in navigator) {
				// Set up event listener for service worker messages
				this.setupEventListener();

				// Check if service worker API is properly initialized
				if (!navigator.serviceWorker.ready && WEBSOCKET_CLIENT_DEBUG) {
					console.warn('Service Worker API not ready yet');
				}
			} else if (WEBSOCKET_CLIENT_DEBUG) {
				console.warn('Service Workers are not supported in this browser');
			}
		} else if (WEBSOCKET_CLIENT_DEBUG) {
			console.warn('Not in browser environment, WebSocket service will be limited');
		}
	}

	// Set up event listener for service worker messages
	private setupEventListener() {
		if (typeof window !== 'undefined') {
			navigator.serviceWorker?.addEventListener('message', (event) => {
				const message = event.data;

				if (message.type === 'websocket_status') {
					const oldStatus = this.status;
					this.status = message.status;
					this.dispatchEvent('status', {status: message.status});

					// If the connection was re-established, resubscribe to rooms
					if ((oldStatus !== 'OPEN' && message.status === 'OPEN') ||
					    (oldStatus === 'CLOSED' && message.status === 'OPEN') ||
					    (oldStatus === 'RECONNECTING' && message.status === 'OPEN')) {
						// Allow a short delay to ensure service worker is fully ready to process messages
						setTimeout(() => {
							this.resubscribeToRooms();
						}, 500);
					}
				} else if (message.type === 'websocket_message') {
					this.lastMessage = message.data;
					// Process the message through the subscription manager
					this.subscriptionManager.handleMessage(message.data);

					// Also dispatch to general event listeners
					this.dispatchEvent('message', {data: message.data});
				} else if (message.type === 'websocket_error') {
					this.dispatchEvent('error', {error: message.error});
				}
			});
		}
	}

	// Initialize the service worker for WebSocket
	async initialize(options: ServiceWorkerWebSocketOptions): Promise<boolean> {
		// Prevent duplicate initialization (React StrictMode protection)
		if (this.isInitialized) {
			if (WEBSOCKET_CLIENT_DEBUG) {
				console.log('WebSocket service already initialized, skipping redundant initialization (StrictMode protection)');
			}
			return true;
		}

		if (typeof window === 'undefined') {
			console.error('Cannot initialize WebSocket service worker in a non-browser environment');
			return false;
		}

		// Check for service worker support
		if (!('serviceWorker' in navigator)) {
			console.warn('Service Workers are not supported in this browser - falling back to direct WebSocket');
			// Signal that we need to use direct WebSocket connection
			this.dispatchEvent('status', {status: 'FALLBACK_REQUIRED'});
			return false;
		}

		try {
			// First check the connection status by sending a ping message
			// This will tell us if there's already an active connection in another tab
			const connectionStatus = await this.checkConnectionStatus();
			if (connectionStatus === 'OPEN') {
				if (WEBSOCKET_CLIENT_DEBUG) {
					console.log('WebSocket connection is already active in another tab, joining existing connection');
				}
				this.status = 'OPEN';
				this.isInitialized = true;

				// Resubscribe to any rooms we need
				setTimeout(() => {
					this.resubscribeToRooms();
				}, 500);

				// Start client heartbeat to prevent disconnection
				this.startHeartbeat();

				return true;
			}

			// First check if the service worker is already registered
			const registrations = await navigator.serviceWorker.getRegistrations();
			const existingRegistration = registrations.find(
				registration => registration.active?.scriptURL.includes('websocket-sw.js')
			);

			if (existingRegistration) {
				if (WEBSOCKET_CLIENT_DEBUG) {
					console.log('WebSocket Service Worker already registered');
				}
				this.serviceWorkerRegistration = existingRegistration;
			} else {
				try {
					// Register a new service worker
					this.serviceWorkerRegistration = await navigator.serviceWorker.register('/websocket-sw.js', {
						scope: '/'
					});
					if (WEBSOCKET_CLIENT_DEBUG) {
						console.log('WebSocket Service Worker registered with scope:', this.serviceWorkerRegistration.scope);
					}
				} catch (registerError) {
					console.error('Failed to register WebSocket service worker:', registerError);
					
					// Check if it's a security-related error
					const errorMessage = registerError.message || '';
					const isSecurityError = errorMessage.includes('security') || 
						errorMessage.includes('SSL') || 
						errorMessage.includes('certificate');

					if (isSecurityError) {
						console.warn('Falling back to direct WebSocket connection due to security/certificate issues');
					} else {
						console.warn('Falling back to direct WebSocket connection due to service worker registration failure');
					}
					
					// Try to fetch the service worker file to see if it exists
					try {
						const response = await fetch(`${this.basePath}/websocket-sw.js`);
						if (!response.ok) {
							console.error('Service worker file not found or not accessible:', response.status, response.statusText);
						}
					} catch (fetchError) {
						console.error('Could not fetch service worker file:', fetchError);
					}
					
					// Signal that we need to use direct WebSocket connection
					this.dispatchEvent('status', {status: 'FALLBACK_REQUIRED'});
					return false;
				}
			}

			// Wait for the service worker to be activated
			if (this.serviceWorkerRegistration.active) {
				this.connectToServiceWorker(options);
				this.isInitialized = true;
			} else {
				// Set up a timeout for activation
				const activationPromise = new Promise<boolean>((resolve) => {
					const timeout = setTimeout(() => {
						console.error('Service worker activation timed out');
						// Signal fallback is needed
						this.dispatchEvent('status', {status: 'FALLBACK_REQUIRED'});
						resolve(false);
					}, 5000); // 5 second timeout

					this.serviceWorkerRegistration?.addEventListener('activate', () => {
						clearTimeout(timeout);
						this.connectToServiceWorker(options);
						this.isInitialized = true;
						resolve(true);
					});
				});

				// Wait for activation or timeout
				if (!(await activationPromise)) {
					return false;
				}
			}

			// Additional check for controller availability
			if (!navigator.serviceWorker.controller) {
				if (WEBSOCKET_CLIENT_DEBUG) {
					console.log('No service worker controller yet, waiting for controllerchange event');
				}

				// Set up a promise that resolves when controller is available
				const controllerPromise = new Promise<boolean>((resolve) => {
					const timeout = setTimeout(() => {
						console.error('Service worker controller change timed out');
						// Signal fallback is needed
						this.dispatchEvent('status', {status: 'FALLBACK_REQUIRED'});
						resolve(false);
					}, 3000); // 3 second timeout

					navigator.serviceWorker.addEventListener('controllerchange', () => {
						clearTimeout(timeout);
						if (WEBSOCKET_CLIENT_DEBUG) {
							console.log('Service worker controller now available');
						}
						this.connectToServiceWorker(options);
						this.isInitialized = true;
						resolve(true);
					});
				});

				// Wait for controller or timeout
				if (!(await controllerPromise)) {
					// Even if controller doesn't change, we'll still try to proceed
					if (WEBSOCKET_CLIENT_DEBUG) {
						console.warn('Proceeding without confirmed service worker controller');
					}
				}
			}

			return this.isInitialized;
		} catch (error) {
			console.error('Service Worker initialization failed:', error);
			// Signal that we need to use direct WebSocket connection
			this.dispatchEvent('status', {status: 'FALLBACK_REQUIRED'});
			return false;
		}
	}

	// Check if there's already an active WebSocket connection
	private async checkConnectionStatus(): Promise<WebSocketStatus> {
		if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
			return 'CLOSED';
		}

		// If no controller, connection cannot be open
		if (!navigator.serviceWorker.controller) {
			return 'CLOSED';
		}

		// Create a promise to handle the response
		return new Promise<WebSocketStatus>((resolve) => {
			// Set up a one-time message handler
			const messageHandler = (event: MessageEvent) => {
				if (event.data && event.data.type === 'websocket_status') {
					// Remove the event listener once we get a response
					navigator.serviceWorker.removeEventListener('message', messageHandler);
					resolve(event.data.status);
				}
			};

			// Set a timeout in case we don't get a response
			const timeout = setTimeout(() => {
				navigator.serviceWorker.removeEventListener('message', messageHandler);
				resolve('CLOSED');
			}, 1000);

			// Add the listener
			navigator.serviceWorker.addEventListener('message', messageHandler);

			// Send ping message to get current status
			if (navigator.serviceWorker.controller) {
				navigator.serviceWorker.controller.postMessage({
					type: 'ping'
				});
			} else {
				clearTimeout(timeout);
				resolve('CLOSED');
			}
		});
	}

	// Connect to the service worker and initialize WebSocket
	private async connectToServiceWorker(options: ServiceWorkerWebSocketOptions) {
		try {
			// Check if connection is already active before attempting to connect
			const status = await this.checkConnectionStatus();
			if (status === 'OPEN') {
				if (WEBSOCKET_CLIENT_DEBUG) {
					console.log('Existing WebSocket connection detected, skipping connection request');
				}
				this.status = 'OPEN';

				// Resubscribe after a short delay to ensure everything is ready
				setTimeout(() => {
					this.resubscribeToRooms();
				}, 500);

				// Start client heartbeat to prevent disconnection
				this.startHeartbeat();

				return;
			}

			if (!navigator.serviceWorker.controller) {
				if (WEBSOCKET_CLIENT_DEBUG) {
					console.warn('No service worker controller available yet');
				}

				// If no controller is available, wait for it with a timeout
				const timeout = setTimeout(() => {
					console.error('Controllerchange event never fired, trying direct connection anyway');
					this.attemptDirectConnection(options);
				}, 3000);

				navigator.serviceWorker.addEventListener('controllerchange', () => {
					clearTimeout(timeout);
					if (WEBSOCKET_CLIENT_DEBUG) {
						console.log('Controller now available, sending connect message');
					}
					this.sendMessageToServiceWorker({
						type: 'connect',
						url: options.url,
						reconnectInterval: options.reconnectInterval,
						pingInterval: options.pingInterval
					});

					// Start client heartbeat to prevent disconnection
					this.startHeartbeat();
				});
			} else {
				// Send connect message to service worker
				if (WEBSOCKET_CLIENT_DEBUG) {
					console.log('Service worker controller available, sending connect message');
				}
				this.sendMessageToServiceWorker({
					type: 'connect',
					url: options.url,
					reconnectInterval: options.reconnectInterval,
					pingInterval: options.pingInterval
				});

				// Start client heartbeat to prevent disconnection
				this.startHeartbeat();
			}
		} catch (error) {
			console.error('Error connecting to service worker:', error);
		}
	}

	// Attempt a direct connection as a fallback
	private async attemptDirectConnection(options: ServiceWorkerWebSocketOptions) {
		try {
			// Check if connection is already active before attempting to connect
			const status = await this.checkConnectionStatus();
			if (status === 'OPEN') {
				if (WEBSOCKET_CLIENT_DEBUG) {
					console.log('Existing WebSocket connection detected in fallback, skipping connection request');
				}
				this.status = 'OPEN';

				// Resubscribe after a short delay
				setTimeout(() => {
					this.resubscribeToRooms();
				}, 500);

				// Start client heartbeat to prevent disconnection
				this.startHeartbeat();

				return;
			}

			if (navigator.serviceWorker.controller) {
				this.sendMessageToServiceWorker({
					type: 'connect',
					url: options.url,
					reconnectInterval: options.reconnectInterval,
					pingInterval: options.pingInterval
				});

				// Start client heartbeat to prevent disconnection
				this.startHeartbeat();
			} else {
				console.error('Still no service worker controller available');
			}
		} catch (error) {
			console.error('Error in direct connection attempt:', error);
		}
	}

	// Start a heartbeat to keep the client active in the service worker
	private startHeartbeat() {
		// Clear any existing heartbeat
		if (this.heartbeatInterval) {
			clearInterval(this.heartbeatInterval);
		}

		// Send a ping every 30 seconds (less than the 2-minute inactive threshold)
		this.heartbeatInterval = setInterval(() => {
			if (this.isInitialized && this.status !== 'CLOSED') {
				if (WEBSOCKET_CLIENT_DEBUG) {
					console.log('Sending client heartbeat to prevent disconnection');
				}
				this.sendMessageToServiceWorker({
					type: 'ping'
				});
			} else {
				// If we're disconnected, stop the heartbeat
				if (this.heartbeatInterval) {
					clearInterval(this.heartbeatInterval);
					this.heartbeatInterval = null;
				}
			}
		}, 30000); // 30 seconds
	}

	// Stop the heartbeat
	private stopHeartbeat() {
		if (this.heartbeatInterval) {
			clearInterval(this.heartbeatInterval);
			this.heartbeatInterval = null;
		}
	}

	// Send a message to the service worker
	private sendMessageToServiceWorker(message: any): boolean {
		try {
			if (navigator.serviceWorker.controller) {
				navigator.serviceWorker.controller.postMessage(message);
				return true;
			} else {
				console.error('No active Service Worker controller found');

				// Try to send to all service worker registrations as a fallback
				navigator.serviceWorker.getRegistrations().then(registrations => {
					if (registrations.length > 0) {
						// If we have registrations but no controller, try sending to all active service workers
						let messageSent = false;

						registrations.forEach(registration => {
							if (registration.active) {
								try {
									registration.active.postMessage(message);
									if (WEBSOCKET_CLIENT_DEBUG) {
										console.log('Message sent to service worker via registration');
									}
									messageSent = true;
								} catch (err) {
									console.error('Error sending message to registration:', err);
								}
							}
						});

						return messageSent;
					}

					return false;
				}).catch(err => {
					console.error('Error getting service worker registrations:', err);
					return false;
				});

				return false;
			}
		} catch (error) {
			console.error('Error sending message to service worker:', error);
			return false;
		}
	}

	// Add event listener for WebSocket events
	addEventListener(type: string, callback: (event: any) => void): void {
		if (!this.eventListeners.has(type)) {
			this.eventListeners.set(type, []);
		}

		const listeners = this.eventListeners.get(type)!;
		if (!listeners.includes(callback)) {
			listeners.push(callback);
		}
	}

	// Remove event listener
	removeEventListener(type: string, callback: (event: any) => void): void {
		if (!this.eventListeners.has(type)) return;

		const listeners = this.eventListeners.get(type)!;
		const index = listeners.indexOf(callback);

		if (index !== -1) {
			listeners.splice(index, 1);
		}
	}

	// Dispatch an event to all listeners
	private dispatchEvent(type: string, data: any): void {
		if (!this.eventListeners.has(type)) return;

		const listeners = this.eventListeners.get(type)!;
		listeners.forEach(callback => {
			try {
				callback(data);
			} catch (error) {
				console.error(`Error in ${type} event listener:`, error);
			}
		});
	}

	// Send a message through the WebSocket
	sendMessage(type: string, message: string, additionalData: Record<string, any> = {}): boolean {
		if (!this.isInitialized) {
			console.error('WebSocket service not initialized');
			return false;
		}

		const data = {
			type,
			message,
			...additionalData,
			timestamp: new Date().toISOString()
		};

		this.sendMessageToServiceWorker({
			type: 'send',
			data
		});

		return true;
	}

	// Close the WebSocket connection
	close(): void {
		if (!this.isInitialized) return;

		// Stop the heartbeat
		this.stopHeartbeat();

		this.sendMessageToServiceWorker({
			type: 'disconnect'
		});
	}

	// Reconnect the WebSocket
	reconnect(options?: Partial<ServiceWorkerWebSocketOptions>): void {
		if (!this.isInitialized) return;

		// Close existing connection first
		this.close();

		// Connect with potentially new options
		this.sendMessageToServiceWorker({
			type: 'connect',
			url: options?.url || this.getDefaultWebSocketUrl(),
			reconnectInterval: options?.reconnectInterval,
			pingInterval: options?.pingInterval
		});
	}

	// Check the current status of the WebSocket
	getStatus(): WebSocketStatus {
		return this.status;
	}

	// Get the last received message
	getLastMessage(): WebSocketMessage | null {
		return this.lastMessage;
	}

	// Get default WebSocket URL based on current location
	private getDefaultWebSocketUrl(): string {
		if (typeof window === 'undefined') return '';

		const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
		const host = window.location.host;
		return `${protocol}//${host}/wss`;
	}

	// Check if the service is initialized
	isReady(): boolean {
		return this.isInitialized;
	}

	// Unregister the service worker (useful for development)
	async unregister(): Promise<boolean> {
		if (!this.serviceWorkerRegistration) return false;

		try {
			// Close connection first
			this.close();

			// Make sure heartbeat is stopped
			this.stopHeartbeat();

			// Unregister the service worker
			const result = await this.serviceWorkerRegistration.unregister();
			this.isInitialized = false;
			this.serviceWorkerRegistration = null;

			return result;
		} catch (error) {
			console.error('Failed to unregister Service Worker:', error);
			return false;
		}
	}

	/**
	 * Subscribe to a room (entity) to receive entity-specific events
	 * @param entityType The type of entity (e.g., 'resource', 'building')
	 * @param entityId The ID of the entity
	 * @param callback Optional callback function to handle entity events
	 * @returns Unsubscribe function if callback provided, otherwise undefined
	 */
	subscribeToRoom(
		entityType: string,
		entityId: number | string,
		callback?: SubscriptionCallback
	): (() => void) | undefined {
		// Send subscribe message to server
		this.sendMessage('subscribe', `Subscribing to ${entityType} ${entityId}`, {
			entityType,
			entityId
		});

		// Add to pending subscriptions if not connected
		if (this.status !== 'OPEN') {
			this.pendingSubscriptions.push({ entityType, entityId });
		}

		// Register callback if provided
		if (callback) {
			return this.subscriptionManager.subscribeToEntity(entityType, entityId, callback);
		}
	}

	// The unsubscribeFromRoom method has been removed.
	// The server detects inactive subscriptions via ping-pong mechanism.

	/**
	 * Subscribe to messages of a specific type
	 * @param messageType The type of message to subscribe to
	 * @param callback Callback function to handle messages of this type
	 * @returns Unsubscribe function
	 */
	subscribeToMessageType(
		messageType: string,
		callback: SubscriptionCallback
	): () => void {
		return this.subscriptionManager.subscribeToMessageType(messageType, callback);
	}

	/**
	 * Unsubscribe from messages of a specific type
	 * @param messageType The type of message
	 * @param callback The callback function to remove
	 */
	unsubscribeFromMessageType(
		messageType: string,
		callback: SubscriptionCallback
	): void {
		this.subscriptionManager.unsubscribeFromMessageType(messageType, callback);
	}

	/**
	 * Resubscribe to all rooms if connection was lost and reestablished
	 */
	private resubscribeToRooms(): void {
		// Get all active subscriptions from the manager
		const activeSubscriptions = this.subscriptionManager.getActiveEntitySubscriptions();

		// Add any pending subscriptions that might not have made it through
		let allSubscriptions = [...activeSubscriptions, ...this.pendingSubscriptions];

		// Remove duplicates (same entity type and ID)
		const uniqueSubscriptions = new Map<string, EntitySubscription>();
		allSubscriptions.forEach(sub => {
			const key = `${sub.entityType}:${sub.entityId}`;
			uniqueSubscriptions.set(key, sub);
		});

		allSubscriptions = Array.from(uniqueSubscriptions.values());

		// Clear pending subscriptions as we're going to resubscribe to all
		this.pendingSubscriptions = [];

		// Log resubscription attempt
		if (WEBSOCKET_CLIENT_DEBUG) {
			console.log(`Resubscribing to ${allSubscriptions.length} rooms`);
		}

		// Send subscribe messages for all subscriptions with slight delay between each
		// to prevent overwhelming the service worker
		if (allSubscriptions.length > 0) {
			const resubscribeWithDelay = (index: number) => {
				if (index >= allSubscriptions.length) return;

				const sub = allSubscriptions[index];
				this.sendMessage('subscribe', `Resubscribing to ${sub.entityType} ${sub.entityId}`, {
					entityType: sub.entityType,
					entityId: sub.entityId
				});

				// Schedule next subscription with a small delay
				setTimeout(() => {
					resubscribeWithDelay(index + 1);
				}, 50);
			};

			// Start the sequential resubscription
			resubscribeWithDelay(0);
		}
	}
}