'use client';

/**
 * Service Worker Fallback Handler
 *
 * This module provides utilities to detect when service worker registration fails
 * due to security issues (like SSL certificate errors) and automatically switches
 * to direct WebSocket mode.
 */

import {getWebSocketUrl, wsLog as wslogbase} from './util';


const wsLog = (message: string, data: any = null) => wslogbase('WSFallback', message, data)

/**
 * Creates a direct WebSocket connection as fallback when service worker fails
 * @param options Options for the WebSocket connection
 * @returns A WebSocket instance or null if connection failed
 */
export async function createDirectWebSocketFallback(
  url?: string,
  onOpen?: () => void,
  onMessage?: (data: any) => void,
  onClose?: (event: CloseEvent) => void,
  onError?: (event: Event) => void
): Promise<WebSocket | null> {
  try {
    // Log the fallback for the FallbackIndicator to detect
    wsLog('Falling back to direct WebSocket connection due to service worker failure');

    // Get the WebSocket URL
    const wsUrl = url || getWebSocketUrl();
    if (!wsUrl) {
      wsLog('No WebSocket URL available for direct connection');
      return null;
    }

    // Create a new WebSocket connection
    wsLog('Creating direct WebSocket connection to:', wsUrl);
    const ws = new WebSocket(wsUrl);

    // Set up event handlers
    ws.onopen = () => {
      wsLog('Direct WebSocket connection established successfully');
      if (onOpen) onOpen();
    };

    ws.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data);
        
        // Automatically respond to server_ping messages with pong
        if (data.type === 'server_ping') {
          try {
            ws.send(JSON.stringify({
              type: 'pong',
              timestamp: new Date().toISOString(),
              reply_to: data.id || null
            }));
            wsLog('Sent pong response to server_ping');
          } catch (error) {
            wsLog('Error sending pong response to server_ping:', error);
          }
        }
        
        if (onMessage) onMessage(data);
      } catch (error) {
        wsLog('Error parsing WebSocket message:', error);
      }
    };

    ws.onclose = (event) => {
      wsLog(`Direct WebSocket connection closed: ${event.code} - ${event.reason}`);
      if (onClose) onClose(event);
    };

    ws.onerror = (event) => {
      wsLog('Direct WebSocket connection error');
      if (onError) onError(event);
    };

    return ws;
  } catch (error) {
    wsLog('Failed to create direct WebSocket connection:', error);
    return null;
  }
}

/**
 * Determines if a service worker error is security-related
 * @param error The error from service worker registration
 * @returns True if it's a security error
 */
export function isSecurityError(error: any): boolean {
  if (!error) return false;

  const errorMessage = error.toString().toLowerCase();

  return (
    errorMessage.includes('security') ||
    errorMessage.includes('secure context') ||
    errorMessage.includes('ssl') ||
    errorMessage.includes('certificate') ||
    errorMessage.includes('https')
  );
}

/**
 * Determine if we should use a direct WebSocket connection instead of a service worker
 * @param disableServiceWorker Optional flag to explicitly disable service worker
 * @returns Promise resolving to true if direct connection should be used
 */
export async function shouldUseDirectConnection(disableServiceWorker?: boolean): Promise<boolean> {
  // Check if running in a browser
  if (typeof window === 'undefined') return false;
  
  // Check if service worker is explicitly disabled
  if (disableServiceWorker) return true;

  // Check if service workers are supported at all
  if (!('serviceWorker' in navigator)) return true;

  // Check if we're in a secure context
  if (window.isSecureContext === false) return true;

  // Check if URL parameter forces direct mode
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('direct') === 'true' || urlParams.get('direct') === '1') {
    return true;
  }

  // Check if browser has encountered previous service worker errors
  // Store this in sessionStorage to persist across initial page load and refreshes
  if (sessionStorage.getItem('sw_error')) {
    console.log('Previous service worker errors detected, using direct connection');
    return true;
  }

  // Default to service worker if all checks pass
  return false;
}

/**
 * Verify if the service worker script has the correct MIME type
 * @param swUrl URL of the service worker file
 * @returns Promise resolving to true if valid, false otherwise
 */
export async function verifyServiceWorkerMimeType(swUrl: string): Promise<boolean> {
  try {
    const response = await fetch(swUrl, {
      cache: 'no-cache',
      headers: { 'X-Requested-With': 'SW-MIME-Check' }
    });

    if (!response.ok) {
      console.error(`Service worker file not found: ${response.status} ${response.statusText}`);
      return false;
    }

    const contentType = response.headers.get('content-type');
    const validTypes = ['application/javascript', 'text/javascript', 'application/x-javascript'];
    const isValidType = contentType && validTypes.some(type => contentType.includes(type));

    if (!isValidType) {
      console.error(`Service worker has incorrect MIME type: ${contentType || 'unknown'}`);
      // Mark that we've had a MIME type error for this session
      if (typeof sessionStorage !== 'undefined') {
        sessionStorage.setItem('sw_error', 'mime_type');
      }
      return false;
    }

    return true;
  } catch (error) {
    console.error('Error checking service worker MIME type:', error);
    return false;
  }
}