/**
 * Type definitions for WebSocket communication
 */
import { IServerMessage } from '../types/api/server-messages.types';

// Base interface for all WebSocket messages
export interface IWebSocketMessageBase {
  type: string;
  action?: 'new' | 'changed' | 'deleted';
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
}

// Interface for a reconnect required message
export interface IWSReconnectMessage extends IWebSocketMessageBase {
  type: 'reconnect_required';
  message?: string;
}

// Union type for all possible WebSocket messages
export type WebSocketMessage = 
  | IWSNotificationMessage 
  | IWSServerNewMessage
  | IWSServerDeletedMessage
  | IWSPingMessage 
  | IWSReconnectMessage
  | (IWebSocketMessageBase & { [key: string]: any }); // Catch-all for other message types

export type WebSocketStatus = 'CONNECTING' | 'OPEN' | 'CLOSING' | 'CLOSED' | 'RECONNECTING' | 'ERROR';

// Interface for WebSocket service worker communication
export interface ServiceWorkerWebSocketOptions {
  url: string;
  autoReconnect?: boolean;
  reconnectInterval?: number;
  pingInterval?: number;
}

// Events that can be dispatched
export type WebSocketServiceEvent =
  | { type: 'status'; status: WebSocketStatus }
  | { type: 'message'; data: WebSocketMessage }
  | { type: 'error'; error: string };