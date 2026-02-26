/**
 * Type definitions for WebSocket communication
 */
import { IServerMessage } from '../types/api/server-messages.types';
import { IApplication } from '../types/api/application.types';

// Base interface for all WebSocket messages
export interface IWebSocketMessageBase {
  type: string;
  action?: 'new' | 'changed' | 'deleted' | string;
  timestamp: string;
}

// Interface for a notification message
export interface IWSNotificationMessage extends IWebSocketMessageBase {
  type: 'notification';
  message: string;
  notificationType?: string;
}

// Interface for a new/changed server message
export interface IWSServerNewMessage extends IWebSocketMessageBase {
  type: 'server_message';
  action: 'new' | 'changed';
  messages: IServerMessage[];
}

// Interface for a deleted server message
export interface IWSServerDeletedMessage extends IWebSocketMessageBase {
  type: 'server_message';
  action: 'deleted';
  message_ids: string[]; // IDs of messages to delete
}

// Interface for a ping/pong message
export interface IWSPingMessage extends IWebSocketMessageBase {
  type: 'ping' | 'pong' | 'server_ping' | 'server_pong';
  entityType?: string;
  entityId?: number | string;
  id?: any;
}

// Interface for a room ping/ping response message
export interface IWSRoomPingMessage extends IWebSocketMessageBase {
  type: 'room_ping';
  roomId: string;
  pingId?: string;
}

export interface IWSRoomPingResponseMessage extends IWebSocketMessageBase {
  type: 'room_ping_response';
  roomId: string;
  pingId?: string;
}

// Interface for a reconnect required message
export interface IWSReconnectMessage extends IWebSocketMessageBase {
  type: 'reconnect_required';
  message?: string;
}

// Interface for entity subscription message
export interface IWSEntitySubscribeMessage extends IWebSocketMessageBase {
  type: 'subscribe';
  entityType: string;
  entityId: number | string;
}

// Interface for entity unsubscription message
export interface IWSEntityUnsubscribeMessage extends IWebSocketMessageBase {
  type: 'unsubscribe';
  entityType: string;
  entityId: number | string;
}

// Interface for subscription confirmation message
export interface IWSSubscriptionConfirmMessage extends IWebSocketMessageBase {
  type: 'subscription_confirmation';
  entityType: string;
  entityId: number | string;
  status: 'subscribed' | 'unsubscribed';
}

// Interface for entity event message
export interface IWSEntityEventMessage extends IWebSocketMessageBase {
  type: 'entity_event';
  entityType: string;
  entityId: number | string;
  eventType: 'create' | 'update' | 'delete' | 'reservation' | string;
  data?: any;
}

// Interface for room message
export interface IWSRoomMessage extends IWebSocketMessageBase {
  type: 'room_message';
  roomId: string;
  entityType: string;
  entityId: number | string;
  action: string;
  message: string;
  data?: any;
}

// Interface for session update message
export interface IWSSessionUpdateMessage extends IWebSocketMessageBase {
  type: 'update_session';
  sessionId: string;
}

// Interface for session update confirmation message
export interface IWSSessionUpdateConfirmMessage extends IWebSocketMessageBase {
  type: 'session_update_confirmation';
  success: boolean;
  message: string;
  action: 'updated' | 'unchanged';
  sessionId: string;
  environment?: {
    NEXTJS_HOST?: string | null;
    SLIM_HOST?: string | null;
    REDIS_HOST?: string | null;
    websocket_host?: string | null;
  };
}

// Interface for session ID required message
export interface IWSSessionIdRequiredMessage extends IWebSocketMessageBase {
  type: 'session_id_required';
  message: string;
}

// Interface for connection success message
export interface IWSConnectionSuccessMessage extends IWebSocketMessageBase {
  type: 'connection_success';
  message: string;
  roomId: string;
  environment?: {
    NEXTJS_HOST?: string | null;
    SLIM_HOST?: string | null;
    REDIS_HOST?: string | null;
    websocket_host?: string | null;
  };
}

// Interface for partial applications response
export interface IWSPartialApplicationsResponse extends IWebSocketMessageBase {
  type: 'partial_applications_response';
  data: {
    error: boolean;
    status: string;
    applications: IApplication[]; // Array of partial applications
    count: number;
    sessionId: string;
  };
}

// Interface for booking user refresh message
export interface IWSRefreshBookingUserMessage extends IWebSocketMessageBase {
  type: 'refresh_bookinguser';
  message: string;
  action: 'refresh';
}

// Interface for cache invalidation message
export interface IWSCacheInvalidationMessage extends IWebSocketMessageBase {
  type: 'cache_invalidation';
  queryKeys: string[][];  // Array of React Query key arrays to invalidate
  timestamp: string;
}

// Union type for all possible WebSocket messages
export type WebSocketMessage =
  | IWSNotificationMessage
  | IWSServerNewMessage
  | IWSServerDeletedMessage
  | IWSPingMessage
  | IWSRoomPingMessage
  | IWSRoomPingResponseMessage
  | IWSReconnectMessage
  | IWSEntitySubscribeMessage
  | IWSEntityUnsubscribeMessage
  | IWSSubscriptionConfirmMessage
  | IWSEntityEventMessage
  | IWSRoomMessage
  | IWSSessionUpdateMessage
  | IWSSessionUpdateConfirmMessage
  | IWSSessionIdRequiredMessage
  | IWSConnectionSuccessMessage
  | IWSPartialApplicationsResponse
  | IWSRefreshBookingUserMessage
  | IWSCacheInvalidationMessage;
  // | (IWebSocketMessageBase & { [key: string]: any }); // Catch-all for other message types

export type WebSocketStatus = 'CONNECTING' | 'OPEN' | 'CLOSING' | 'CLOSED' | 'RECONNECTING' | 'ERROR' | 'FALLBACK_REQUIRED';

// Interface for WebSocket service worker communication
export interface ServiceWorkerWebSocketOptions {
  url: string;
  autoReconnect?: boolean;
  reconnectInterval?: number;
  pingInterval?: number;
  disableServiceWorker?: boolean;
}

// Events that can be dispatched
export type WebSocketServiceEvent =
  | { type: 'status'; status: WebSocketStatus }
  | { type: 'message'; data: WebSocketMessage }
  | { type: 'error'; error: string };