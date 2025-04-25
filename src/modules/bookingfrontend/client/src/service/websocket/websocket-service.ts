'use client';

import {
	WebSocketMessage,
	WebSocketStatus,
	ServiceWorkerWebSocketOptions,
	WebSocketServiceEvent
} from './websocket.types';

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

	// Singleton pattern
	static getInstance(): WebSocketService {
		if (!WebSocketService.instance) {
			WebSocketService.instance = new WebSocketService();
		}
		return WebSocketService.instance;
	}

	private constructor() {
		// Private constructor to enforce singleton pattern

		// Make sure we're in a browser environment
		if (typeof window !== 'undefined') {
			// Check for service worker support
			if ('serviceWorker' in navigator) {
				// Set up event listener for service worker messages
				this.setupEventListener();

				// Check if service worker API is properly initialized
				if (!navigator.serviceWorker.ready) {
					console.warn('Service Worker API not ready yet');
				}
			} else {
				console.warn('Service Workers are not supported in this browser');
			}
		} else {
			console.warn('Not in browser environment, WebSocket service will be limited');
		}
	}

	// Set up event listener for service worker messages
	private setupEventListener() {
		if (typeof window !== 'undefined') {
			navigator.serviceWorker?.addEventListener('message', (event) => {
				const message = event.data;

				if (message.type === 'websocket_status') {
					this.status = message.status;
					this.dispatchEvent('status', {status: message.status});
				} else if (message.type === 'websocket_message') {
					this.lastMessage = message.data;
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
			console.log('WebSocket service already initialized, skipping redundant initialization (StrictMode protection)');
			return true;
		}

		if (typeof window === 'undefined') {
			console.error('Cannot initialize WebSocket service worker in a non-browser environment');
			return false;
		}

		if (!('serviceWorker' in navigator)) {
			console.error('Service Workers are not supported in this browser');
			return false;
		}

		try {
			// First check if the service worker is already registered
			const registrations = await navigator.serviceWorker.getRegistrations();
			const existingRegistration = registrations.find(
				registration => registration.active?.scriptURL.includes('websocket-sw.js')
			);

			if (existingRegistration) {
				console.log('WebSocket Service Worker already registered');
				this.serviceWorkerRegistration = existingRegistration;
			} else {
				try {
					// Register a new service worker
					this.serviceWorkerRegistration = await navigator.serviceWorker.register('/websocket-sw.js', {
						scope: '/'
					});
					console.log('WebSocket Service Worker registered with scope:', this.serviceWorkerRegistration.scope);
				} catch (registerError) {
					console.error('Failed to register WebSocket service worker:', registerError);
					// Try to fetch the service worker file to see if it exists
					try {
						const response = await fetch(`${this.basePath}/websocket-sw.js`);
						if (!response.ok) {
							console.error('Service worker file not found or not accessible:', response.status, response.statusText);
						}
					} catch (fetchError) {
						console.error('Could not fetch service worker file:', fetchError);
					}
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
				console.log('No service worker controller yet, waiting for controllerchange event');

				// Set up a promise that resolves when controller is available
				const controllerPromise = new Promise<boolean>((resolve) => {
					const timeout = setTimeout(() => {
						console.error('Service worker controller change timed out');
						resolve(false);
					}, 3000); // 3 second timeout

					navigator.serviceWorker.addEventListener('controllerchange', () => {
						clearTimeout(timeout);
						console.log('Service worker controller now available');
						this.connectToServiceWorker(options);
						this.isInitialized = true;
						resolve(true);
					});
				});

				// Wait for controller or timeout
				if (!(await controllerPromise)) {
					// Even if controller doesn't change, we'll still try to proceed
					console.warn('Proceeding without confirmed service worker controller');
				}
			}

			return this.isInitialized;
		} catch (error) {
			console.error('Service Worker initialization failed:', error);
			return false;
		}
	}

	// Connect to the service worker and initialize WebSocket
	private connectToServiceWorker(options: ServiceWorkerWebSocketOptions) {
		try {
			if (!navigator.serviceWorker.controller) {
				console.warn('No service worker controller available yet');

				// If no controller is available, wait for it with a timeout
				const timeout = setTimeout(() => {
					console.error('Controllerchange event never fired, trying direct connection anyway');
					this.attemptDirectConnection(options);
				}, 3000);

				navigator.serviceWorker.addEventListener('controllerchange', () => {
					clearTimeout(timeout);
					console.log('Controller now available, sending connect message');
					this.sendMessageToServiceWorker({
						type: 'connect',
						url: options.url,
						reconnectInterval: options.reconnectInterval,
						pingInterval: options.pingInterval
					});
				});
			} else {
				// Send connect message to service worker
				console.log('Service worker controller available, sending connect message');
				this.sendMessageToServiceWorker({
					type: 'connect',
					url: options.url,
					reconnectInterval: options.reconnectInterval,
					pingInterval: options.pingInterval
				});
			}
		} catch (error) {
			console.error('Error connecting to service worker:', error);
		}
	}

	// Attempt a direct connection as a fallback
	private attemptDirectConnection(options: ServiceWorkerWebSocketOptions) {
		try {
			if (navigator.serviceWorker.controller) {
				this.sendMessageToServiceWorker({
					type: 'connect',
					url: options.url,
					reconnectInterval: options.reconnectInterval,
					pingInterval: options.pingInterval
				});
			} else {
				console.error('Still no service worker controller available');
			}
		} catch (error) {
			console.error('Error in direct connection attempt:', error);
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
									console.log('Message sent to service worker via registration');
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
}