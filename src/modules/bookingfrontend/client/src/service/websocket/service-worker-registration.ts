'use client';

// This file handles service worker registration specifically for the WebSocket service worker

/**
 * Register the WebSocket service worker
 * @returns A promise that resolves to true if registration was successful, false otherwise
 */
export async function registerWebSocketServiceWorker(): Promise<boolean> {
	if (typeof window === 'undefined') {
		console.warn('Cannot register service worker in server-side environment');
		return false;
	}

	// More thorough check for service worker support
	if (!('serviceWorker' in navigator) ||
		!navigator.serviceWorker ||
		typeof navigator.serviceWorker.register !== 'function') {
		console.warn('Service Worker API not fully supported in this browser/context');
		return false;
	}

	// Check for secure context (needed for service workers)
	if (window.isSecureContext === false) {
		console.warn('Service Workers require a secure context (HTTPS or localhost)');
		return false;
	}

	try {
		const basePath = process.env.NEXT_PUBLIC_BASE_PATH || '';

		// For service workers, we should use a path relative to the origin
		// This works better with proxies than absolute URLs
		const swURL = `${basePath}/websocket-sw.js`;

		// First verify the service worker file exists
		try {
			const swFileCheck = await fetch(swURL);
			if (!swFileCheck.ok) {
				console.error(`Service worker file not found: ${swFileCheck.status} ${swFileCheck.statusText}`);
				return false;
			}
		} catch (fetchError) {
			console.error('Failed to fetch service worker file:', fetchError);
			return false;
		}

		// Check if the service worker is already registered
		const registrations = await navigator.serviceWorker.getRegistrations();
		const existingRegistration = registrations.find(
			registration => registration.active?.scriptURL.includes('websocket-sw.js')
		);

		if (existingRegistration) {
			console.log('WebSocket Service Worker already registered');

			// Check if it needs updating
			try {
				await existingRegistration.update();
				console.log('WebSocket Service Worker updated');
			} catch (updateError) {
				console.warn('Failed to update existing service worker:', updateError);
			}

			return true;
		}

		// Register the service worker with absolute URL
		console.log('Registering WebSocket Service Worker...', swURL);
		// Use the same scope that service-worker-check.ts expects (/bookingfrontend/client/)
		const scope = '/bookingfrontend/client/';
		console.log('Using service worker scope:', scope);
		const registration = await navigator.serviceWorker.register(swURL, {
			scope: scope
		});

		console.log('WebSocket Service Worker registered with scope:', registration.scope);

		// Wait for the service worker to become installed
		if (registration.installing) {
			console.log('Service worker installing...');
			return new Promise<boolean>((resolve) => {
				registration.installing?.addEventListener('statechange', (event) => {
					const sw = event.target as ServiceWorker;
					console.log('Service worker state changed:', sw.state);

					if (sw.state === 'activated') {
						console.log('Service worker activated successfully');
						resolve(true);
					} else if (sw.state === 'redundant') {
						console.error('Service worker became redundant during installation');
						resolve(false);
					}
				});

				// Add a timeout to avoid hanging
				setTimeout(() => {
					console.warn('Service worker activation timed out, but continuing');
					resolve(true);
				}, 5000);
			});
		}

		return true;
	} catch (error) {
		console.error('Service Worker registration failed:', error);
		if (error instanceof TypeError && error.message.includes('Script error')) {
			console.error('Service worker script has errors - check the console for details');
		}
		return false;
	}
}

/**
 * Unregister the WebSocket service worker
 * @returns A promise that resolves to true if unregistration was successful, false otherwise
 */
export async function unregisterWebSocketServiceWorker(): Promise<boolean> {
	if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
		return false;
	}

	try {
		const registrations = await navigator.serviceWorker.getRegistrations();

		// Find the WebSocket service worker registration
		const wsRegistration = registrations.find(
			registration => registration.active?.scriptURL.includes('websocket-sw.js')
		);

		if (wsRegistration) {
			const result = await wsRegistration.unregister();
			console.log('WebSocket Service Worker unregistered:', result);
			return result;
		}

		// No service worker found to unregister
		return false;
	} catch (error) {
		console.error('Failed to unregister Service Worker:', error);
		return false;
	}
}

/**
 * Check if the WebSocket service worker is registered and active
 * @returns A promise that resolves to true if the service worker is active, false otherwise
 */
export async function isWebSocketServiceWorkerActive(): Promise<boolean> {
	if (typeof window === 'undefined') return false;

	// More thorough check for service worker support
	if (!('serviceWorker' in navigator) ||
		!navigator.serviceWorker ||
		typeof navigator.serviceWorker.register !== 'function' ||
		typeof navigator.serviceWorker.getRegistrations !== 'function') {
		console.warn('Service Worker API not fully supported in this browser/context');
		return false;
	}

	try {
		// Check for service worker in secure context
		if (window.isSecureContext === false) {
			console.warn('Service Workers require a secure context (HTTPS or localhost)');
			return false;
		}

		const registrations = await navigator.serviceWorker.getRegistrations();

		// Find the WebSocket service worker registration
		const wsRegistration = registrations.find(
			registration => registration.active?.scriptURL.includes('websocket-sw.js')
		);

		return !!wsRegistration?.active;
	} catch (error) {
		console.error('Failed to check Service Worker status:', error);
		return false;
	}
}