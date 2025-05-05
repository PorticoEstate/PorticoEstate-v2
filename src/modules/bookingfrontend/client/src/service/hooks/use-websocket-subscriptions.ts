'use client';

import {useEffect, useRef, useCallback, useState} from 'react';
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
  const callbackRef = useRef(callback);
  // Track whether we need to attempt subscription when service becomes ready
  const needsSubscriptionRef = useRef(false);
  // Track the current status
  const [serviceReady, setServiceReady] = useState(wsService.isReady());
  
  // Listen for websocket status changes
  useEffect(() => {
    const statusListener = (event: { status: any }) => {
      const newStatus = event.status;
      if (newStatus === 'OPEN') {
        setServiceReady(true);
      }
    };
    
    // Add event listener for status changes
    wsService.addEventListener('status', statusListener);
    
    // Clean up
    return () => {
      wsService.removeEventListener('status', statusListener);
    };
  }, [wsService]);
  
  // Update the callback ref when it changes
  useEffect(() => {
    callbackRef.current = callback;
  }, [callback]);
  
  // Stable callback that uses the ref
  const stableCallback = useCallback((message: WebSocketMessage) => {
    callbackRef.current(message);
  }, []); // No dependencies for the stable callback

  // Effect for initial subscription
  useEffect(() => {
    // Only subscribe if not already subscribed to the same entity
    if (!unsubscribeFnRef.current) {
      console.log(`Subscribing to ${entityType} ${entityId}`);
      
      // Special check for direct WebSocket mode
      // In direct mode, the WebSocket might be OPEN but the service not marked as initialized
      const isDirectWebSocketReady = (() => {
        // Check if we're using direct WebSocket by looking at context status
        // If the WebSocketContext shows OPEN but isReady() is false, we're likely in direct mode
        try {
          if (typeof window !== 'undefined') {
            // @ts-ignore - Access private status field to detect if the WebSocket is actually OPEN
            const status = wsService['status']; 
            return status === 'OPEN' && !wsService.isReady();
          }
        } catch (e) {
          // Ignore errors trying to access private fields
        }
        return false;
      })();
      
      // Try to subscribe if service is ready OR we're in direct WebSocket mode with an open connection
      if (wsService.isReady() || isDirectWebSocketReady) {
        try {
          // If we're in direct WebSocket mode with ready connection but uninitialized service,
          // force set the initialization flag
          if (isDirectWebSocketReady) {
            console.log(`Detected direct WebSocket open but uninitialized for ${entityType} ${entityId}`);
            // @ts-ignore - Force set the internal isInitialized flag
            wsService['isInitialized'] = true;
          }
          
          unsubscribeFnRef.current = wsService.subscribeToRoom(entityType, entityId, stableCallback);
          needsSubscriptionRef.current = false;
        } catch (error) {
          console.log(`Failed to subscribe to ${entityType} ${entityId}:`, error);
          needsSubscriptionRef.current = true;
        }
      } else {
        console.log(`Deferring subscription to ${entityType} ${entityId} until WebSocket is ready`);
        // Mark that we need to subscribe when service becomes ready
        needsSubscriptionRef.current = true;
        
        // Add fake unsubscribe function that will be replaced when real subscription happens
        unsubscribeFnRef.current = () => {
          console.log(`Cancelling deferred subscription to ${entityType} ${entityId}`);
          needsSubscriptionRef.current = false;
        };
      }
    }

    // Unsubscribe when component unmounts or entityType/entityId changes
    return () => {
      if (unsubscribeFnRef.current) {
        console.log(`Unsubscribing from ${entityType} ${entityId}`);
        unsubscribeFnRef.current();
        unsubscribeFnRef.current = undefined;
        needsSubscriptionRef.current = false;
        // No explicit unsubscribe needed - server will detect inactive subscriptions via ping-pong
      }
    };
  }, [entityType, entityId, wsService, stableCallback]);
  
  // Effect to subscribe when service becomes ready
  useEffect(() => {
    // If service just became ready and we need to subscribe
    if (serviceReady && needsSubscriptionRef.current && !unsubscribeFnRef.current) {
      console.log(`WebSocket now ready, subscribing to ${entityType} ${entityId}`);
      try {
        unsubscribeFnRef.current = wsService.subscribeToRoom(entityType, entityId, stableCallback);
        needsSubscriptionRef.current = false;
      } catch (error) {
        console.log(`Failed to subscribe to ${entityType} ${entityId} after service ready:`, error);
      }
    }
  }, [serviceReady, entityType, entityId, stableCallback, wsService]);

  // Manual unsubscribe function
  const unsubscribe = useCallback(() => {
    if (unsubscribeFnRef.current) {
      unsubscribeFnRef.current();
      unsubscribeFnRef.current = undefined;
      needsSubscriptionRef.current = false;
    }
    // No explicit unsubscribe needed - server will detect inactive subscriptions via ping-pong
  }, []);

  return {
    unsubscribe,
    isSubscribed: !!unsubscribeFnRef.current,
    isServiceReady: serviceReady
  };
};

/**
 * A React hook for subscribing to WebSocket message types with type safety
 * Message type is automatically inferred based on the messageType string
 *
 * @template T Type of message to subscribe to (string literal)
 * @param messageType Type of message to subscribe to
 * @param callback Callback function to execute when messages of this type are received
 * @returns Unsubscribe function and subscription status
 */
export const useMessageTypeSubscription = <T extends WebSocketMessage['type']>(
  messageType: T,
  callback: (message: Extract<WebSocketMessage, { type: T }>) => void
) => {
  const unsubscribeFnRef = useRef<(() => void) | undefined>(undefined);
  const wsService = WebSocketService.getInstance();

  // Type safe wrapper for the callback
  const typedCallback = useCallback((message: WebSocketMessage) => {
    // This cast is safe because we're filtering by type in the subscription
    // and the Extract utility type ensures we only get messages of the correct type
    callback(message as Extract<WebSocketMessage, { type: T }>);
  }, [callback]);

  useEffect(() => {
    // Subscribe when component mounts
    unsubscribeFnRef.current = wsService.subscribeToMessageType(messageType, typedCallback);

    // Unsubscribe when component unmounts
    return () => {
      if (unsubscribeFnRef.current) {
        unsubscribeFnRef.current();
        unsubscribeFnRef.current = undefined;
      }
    };
  }, [messageType, typedCallback]);

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
          // No explicit unsubscribe needed - server will detect inactive subscriptions via ping-pong
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