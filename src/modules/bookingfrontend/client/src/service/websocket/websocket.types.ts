/**
 * Type definitions for WebSocket communication
 */
import { IServerMessage } from '../types/api/server-messages.types';

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
  type: 'ping' | 'pong' | 'server_ping';
  entityType?: string;
  entityId?: number | string;
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

// Union type for all possible WebSocket messages
export type WebSocketMessage =
  | IWSNotificationMessage
  | IWSServerNewMessage
  | IWSServerDeletedMessage
  | IWSPingMessage
  | IWSReconnectMessage
  | IWSEntitySubscribeMessage
  | IWSEntityUnsubscribeMessage
  | IWSSubscriptionConfirmMessage
  | IWSEntityEventMessage
  | IWSRoomMessage;
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