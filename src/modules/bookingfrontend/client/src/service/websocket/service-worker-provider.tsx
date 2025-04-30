'use client';

import {FC, ReactNode, useEffect, useState} from 'react';
import {registerWebSocketServiceWorker, isWebSocketServiceWorkerActive} from './service-worker-registration';
import {checkServiceWorkerSupport} from './service-worker-check';
import {wsLog as wslogbase} from "@/service/websocket/util";

interface ServiceWorkerProviderProps {
	children: ReactNode;
	disableServiceWorker?: boolean;
}
const wsLog = (message: string, data: any = null) => wslogbase('WSProvider', message, data)

/**
 * Provider component that handles WebSocket Service Worker registration
 * This component must be a client component (use client directive)
 */
export const ServiceWorkerProvider: FC<ServiceWorkerProviderProps> = ({children, disableServiceWorker}) => {
	const [isRegistered, setIsRegistered] = useState(false);
	const [fallbackToDirectMode, setFallbackToDirectMode] = useState(false);

	useEffect(() => {
		// Skip if we're not in a browser environment
		if (typeof window === 'undefined') return;

		// If service worker is explicitly disabled via prop
		if (disableServiceWorker) {
			wsLog('Direct mode requested via disableServiceWorker prop');
			setFallbackToDirectMode(true);
			return;
		}

		// If URL has direct=true, don't even try service workers
		const urlParams = new URLSearchParams(window.location.search);
		if (urlParams.get('direct') === 'true' || urlParams.get('direct') === '1') {
			wsLog('Direct mode requested via URL parameter');
			setFallbackToDirectMode(true);
			return;
		}

		// Comprehensive check for service worker support
		checkServiceWorkerSupport(disableServiceWorker).then(result => {
			if (!result.supported) {
				console.warn(`Service Workers are not supported: ${result.reason}`);
				setIsRegistered(false); // Mark as not registered, but continue

				if (result.reason?.includes('Certificate not trusted') ||
					result.reason?.includes('not in secure context') ||
					result.reason?.includes('insecure')) {
					// This is a certificate or security issue - fall back to direct mode
					console.warn('Falling back to direct WebSocket mode due to security/certificate issues');
					setFallbackToDirectMode(true);
				}
				return;
			}

			const initializeServiceWorker = async () => {
				try {
					// Check if service worker is already active
					const isActive = await isWebSocketServiceWorkerActive();

					if (isActive) {
						wsLog('WebSocket service worker is already active');
						setIsRegistered(true);
						return;
					}

					// Register the WebSocket service worker
					const success = await registerWebSocketServiceWorker();
					setIsRegistered(success);

					if (success) {
						wsLog('WebSocket service worker registered successfully');
					} else {
						console.error('Failed to register WebSocket service worker');
						// If registration failed, we'll fall back to direct mode
						setFallbackToDirectMode(true);
					}
				} catch (error) {
					console.error('Error initializing WebSocket service worker:', error);

					// Check if the error is a security error
					if (error instanceof DOMException &&
						(error.name === 'SecurityError' ||
							(error.message && error.message.includes('insecure')))) {
						console.warn('Security error when registering service worker - falling back to direct WebSocket');
						setFallbackToDirectMode(true);
					}
				}
			};

			// Only run the initialization if we have service worker support
			initializeServiceWorker();
		});
	}, []);

	// If we need to fall back to direct mode, render just the children without the provider
	if (fallbackToDirectMode) {
		// Inform the console so it's clear what's happening
		console.info('Using direct WebSocket connection (service worker disabled)');
		return <>{children}</>;
	}

	return <>{children}</>;
};

export default ServiceWorkerProvider;