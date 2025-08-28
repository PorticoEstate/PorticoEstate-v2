'use client';

import {wsLog as wslogbase} from "@/service/websocket/util";

const wsLog = (message: string, data: any = null) => wslogbase('WSChecker', message, data)


/**
 * Comprehensive check for service worker support in the current environment
 * This function checks not just the existence of the API but whether it's
 * actually usable in the current context (HTTPS, permissions, etc.)
 */
export async function checkServiceWorkerSupport(disableServiceWorker?: boolean): Promise<{
	supported: boolean;
	reason?: string;
}> {
	if (typeof window === 'undefined') {
		return {supported: false, reason: 'Not in browser environment'};
	}

	// Check if service worker is explicitly disabled
	if (disableServiceWorker) {
		return {supported: false, reason: 'Service Worker explicitly disabled'};
	}

	// Check for basic API presence
	if (!('serviceWorker' in navigator)) {
		return {supported: false, reason: 'Service Worker API not available'};
	}

	// Check for secure context (required for service workers)
	if (window.isSecureContext === false) {
		return {
			supported: false,
			reason: 'Not in secure context (requires HTTPS or localhost)'
		};
	}

	// Check that navigator.serviceWorker is not null and has required methods
	if (!navigator.serviceWorker ||
		typeof navigator.serviceWorker.register !== 'function' ||
		typeof navigator.serviceWorker.getRegistrations !== 'function') {
		return {
			supported: false,
			reason: 'Service Worker API not fully implemented'
		};
	}

	try {
		// Check if we already have an active service worker for our scope
		// Service worker path is always fixed for this application
		const scope = '/bookingfrontend/client/';
		const wsSwUrl = new URL('/bookingfrontend/client/websocket-sw.js', window.location.origin).href;

		// Get all existing registrations
		const registrations = await navigator.serviceWorker.getRegistrations();
		let existingRegistration = null;

		// Look for existing registrations that match our scope
		for (const registration of registrations) {
			if (registration.scope.includes(scope)) {
				wsLog('Found existing service worker with matching scope:', registration.scope);
				existingRegistration = registration;
				break;
			}
		}

		// If we already have a registration, verify it's responding
		if (existingRegistration && existingRegistration.active) {
			wsLog('Using existing service worker registration');

			// Create a messaging channel to verify the service worker is responding
			const messageChannel = new MessageChannel();

			// Set up a promise to await the test response
			const testPromise = new Promise<boolean>((resolve, reject) => {
				// Time out after 2 seconds
				const timeout = setTimeout(() => {
					messageChannel.port1.onmessage = null;
					reject(new Error('Service worker test timed out'));
				}, 2000);

				// Handle response from service worker
				messageChannel.port1.onmessage = (event) => {
					if (event.data && event.data.type === 'test_response') {
						clearTimeout(timeout);
						resolve(event.data.success === true);
					}
				};

				// Send test message to service worker
				existingRegistration.active?.postMessage({
					type: 'test',
					testOnly: true
				}, [messageChannel.port2]);
			});

			try {
				// Check if existing service worker responds correctly
				const testResult = await testPromise;
				if (testResult) {
					// Existing service worker is working fine
					wsLog('Existing service worker is responsive');
					return {supported: true};
				}
			} catch (error) {
				console.warn('Existing service worker not responding correctly:', error);
				// Will continue to registration step
			}
		}

		// If we don't have a working service worker, register a new one
		try {
			wsLog('Registering new service worker using scope:', scope);

			// Register the service worker
			const registration = await navigator.serviceWorker.register(wsSwUrl, {scope: scope});

			// Wait for the service worker to be ready
			await navigator.serviceWorker.ready;

			// Create a messaging channel to communicate with the service worker
			const messageChannel = new MessageChannel();

			// Set up a promise to await the test response
			const testPromise = new Promise<boolean>((resolve, reject) => {
				// Time out after 2 seconds
				const timeout = setTimeout(() => {
					messageChannel.port1.onmessage = null;
					reject(new Error('Service worker test timed out'));
				}, 2000);

				// Handle response from service worker
				messageChannel.port1.onmessage = (event) => {
					if (event.data && event.data.type === 'test_response') {
						clearTimeout(timeout);
						resolve(event.data.success === true);
					}
				};

				// Send test message to service worker
				if (registration.active) {
					registration.active.postMessage({
						type: 'test',
						testOnly: true
					}, [messageChannel.port2]);
				} else {
					clearTimeout(timeout);
					reject(new Error('Service worker not active'));
				}
			});

			// Wait for test to complete
			const testResult = await testPromise;
			if (!testResult) {
				throw new Error('Service worker test failed');
			}
		} catch (registerError) {
			// Log the specific error for debugging
			console.warn('Service worker test registration error:', registerError);

			// Check for specific error messages
			if (registerError instanceof TypeError &&
				registerError.message.includes('URL scheme')) {
				return {
					supported: false,
					reason: 'Invalid URL scheme - must be https or http on localhost'
				};
			}

			// Check for insecure operation error
			if (registerError instanceof DOMException &&
				(registerError.message.includes('insecure') ||
					registerError.message.includes('security'))) {
				return {
					supported: false,
					reason: 'Certificate not trusted - install your local CA certificate'
				};
			}

			return {
				supported: false,
				reason: `Service worker registration failed: ${registerError instanceof DOMException ? registerError.message : registerError}`
			};
		}

		// Test creating a simple text file in the cache to check permissions
		try {
			const testCacheName = 'sw-test-cache-' + Math.random().toString(36).substr(2, 5);
			const cache = await caches.open(testCacheName);
			await cache.put('/sw-test', new Response('test'));
			await caches.delete(testCacheName);
		} catch (cacheError) {
			return {
				supported: false,
				reason: `Cannot access Cache API: ${cacheError && typeof cacheError === 'object' && 'message' in cacheError ? cacheError.message : cacheError}`
			};
		}

		// All checks passed
		return {supported: true};

	} catch (error) {
		return {
			supported: false,
			reason: `Unexpected error: ${error && typeof error === 'object' && 'message' in error ? error.message : error}`
		};
	}
}

/**
 * Directly checks if service workers are available without performing detailed tests
 * This is a synchronous function that just checks for the basic availability
 */
export function hasServiceWorkerAPI(disableServiceWorker?: boolean): boolean {
	// Check if service worker is explicitly disabled
	if (disableServiceWorker) {
		console.warn('Not using service worker: Explicitly disabled');
		return false;
	}
	
	// Check if we're running on a secure context with proper certificates
	if (typeof window !== 'undefined') {
		// If not running on HTTPS or localhost, force direct WebSocket
		if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
			console.warn('Not using service worker: Not HTTPS or localhost');
			return false;
		}

		// If the page has an insecure certificate, force direct WebSocket
		if (!window.isSecureContext) {
			console.warn('Not using service worker: Not in secure context');
			return false;
		}
	}

	// Check if service workers are supported
	const swSupported = typeof window !== 'undefined' &&
		'serviceWorker' in navigator &&
		!!navigator.serviceWorker;

	if (!swSupported) {
		console.warn('Not using service worker: API not supported');
	}

	return swSupported;
}