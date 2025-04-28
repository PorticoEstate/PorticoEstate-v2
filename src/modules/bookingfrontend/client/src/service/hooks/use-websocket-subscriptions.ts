'use client';

import { useEffect, useRef, useCallback } from 'react';
import { WebSocketService } from '../websocket/websocket-service';
import { WebSocketMessage } from '../websocket/websocket.types';
import { SubscriptionCallback } from '../websocket/subscription-manager';

/**
 * A React hook for subscribing to entity rooms via WebSocket
 * 
 * @param entityType Type of entity (e.g., 'resource', 'building')
 * @param entityId ID of the entity
 * @param callback Callback function to execute when events for this entity occur
 * @returns Object containing subscription status and unsubscribe function
 */
export const useEntitySubscription = (
  entityType: string,
  entityId: number | string,
  callback: SubscriptionCallback
) => {
  const unsubscribeFnRef = useRef<(() => void) | undefined>(undefined);
  const wsService = WebSocketService.getInstance();

  useEffect(() => {
    // Subscribe when component mounts
    unsubscribeFnRef.current = wsService.subscribeToRoom(entityType, entityId, callback);

    // Unsubscribe when component unmounts
    return () => {
      if (unsubscribeFnRef.current) {
        unsubscribeFnRef.current();
      }
      wsService.unsubscribeFromRoom(entityType, entityId);
    };
  }, [entityType, entityId, callback]);

  // Manual unsubscribe function
  const unsubscribe = useCallback(() => {
    if (unsubscribeFnRef.current) {
      unsubscribeFnRef.current();
      unsubscribeFnRef.current = undefined;
    }
    wsService.unsubscribeFromRoom(entityType, entityId);
  }, [entityType, entityId]);

  return {
    unsubscribe,
    isSubscribed: !!unsubscribeFnRef.current
  };
};

/**
 * A React hook for subscribing to WebSocket message types
 * 
 * @param messageType Type of message to subscribe to
 * @param callback Callback function to execute when messages of this type are received
 * @returns Unsubscribe function
 */
export const useMessageTypeSubscription = (
  messageType: string,
  callback: SubscriptionCallback
) => {
  const unsubscribeFnRef = useRef<(() => void) | undefined>(undefined);
  const wsService = WebSocketService.getInstance();

  useEffect(() => {
    // Subscribe when component mounts
    unsubscribeFnRef.current = wsService.subscribeToMessageType(messageType, callback);

    // Unsubscribe when component unmounts
    return () => {
      if (unsubscribeFnRef.current) {
        unsubscribeFnRef.current();
        unsubscribeFnRef.current = undefined;
      }
    };
  }, [messageType, callback]);

  // Manual unsubscribe function
  const unsubscribe = useCallback(() => {
    if (unsubscribeFnRef.current) {
      unsubscribeFnRef.current();
      unsubscribeFnRef.current = undefined;
    }
  }, []);

  return {
    unsubscribe,
    isSubscribed: !!unsubscribeFnRef.current
  };
};

/**
 * A React hook to subscribe to multiple entity rooms at once
 * 
 * @param subscriptions Array of entity subscriptions with callbacks
 * @returns Object containing subscription status
 */
export const useMultiEntitySubscription = (
  subscriptions: Array<{
    entityType: string;
    entityId: number | string;
    callback: SubscriptionCallback;
  }>
) => {
  const unsubscribeFnsRef = useRef<Map<string, () => void>>(new Map());
  const wsService = WebSocketService.getInstance();

  useEffect(() => {
    // Clear previous subscriptions
    unsubscribeFnsRef.current.forEach(unsubFn => unsubFn());
    unsubscribeFnsRef.current.clear();

    // Set up new subscriptions
    subscriptions.forEach(sub => {
      const key = `${sub.entityType}:${sub.entityId}`;
      const unsubFn = wsService.subscribeToRoom(sub.entityType, sub.entityId, sub.callback);
      if (unsubFn) {
        unsubscribeFnsRef.current.set(key, unsubFn);
      }
    });

    // Cleanup when component unmounts or dependencies change
    return () => {
      subscriptions.forEach(sub => {
        const key = `${sub.entityType}:${sub.entityId}`;
        const unsubFn = unsubscribeFnsRef.current.get(key);
        if (unsubFn) {
          unsubFn();
          wsService.unsubscribeFromRoom(sub.entityType, sub.entityId);
        }
      });
      unsubscribeFnsRef.current.clear();
    };
  }, [subscriptions]);

  return {
    isSubscribed: unsubscribeFnsRef.current.size > 0,
    subscriptionCount: unsubscribeFnsRef.current.size
  };
};

/**
 * A React hook to get entity-specific events as they occur
 * 
 * @param entityType Type of entity (e.g., 'resource', 'building')
 * @param entityId ID of the entity
 * @returns The latest entity event message and a clearEvent function
 */
export const useEntityEvents = (
  entityType: string,
  entityId: number | string
) => {
  const [lastEvent, setLastEvent] = useState<WebSocketMessage | null>(null);
  
  const handleEntityEvent = useCallback((message: WebSocketMessage) => {
    setLastEvent(message);
  }, []);

  // Use the entity subscription hook
  const { unsubscribe, isSubscribed } = useEntitySubscription(
    entityType,
    entityId,
    handleEntityEvent
  );

  // Function to clear the last event
  const clearEvent = useCallback(() => {
    setLastEvent(null);
  }, []);

  return {
    lastEvent,
    clearEvent,
    isSubscribed,
    unsubscribe
  };
};