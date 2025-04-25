'use client';

/**
 * Comprehensive check for service worker support in the current environment
 * This function checks not just the existence of the API but whether it's
 * actually usable in the current context (HTTPS, permissions, etc.)
 */
export async function checkServiceWorkerSupport(): Promise<{
	supported: boolean;
	reason?: string;
}> {
	if (typeof window === 'undefined') {
		return {supported: false, reason: 'Not in browser environment'};
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
		// Detect insecure certificates (sites with security warnings)
		// by attempting a very simple registration
		try {
			// Create a test URL using the current origin
			const basePath = process.env.NEXT_PUBLIC_BASE_PATH || '';

			const testSwUrl = new URL(basePath + '/test-sw.js', window.location.origin).href;

			// Try to register using the proper URL format
			try {
				// Try to register with a proper URL
				await navigator.serviceWorker.register(testSwUrl, {scope: './'});
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

			// If we get here, registration worked - clean up the test service worker
			const registrations = await navigator.serviceWorker.getRegistrations();
			for (const registration of registrations) {
				if (registration.scope.includes(window.location.origin)) {
					await registration.unregister();
				}
			}
		} catch (error) {
			console.warn('Error during service worker test:', error);
			return {
				supported: false,
				reason: `Service worker test failed: ${error && typeof error === 'object' && 'message' in error ? error.message : error}`
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
export function hasServiceWorkerAPI(): boolean {
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