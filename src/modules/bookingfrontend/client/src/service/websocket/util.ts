'use client';

// Toggle for client-side WebSocket logging
export const WEBSOCKET_CLIENT_DEBUG = false;

/**
 * Helper function for consistent WebSocket logging
 * @param area the component responsible
 * @param message The message to log
 * @param data Optional data to include with the log
 */
export function wsLog(area: string, message: string, data?: any, ...optionalParams: any[]): void {
  // Only log if debug is enabled
  if (!WEBSOCKET_CLIENT_DEBUG) return;

  const timestamp = new Date().toISOString().split('T')[1].substring(0, 12); // HH:MM:SS.mmm
  const prefix = `[${area} ${timestamp}]`;

  if (data) {
    console.log(prefix, message, data, optionalParams);
  } else {
    console.log(prefix, message, optionalParams);
  }
}

/**
 * Get the base path for the application
 * This considers both the environment variable and the URL path
 *
 * @returns The base path string to use for asset and API URLs
 */
export function getBasePath(): string {
  // Check environment variable first
  const envBasePath = process.env.NEXT_PUBLIC_BASE_PATH || '';
  if (envBasePath) {
    return envBasePath;
  }

  // If not available, try to derive from URL path
  if (typeof window !== 'undefined') {
    const pathSegments = window.location.pathname.split('/');
    if (pathSegments.length > 1) {
      // Assuming the structure is /bookingfrontend/client/...
      // We want to keep the first segment (e.g., /bookingfrontend)
      return pathSegments[1] ? `/${pathSegments[1]}` : '';
    }
  }

  // Default to empty string if nothing found
  return '';
}

/**
 * Get the WebSocket URL based on current location
 *
 * @param customPath Optional custom path to use instead of the default 'wss'
 * @returns The WebSocket URL with the proper protocol and base path
 */
export function getWebSocketUrl(customPath?: string): string {
  if (typeof window === 'undefined') return '';

  const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
  const host = window.location.host;
  const basePath = getBasePath();
  const path = customPath || 'wss';

  return `${protocol}//${host}${basePath}/${path}`;
}

/**
 * Get a path for a resource, using the base path
 *
 * @param path The resource path
 * @returns Path with base path
 */
export function getResourcePath(path: string): string {
  const basePath = getBasePath();
  const cleanPath = path.startsWith('/') ? path : `/${path}`;

  return `${basePath}${cleanPath}`;
}

/**
 * Get a service worker path (relative to origin)
 * This is used for service worker registration which should be relative to origin
 *
 * @param path The service worker path
 * @returns Path relative to origin
 */
export function getServiceWorkerPath(path: string): string {
  // Service workers should be registered with paths relative to origin
  // Do not include the base path
  const cleanPath = path.startsWith('/') ? path : `/${path}`;
  return cleanPath;
}