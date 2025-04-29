'use client';

import {
  WebSocketMessage,
  IWSEntityEventMessage,
  IWSSubscriptionConfirmMessage,
  IWSEntitySubscribeMessage,
  IWSEntityUnsubscribeMessage,
  IWSRoomMessage
} from './websocket.types';
import { WebSocketService } from './websocket-service';

export interface EntitySubscription {
  entityType: string;
  entityId: number | string;
}

export interface MessageSubscription {
  messageType: string;
}

export type SubscriptionCallback = (message: WebSocketMessage) => void;

/**
 * WebSocket Subscription Manager
 * Manages entity and message subscriptions and routes messages to appropriate callbacks
 */
export class SubscriptionManager {
  private static instance: SubscriptionManager | null = null;
  private entitySubscriptions = new Map<string, Set<SubscriptionCallback>>();
  private messageSubscriptions = new Map<string, Set<SubscriptionCallback>>();
  private activeSubscriptions = new Set<string>();

  private constructor() {}

  // Singleton pattern
  static getInstance(): SubscriptionManager {
    if (!SubscriptionManager.instance) {
      SubscriptionManager.instance = new SubscriptionManager();
    }
    return SubscriptionManager.instance;
  }

  /**
   * Subscribe to events for a specific entity
   * @param entityType Type of entity (e.g., 'resource', 'building')
   * @param entityId ID of the entity
   * @param callback Callback function to execute when events for this entity occur
   * @returns Unsubscribe function
   */
  subscribeToEntity(
    entityType: string,
    entityId: number | string,
    callback: SubscriptionCallback
  ): () => void {
    const subscriptionKey = this.getEntityKey(entityType, entityId);

    if (!this.entitySubscriptions.has(subscriptionKey)) {
      this.entitySubscriptions.set(subscriptionKey, new Set());
    }

    this.entitySubscriptions.get(subscriptionKey)!.add(callback);

    // Track active subscription
    this.activeSubscriptions.add(subscriptionKey);

    return () => {
      this.unsubscribeFromEntity(entityType, entityId, callback);
    };
  }

  /**
   * Unsubscribe from events for a specific entity - internal use only
   * (Used by the subscribeToEntity's returned unsubscribe function)
   * @param entityType Type of entity
   * @param entityId ID of the entity
   * @param callback The callback to remove
   */
  unsubscribeFromEntity(
    entityType: string,
    entityId: number | string,
    callback: SubscriptionCallback
  ): void {
    const subscriptionKey = this.getEntityKey(entityType, entityId);
    const callbacks = this.entitySubscriptions.get(subscriptionKey);

    if (callbacks) {
      callbacks.delete(callback);

      if (callbacks.size === 0) {
        this.entitySubscriptions.delete(subscriptionKey);
        this.activeSubscriptions.delete(subscriptionKey);

        // No need to send unsubscribe message to server
        // Server will detect inactive subscriptions via ping-pong mechanism
        console.log(`Last subscriber removed for ${entityType} ${entityId}, server will detect via ping-pong`);
      }
    }
  }

  /**
   * Subscribe to messages of a specific type
   * @param messageType Type of message to subscribe to
   * @param callback Callback function to execute when messages of this type are received
   * @returns Unsubscribe function
   */
  subscribeToMessageType(
    messageType: string,
    callback: SubscriptionCallback
  ): () => void {
    if (!this.messageSubscriptions.has(messageType)) {
      this.messageSubscriptions.set(messageType, new Set());
    }

    this.messageSubscriptions.get(messageType)!.add(callback);

    return () => {
      this.unsubscribeFromMessageType(messageType, callback);
    };
  }

  /**
   * Unsubscribe from messages of a specific type
   * @param messageType Type of message
   * @param callback The callback to remove
   */
  unsubscribeFromMessageType(
    messageType: string,
    callback: SubscriptionCallback
  ): void {
    const callbacks = this.messageSubscriptions.get(messageType);

    if (callbacks) {
      callbacks.delete(callback);

      if (callbacks.size === 0) {
        this.messageSubscriptions.delete(messageType);
      }
    }
  }

  /**
   * Process an incoming message and route it to the appropriate subscribers
   * @param message The incoming WebSocket message
   */
  handleMessage(message: WebSocketMessage): void {
    // Handle entity event messages
    if (message.type === 'entity_event') {
      const entityMessage = message as IWSEntityEventMessage;
      const subscriptionKey = this.getEntityKey(
        entityMessage.entityType,
        entityMessage.entityId
      );

      const callbacks = this.entitySubscriptions.get(subscriptionKey);
      if (callbacks) {
        callbacks.forEach(callback => {
          try {
            callback(message);
          } catch (error) {
            console.error(`Error in entity subscription callback:`, error);
          }
        });
      }
    }

    // Handle room messages (similar to entity event but with different structure)
    else if (message.type === 'room_message') {
      const roomMessage = message as IWSRoomMessage;
      const subscriptionKey = this.getEntityKey(
        roomMessage.entityType,
        roomMessage.entityId
      );

      // Forward to entity subscribers
      const callbacks = this.entitySubscriptions.get(subscriptionKey);
      if (callbacks) {
        callbacks.forEach(callback => {
          try {
            callback(message);
          } catch (error) {
            console.error(`Error in room message callback:`, error);
          }
        });
      }
    }

    // Handle subscription confirmation messages
    else if (message.type === 'subscription_confirmation') {
      const confirmMessage = message as IWSSubscriptionConfirmMessage;
      const subscriptionKey = this.getEntityKey(
        confirmMessage.entityType,
        confirmMessage.entityId
      );

      const callbacks = this.entitySubscriptions.get(subscriptionKey);
      if (callbacks) {
        callbacks.forEach(callback => {
          try {
            callback(message);
          } catch (error) {
            console.error(`Error in subscription confirmation callback:`, error);
          }
        });
      }
    }

    // Handle entity ping messages and respond with pong
    else if (message.type === 'ping' && 'entityType' in message && 'entityId' in message) {
      const entityType = message.entityType as string;
      const entityId = message.entityId as number | string;
      const subscriptionKey = this.getEntityKey(entityType, entityId);

      // If we have subscribers for this entity, respond with a pong
      if (this.entitySubscriptions.has(subscriptionKey)) {
        // Get the WebSocket service to send the pong response
        const wsService = WebSocketService.getInstance();
        wsService.sendMessage('pong', `Pong response for ${entityType} ${entityId}`, {
          entityType,
          entityId
        });

        console.log(`Ping received for ${entityType} ${entityId}, sent pong response`);
      }
    }

    // Handle message type subscriptions for all message types
    const typeCallbacks = this.messageSubscriptions.get(message.type);
    if (typeCallbacks) {
      typeCallbacks.forEach(callback => {
        try {
          callback(message);
        } catch (error) {
          console.error(`Error in message type subscription callback:`, error);
        }
      });
    // } else if (message.type !== 'ping' && message.type !== 'pong') {
    } else {
      // Only log errors for non-ping/pong messages to reduce noise
      // Ping/pong messages are expected to sometimes have no subscribers
      console.log(`No subscription callbacks for message type: ${message.type}`, message);
    }
  }

  /**
   * Get all current entity subscriptions
   * @returns Array of active entity subscriptions
   */
  getActiveEntitySubscriptions(): EntitySubscription[] {
    const subscriptions: EntitySubscription[] = [];

    this.activeSubscriptions.forEach(key => {
      const [entityType, entityId] = key.split(':');
      subscriptions.push({
        entityType,
        entityId: /^\d+$/.test(entityId) ? parseInt(entityId) : entityId
      });
    });

    return subscriptions;
  }

  /**
   * Create an entity key for subscription storage
   * @param entityType Type of entity
   * @param entityId ID of entity
   * @returns A unique key for this entity
   */
  private getEntityKey(entityType: string, entityId: number | string): string {
    return `${entityType}:${entityId}`;
  }

  /**
   * Clear all subscriptions
   */
  clearAllSubscriptions(): void {
    this.entitySubscriptions.clear();
    this.messageSubscriptions.clear();
    this.activeSubscriptions.clear();
  }
}