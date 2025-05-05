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
import {WEBSOCKET_CLIENT_DEBUG, wsLog as wslogbase} from './util';

const wsLog = (message: string, data: any = null) => wslogbase('WSService', message, data)

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
				} else if (message.type === 'ack') {
					// Handle service worker acknowledgment
					// This is for keepalive tracking and diagnostic purposes
					wsLog(`Received acknowledgment from service worker`, {
						receivedType: message.receivedType,
						timestamp: message.timestamp,
						clientId: message.clientId,
						hasHeartbeatInfo: !!message.heartbeat_id
					});

					// Update heartbeat tracking if this is acknowledging a heartbeat ping
					if (message.heartbeat_id && message.heartbeat_count && typeof sessionStorage !== 'undefined') {
						const lastHeartbeatKey = `lastHeartbeat_${message.heartbeat_id}`;
						sessionStorage.setItem(lastHeartbeatKey, message.heartbeat_count.toString());

						// Reset missed heartbeats counter
						if (parseInt(sessionStorage.getItem('missedHeartbeats') || '0', 10) > 0) {
							sessionStorage.setItem('missedHeartbeats', '0');
							wsLog('Reset missed heartbeats counter after successful acknowledgment');
						}
					}

					// We're removing the auto-ping response from acknowledgments
					// This was causing a ping flood by creating a feedback loop
					// The regular heartbeat interval is sufficient for keepalive
					/*
					if (!message.ack_response) {
						// Send delayed ping to avoid overwhelming the service worker
						setTimeout(() => {
							// Only send if we're still initialized
							if (this.isInitialized) {
								this.sendMessageToServiceWorker({
									type: 'ping',
									timestamp: new Date().toISOString(),
									ack_response: true
								});
							}
						}, 1000); // 1 second delay
					}
					*/
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

		// Firefox detection - check if the browser is Firefox
		const isFirefox = typeof navigator !== 'undefined' &&
			navigator.userAgent.toLowerCase().indexOf('firefox') > -1;

		// Log Firefox detection
		if (isFirefox && WEBSOCKET_CLIENT_DEBUG) {
			console.log('Firefox browser detected, using enhanced compatibility mode');
		}

		// Check if service worker is explicitly disabled in options
		if (options.disableServiceWorker) {
			console.log('Service Worker explicitly disabled by configuration - using direct WebSocket');
			this.dispatchEvent('status', {status: 'FALLBACK_REQUIRED'});
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
					// Register a new service worker with correct base path
					const swURL = `${this.basePath}/websocket-sw.js`;
					const scope = `${this.basePath}/`;
					console.log('Registering new service worker using scope:', scope);
					this.serviceWorkerRegistration = await navigator.serviceWorker.register(swURL, {
						scope: scope
					});
					if (WEBSOCKET_CLIENT_DEBUG) {
						console.log('WebSocket Service Worker registered with scope:', this.serviceWorkerRegistration.scope);
					}
				} catch (registerError) {
					console.error('Failed to register WebSocket service worker:', registerError);

					// Check if it's a security-related error
					const errorMessage = 'message' in (registerError as Object) ? (registerError as {
						message: string
					}).message : '';
					const isSecurityError = errorMessage.includes('security') ||
						errorMessage.includes('SSL') ||
						errorMessage.includes('certificate');

					if (isSecurityError) {
						console.warn('Falling back to direct WebSocket connection due to security/certificate issues');
					} else {
						console.warn('Falling back to direct WebSocket connection due to service worker registration failure');
					}

					// Try to verify the service worker file MIME type
					try {
						// Import the verifier function dynamically to avoid circular dependencies
						const {verifyServiceWorkerMimeType} = await import('./service-worker-fallback');
						const swUrl = `${this.basePath}/websocket-sw.js`;

						const isValid = await verifyServiceWorkerMimeType(swUrl);
						if (!isValid) {
							console.error('Service worker file has invalid MIME type or is not accessible');
							// Store the error in session storage so we can skip service worker on future page loads
							if (typeof sessionStorage !== 'undefined') {
								sessionStorage.setItem('sw_error', 'invalid_script');
							}
						}
					} catch (fetchError) {
						console.error('Could not verify service worker file:', fetchError);
						// Store the error in session storage
						if (typeof sessionStorage !== 'undefined') {
							sessionStorage.setItem('sw_error', 'fetch_error');
						}
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
					// Detect Firefox for extended timeout
					const isFirefox = typeof navigator !== 'undefined' &&
						navigator.userAgent.toLowerCase().indexOf('firefox') > -1;

					// Firefox needs longer timeout as it's less aggressive with service worker activation
					const timeoutDuration = isFirefox ? 20000 : 10000; // 20 seconds for Firefox, 10 for others

					if (isFirefox) {
						wsLog('Firefox detected - using extended service worker activation timeout', {
							timeoutDuration: `${timeoutDuration / 1000}s`
						});
					}

					const timeout = setTimeout(() => {
						wsLog('Service worker activation timed out, attempting to use existing registration');
						// Even if activation times out, we can still try to use the registration
						// if it's in the correct state
						if (this.serviceWorkerRegistration?.active) {
							wsLog('Found active service worker despite timeout, proceeding with connection', {
								scriptURL: this.serviceWorkerRegistration.active.scriptURL,
								state: this.serviceWorkerRegistration.active.state,
								timestamp: Date.now()
							});
							this.connectToServiceWorker(options);
							this.isInitialized = true;
							resolve(true);
						} else {
							wsLog('Service worker activation timed out and no active registration', {
								registrations: 'Checking existing registrations...',
								browser: isFirefox ? 'Firefox' : 'Other'
							});

							// Try to find any available registrations as a last resort
							navigator.serviceWorker.getRegistrations().then(regs => {
								wsLog('Found service worker registrations:', regs.length > 0 ?
									regs.map(r => ({
										scriptURL: r.active?.scriptURL,
										state: r.active?.state,
										installing: !!r.installing,
										waiting: !!r.waiting
									})) : 'none');
							});

							// For Firefox, we'll try a more aggressive recovery approach
							if (isFirefox) {
								wsLog('Firefox-specific recovery: attempting to connect anyway');
								// This is risky but necessary for Firefox - we'll try connecting even without a fully activated service worker
								this.connectToServiceWorker(options);
								this.isInitialized = true;
								resolve(true);
								return;
							}

							// For other browsers, signal fallback is needed
							this.dispatchEvent('status', {status: 'FALLBACK_REQUIRED'});
							resolve(false);
						}
					}, timeoutDuration);

					// Check if it's already active, which could happen in some browsers
					if (this.serviceWorkerRegistration?.active) {
						wsLog('Service worker already active, no need to wait for activation', {
							scriptURL: this.serviceWorkerRegistration.active.scriptURL,
							state: this.serviceWorkerRegistration.active.state,
							timestamp: Date.now()
						});
						clearTimeout(timeout);
						this.connectToServiceWorker(options);
						this.isInitialized = true;
						resolve(true);
						return;
					}

					// Otherwise wait for activation event
					this.serviceWorkerRegistration?.addEventListener('activate', (event) => {
						wsLog('Service worker activated via event listener', {
							timestamp: Date.now(),
							event: 'activate'
						});

						// Try to get details about the activated worker
						if (this.serviceWorkerRegistration?.active) {
							wsLog('Activated worker details', {
								scriptURL: this.serviceWorkerRegistration.active.scriptURL,
								state: this.serviceWorkerRegistration.active.state
							});
						}

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
					// Check if controller is already available
					if (navigator.serviceWorker.controller) {
						console.log('Service worker controller already available, proceeding immediately');
						this.connectToServiceWorker(options);
						this.isInitialized = true;
						resolve(true);
						return;
					}

					// Increase timeout to 5 seconds
					const timeout = setTimeout(() => {
						wsLog('Service worker controller change timed out, attempting to proceed anyway', {
							timeout: 5000,
							hasController: !!navigator.serviceWorker.controller,
							controller: navigator.serviceWorker.controller ? {
								scriptURL: navigator.serviceWorker.controller.scriptURL,
								state: navigator.serviceWorker.controller.state
							} : null
						});

						// Check for any registrations that might be available
						navigator.serviceWorker.getRegistrations().then(regs => {
							wsLog('Available service worker registrations after controller timeout:',
								regs.map(r => ({
									active: r.active ? {
										scriptURL: r.active.scriptURL,
										state: r.active.state
									} : null,
									installing: !!r.installing,
									waiting: !!r.waiting,
									scope: r.scope
								}))
							);
						});

						// Even if controller change times out, try forcing a connection
						this.connectToServiceWorker(options);
						this.isInitialized = true;
						resolve(true);
					}, 5000); // 5 second timeout

					navigator.serviceWorker.addEventListener('controllerchange', () => {
						clearTimeout(timeout);
						wsLog('Service worker controller now available via event');

						// Get and log controller information for debugging
						const controller = navigator.serviceWorker.controller;
						if (controller) {
							wsLog('Controller details:', {
								scriptURL: controller.scriptURL,
								state: controller.state,
								timestamp: Date.now()
							});
						} else {
							wsLog('Controller event fired but controller is null/undefined!');
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

		// Check if we have persistent service worker errors
		if (typeof sessionStorage !== 'undefined' && sessionStorage.getItem('sw_error')) {
			console.log('Cached service worker error found, not checking connection status');
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
		wsLog('Attempting to connect to service worker with options:', options);
		try {
			// Check if connection is already active before attempting to connect
			wsLog('Checking connection status before attempting to connect');
			const status = await this.checkConnectionStatus();
			wsLog(`Connection status check result: ${status}`);
			if (status === 'OPEN') {
				wsLog('Existing WebSocket connection detected, skipping connection request');
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

		const heartbeatId = Math.random().toString(36).substring(2, 10);
		let heartbeatCount = 0;
		const heartbeatInterval = 30000; // 30 seconds (increased from 15 seconds to reduce message frequency)

		// Log heartbeat setup
		wsLog(`Starting client heartbeat ${heartbeatId} with interval ${heartbeatInterval / 1000}s`);

		// Send a ping every 30 seconds (less than the 3-minute inactive threshold)
		this.heartbeatInterval = setInterval(() => {
			if (this.isInitialized && this.status !== 'CLOSED') {
				heartbeatCount++;

				// Add more detailed heartbeat information
				const heartbeatMessage = {
					type: 'ping',
					heartbeat_id: heartbeatId,
					count: heartbeatCount,
					timestamp: new Date().toISOString()
				};

				wsLog(`Sending client heartbeat #${heartbeatCount}`, heartbeatMessage);

				// Track last heartbeat acknowledged status
				const lastHeartbeatKey = `lastHeartbeat_${heartbeatId}`;
				let missedHeartbeats = 0;

				// Check if we're missing too many heartbeats
				if (typeof sessionStorage !== 'undefined') {
					const missedCount = sessionStorage.getItem('missedHeartbeats') || '0';
					missedHeartbeats = parseInt(missedCount, 10);

					if (missedHeartbeats > 3) {
						wsLog(`Too many missed heartbeats (${missedHeartbeats}), attempting reconnection`, {
							heartbeatId,
							timestamp: new Date().toISOString()
						});

						// Reset counter
						sessionStorage.setItem('missedHeartbeats', '0');

						// Force reconnection
						this.reconnect();
						return;
					}
				}

				// Set up a one-time handler to check if heartbeat is acknowledged
				setTimeout(() => {
					// If last heartbeat timestamp doesn't match, increment missed count
					if (typeof sessionStorage !== 'undefined') {
						const lastHeartbeat = sessionStorage.getItem(lastHeartbeatKey);
						if (lastHeartbeat !== heartbeatCount.toString()) {
							// Increment missed heartbeats
							sessionStorage.setItem('missedHeartbeats', (missedHeartbeats + 1).toString());
							wsLog(`Heartbeat #${heartbeatCount} not acknowledged, missed count: ${missedHeartbeats + 1}`);
						}
					}
				}, 5000); // Check after 5 seconds

				// Send the heartbeat
				this.sendMessageToServiceWorker(heartbeatMessage);

				// Store the sent heartbeat count
				if (typeof sessionStorage !== 'undefined') {
					sessionStorage.setItem(lastHeartbeatKey, heartbeatCount.toString());
				}
			} else {
				// If we're disconnected, stop the heartbeat
				if (this.heartbeatInterval) {
					wsLog(`Stopping heartbeat ${heartbeatId} after ${heartbeatCount} beats - connection closed`);
					clearInterval(this.heartbeatInterval);
					this.heartbeatInterval = null;
				}
			}
		}, heartbeatInterval);
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
			// First, check if navigator.serviceWorker exists
			if (typeof navigator === 'undefined' || !navigator.serviceWorker) {
				// In direct WebSocket mode or when service worker API is not available
				console.warn('Service Worker API is not available - using direct WebSocket');
				
				// Ensure the message is properly routed in direct WebSocket mode
				this.dispatchEvent('direct_message', {
					type: 'direct_message',
					data: message
				});
				
				return true;
			}
			
			// Normal service worker code path
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
			
			// In case of error, try to dispatch as direct message as fallback
			if (typeof window !== 'undefined' && 
				// @ts-ignore - This property is dynamically added by WebSocketContext in direct mode
				window.__directWebSocketRef) {
				this.dispatchEvent('direct_message', {
					type: 'direct_message',
					data: message
				});
				return true;
			}
			
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
		// Special case: If this is a 'subscribe' message and the WebSocket is actually OPEN,
		// we should consider the service initialized even if the flag isn't set yet.
		// This fixes the bug where direct WebSocket connections work but isInitialized isn't set.
		if (type === 'subscribe' && this.status === 'OPEN' && !this.isInitialized) {
			wsLog('WebSocket is OPEN but not marked as initialized. Setting initialized flag.', {
				type,
				status: this.status,
				isInitialized: false
			});
			this.isInitialized = true;
		}

		if (!this.isInitialized) {
			console.error('WebSocket service not initialized');
			// Store subscriptions for later even if not initialized
			if (type === 'subscribe' && 'entityType' in additionalData && 'entityId' in additionalData) {
				const entityType = additionalData.entityType;
				const entityId = additionalData.entityId;
				wsLog(`Storing subscription to ${entityType} ${entityId} for when service is ready`);
				this.pendingSubscriptions.push({
					entityType,
					entityId
				});
			}
			return false;
		}

		const data = {
			type,
			message,
			...additionalData,
			timestamp: new Date().toISOString()
		};

		wsLog('Sending message:', {
			type,
			message,
			...additionalData,
			timestamp: new Date().toISOString()
		});

		// Check for direct WebSocket mode in these cases:
		// 1. We're in direct WebSocket mode if window.__directWebSocketRef exists
		// 2. navigator.serviceWorker is undefined (no SW support)
		// 3. navigator.serviceWorker exists but no controller is available
		const dispatchDirectly = 
			// Check if we're in direct WebSocket mode by seeing if window.__directWebSocketRef exists
			(typeof window !== 'undefined' &&
				// @ts-ignore - This property is dynamically added by WebSocketContext in direct mode
				window.__directWebSocketRef) ||
			// Check if service worker API is not available
			(typeof navigator === 'undefined' || !navigator.serviceWorker) ||
			// Or if service worker controller is missing
			(typeof navigator !== 'undefined' &&
				'serviceWorker' in navigator &&
				!navigator.serviceWorker.controller);

		if (dispatchDirectly) {
			// In direct WebSocket mode, we dispatch an event that the WebSocketContext will handle
			wsLog('Using direct event dispatch in direct WebSocket mode', {type, data});

			// Dispatch the message to be handled by the WebSocketContext
			this.dispatchEvent('direct_message', {
				type: 'direct_message',
				data: data
			});
			
			return true;
		} else {
			// Normal service worker mode
			this.sendMessageToServiceWorker({
				type: 'send',
				data
			});
			return true;
		}
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
		// If the connection is OPEN but not marked as initialized, fix that
		// This happens with direct WebSocket connections that don't properly set isInitialized
		if (this.status === 'OPEN' && !this.isInitialized) {
			wsLog('WebSocket is OPEN but not marked as initialized. Auto-correcting state.', {
				status: this.status,
				isInitialized: false
			});
			this.isInitialized = true;

			// Check if we have any pending subscriptions that should be processed now
			if (this.pendingSubscriptions.length > 0) {
				wsLog(`Processing ${this.pendingSubscriptions.length} pending subscriptions after auto-correction`, {
					subscriptions: this.pendingSubscriptions
				});

				// Clone the pending subscriptions array to avoid modification during iteration
				const subscriptionsToProcess = [...this.pendingSubscriptions];
				this.pendingSubscriptions = []; // Clear pending queue

				// Process each subscription with a small delay to avoid overwhelming the socket
				subscriptionsToProcess.forEach((sub, index) => {
					setTimeout(() => {
						this.sendMessage('subscribe', `Processing deferred subscription to ${sub.entityType} ${sub.entityId}`, {
							entityType: sub.entityType,
							entityId: sub.entityId
						});
					}, index * 50); // 50ms delay between each subscription
				});
			}
		}
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
			this.pendingSubscriptions.push({entityType, entityId});
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
		// Detect Firefox for special handling
		const isFirefox = typeof navigator !== 'undefined' &&
			navigator.userAgent.toLowerCase().indexOf('firefox') > -1;

		// Track resubscription attempts to prevent infinite loops in Firefox
		if (typeof window !== 'undefined' && typeof sessionStorage !== 'undefined') {
			const resubAttemptKey = 'wsResubscriptionAttempts';
			const now = Date.now();
			const lastAttemptTimeKey = 'wsLastResubscriptionAttempt';
			const lastAttemptTime = parseInt(sessionStorage.getItem(lastAttemptTimeKey) || '0', 10);
			const timeSinceLastAttempt = now - lastAttemptTime;

			// Reset counter if it's been more than 2 minutes since last attempt
			if (timeSinceLastAttempt > 120000) {
				sessionStorage.setItem(resubAttemptKey, '1');
			} else {
				// Increment attempt counter
				const attempts = parseInt(sessionStorage.getItem(resubAttemptKey) || '0', 10) + 1;
				sessionStorage.setItem(resubAttemptKey, attempts.toString());

				// If too many attempts in a short period, especially in Firefox, throttle
				if (attempts > 10 && isFirefox) {
					wsLog(`Firefox detected with ${attempts} resubscription attempts in ${timeSinceLastAttempt / 1000}s - throttling`, {
						timeSinceLastAttempt: `${timeSinceLastAttempt}ms`,
						browser: 'Firefox'
					});

					// Don't resubscribe - we're in a resubscription loop
					return;
				}
			}

			// Update last attempt time
			sessionStorage.setItem(lastAttemptTimeKey, now.toString());
		}

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
			console.log(`Resubscribing to ${allSubscriptions.length} rooms${isFirefox ? ' (Firefox)' : ''}`);
		}

		// For Firefox, we'll use a much longer delay between subscriptions to prevent issues
		const subscriptionDelay = isFirefox ? 300 : 50; // 300ms for Firefox, 50ms for others

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

				// Schedule next subscription with browser-specific delay
				setTimeout(() => {
					resubscribeWithDelay(index + 1);
				}, subscriptionDelay);
			};

			// Start the sequential resubscription with a small initial delay for Firefox
			setTimeout(() => {
				resubscribeWithDelay(0);
			}, isFirefox ? 500 : 0);
		}
	}
}