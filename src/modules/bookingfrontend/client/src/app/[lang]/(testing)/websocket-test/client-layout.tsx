'use client';

import React, {useState, useEffect} from 'react';
import {WebSocketProvider} from '@/service/websocket/websocket-context';
import ServiceWorkerProvider from '@/service/websocket/service-worker-provider';
import QueryProvider from "@/app/queryProvider";

/**
 * Client layout for WebSocket testing
 * This layout provides two modes:
 * 1. Service Worker with automatic fallback to direct mode if needed
 * 2. Forced direct mode via URL parameter
 */
export default function WebSocketTestClientLayout({
													  children,
												  }: {
	children: React.ReactNode;
}) {
	// We always wrap with WebSocketProvider to ensure availability
	// The ServiceWorkerProvider will decide whether to use service workers or fallback to direct mode
	return (
		<QueryProvider>
			<WebSocketProvider>

				{children}
			</WebSocketProvider>
		</QueryProvider>
	);
}