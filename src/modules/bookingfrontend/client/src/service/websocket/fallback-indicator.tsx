'use client';

import React, {useEffect, useState} from 'react';

/**
 * A component that indicates when automatic fallback to direct WebSocket has occurred
 */
export const FallbackIndicator: React.FC = () => {
	const [fallbackActive, setFallbackActive] = useState(false);
	const [fallbackReason, setFallbackReason] = useState<string | null>(null);

	useEffect(() => {
		// Use a MutationObserver to detect console warnings about fallback
		if (typeof window !== 'undefined') {
			// Check if URL has direct=true param first
			const urlParams = new URLSearchParams(window.location.search);
			const directMode = urlParams.get('direct') === 'true' || urlParams.get('direct') === '1';

			if (directMode) {
				// Direct mode was explicitly requested, not a fallback
				return;
			}

			// Create a special listener for our fallback messages
			const originalConsoleWarn = console.warn;
			const originalConsoleInfo = console.info;

			console.warn = function (message: any, ...args: any[]) {
				// Check if this is a fallback message
				if (typeof message === 'string') {
					if (message.includes('Falling back to direct WebSocket')) {
						setFallbackActive(true);
						setFallbackReason(message);
					}
				}
				// Call the original console.warn
				originalConsoleWarn.apply(console, [message, ...args]);
			};

			console.info = function (message: any, ...args: any[]) {
				// Check if this is a fallback message
				if (typeof message === 'string') {
					if (message.includes('Using direct WebSocket connection')) {
						setFallbackActive(true);
						if (!fallbackReason) {
							setFallbackReason('Automatic fallback to direct WebSocket connection');
						}
					}
				}
				// Call the original console.info
				originalConsoleInfo.apply(console, [message, ...args]);
			};

			// Clean up on unmount
			return () => {
				console.warn = originalConsoleWarn;
				console.info = originalConsoleInfo;
			};
		}
	}, [fallbackReason]);

	if (!fallbackActive) {
		return null;
	}

	return (
		<div style={{
			padding: '8px 12px',
			marginBottom: '16px',
			backgroundColor: '#d1ecf1',
			border: '1px solid #bee5eb',
			borderRadius: '4px',
			fontSize: '14px',
			color: '#0c5460'
		}}>
			<strong>Automatic Fallback:</strong> Using direct WebSocket connection without service workers
			{fallbackReason && fallbackReason.includes('security') && (
				<div style={{marginTop: '4px', fontSize: '12px'}}>
					Reason: Security certificate issues. The site must be accessed via HTTPS with a trusted certificate
					for service workers to function.
				</div>
			)}
		</div>
	);
};