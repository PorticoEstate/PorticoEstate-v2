'use client';

import {useEffect, useRef, useCallback, useState} from 'react';
import { WebSocketService } from '../websocket/websocket-service';
import { WebSocketMessage, IWSRoomPingMessage, IWSRoomPingResponseMessage } from '../websocket/websocket.types';
import { SubscriptionCallback } from '../websocket/subscription-manager';
import {wsLog as wslogbase} from "@/service/websocket/util";
import { useWebSocketContext } from '../websocket/websocket-context';


const wsLog = (message: string, ...optionalParams: any[]) => wslogbase('WSSubscriptions', message, optionalParams)


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
  // Use WebSocketContext to get current status including session connection state
  const wsContext = useWebSocketContext();
  // Track the current status
  const [serviceReady, setServiceReady] = useState(wsService.isReady() && wsContext.sessionConnected);

  // Track both websocket status and session connection changes
  useEffect(() => {
    // Update serviceReady based on both conditions:
    // 1. WebSocket is ready and open
    // 2. Session is connected (we've received connection_success)
    const isFullyReady = wsService.isReady() && 
                         wsContext.status === 'OPEN' && 
                         wsContext.sessionConnected;
    
    setServiceReady(isFullyReady);
    
    // If we're fully ready now but weren't before, log the transition
    if (isFullyReady) {
      wsLog(`WebSocket session is fully connected, ready for entity subscriptions`);
    }
    
    // Set up status change listener from WebSocketService for backward compatibility
    const statusListener = (event: { status: any }) => {
      const newStatus = event.status;
      // We only set ready if both conditions are met
      if (newStatus === 'OPEN' && wsContext.sessionConnected) {
        setServiceReady(true);
      } else if (newStatus !== 'OPEN') {
        // If the WebSocket is not open, we're definitely not ready
        setServiceReady(false);
      }
    };
    
    // Add event listener for status changes
    wsService.addEventListener('status', statusListener);
    
    // Clean up
    return () => {
      wsService.removeEventListener('status', statusListener);
    };
  }, [wsService, wsContext.status, wsContext.sessionConnected]);

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
      wsLog(`Preparing subscription to ${entityType} ${entityId}`);

      // Check if WebSocket is ready AND session is connected
      // This confirms a complete connection path to the server
      if (wsService.isReady() && wsContext.status === 'OPEN' && wsContext.sessionConnected) {
        wsLog(`WebSocket fully connected - Subscribing to ${entityType} ${entityId}`);
        
        try {
          unsubscribeFnRef.current = wsService.subscribeToRoom(entityType, entityId, stableCallback);
          needsSubscriptionRef.current = false;
        } catch (error) {
          wsLog(`Failed to subscribe to ${entityType} ${entityId}:`, error);
          needsSubscriptionRef.current = true;
        }
      } else {
        // Log different messages based on what's missing
        if (!wsService.isReady()) {
          wsLog(`WebSocket service not initialized - deferring subscription to ${entityType} ${entityId}`);
        } else if (wsContext.status !== 'OPEN') {
          wsLog(`WebSocket not OPEN (status: ${wsContext.status}) - deferring subscription to ${entityType} ${entityId}`);
        } else if (!wsContext.sessionConnected) {
          wsLog(`WebSocket session not connected - deferring subscription to ${entityType} ${entityId}`);
        }
        
        // Mark that we need to subscribe when everything is ready
        needsSubscriptionRef.current = true;

        // Add fake unsubscribe function that will be replaced when real subscription happens
        unsubscribeFnRef.current = () => {
          wsLog(`Cancelling deferred subscription to ${entityType} ${entityId}`);
          needsSubscriptionRef.current = false;
        };
      }
    }

    // Unsubscribe when component unmounts or entityType/entityId changes
    return () => {
      if (unsubscribeFnRef.current) {
        wsLog(`Unsubscribing from ${entityType} ${entityId}`);
        unsubscribeFnRef.current();
        unsubscribeFnRef.current = undefined;
        needsSubscriptionRef.current = false;
        
        // Explicitly tell the server we're unsubscribing
        wsService.unsubscribeFromRoom(entityType, entityId);
      }
    };
  }, [entityType, entityId, wsService, stableCallback, wsContext.status, wsContext.sessionConnected]);

  // Effect to subscribe when service becomes ready AND session is connected
  useEffect(() => {
    // If service just became ready (which now includes session connected check) and we need to subscribe
    if (serviceReady && needsSubscriptionRef.current && !unsubscribeFnRef.current) {
      wsLog(`WebSocket now fully ready with session connected, subscribing to ${entityType} ${entityId}`);
      try {
        unsubscribeFnRef.current = wsService.subscribeToRoom(entityType, entityId, stableCallback);
        needsSubscriptionRef.current = false;
      } catch (error) {
        wsLog(`Failed to subscribe to ${entityType} ${entityId} after service ready:`, error);
      }
    }
  }, [serviceReady, entityType, entityId, stableCallback, wsService]);

  // Manual unsubscribe function
  const unsubscribe = useCallback(() => {
    if (unsubscribeFnRef.current) {
      unsubscribeFnRef.current();
      unsubscribeFnRef.current = undefined;
      needsSubscriptionRef.current = false;
      
      // Explicitly tell the server we're unsubscribing
      wsService.unsubscribeFromRoom(entityType, entityId);
    }
  }, [entityType, entityId, wsService]);

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
  }, [messageType, typedCallback, wsService]);

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
    // Get a snapshot of the current ref value for cleanup
    const unsubscribeFns = unsubscribeFnsRef.current;
    
    // Clear previous subscriptions
    unsubscribeFns.forEach(unsubFn => unsubFn());
    unsubscribeFns.clear();

    // Set up new subscriptions
    subscriptions.forEach(sub => {
      const key = `${sub.entityType}:${sub.entityId}`;
      const unsubFn = wsService.subscribeToRoom(sub.entityType, sub.entityId, sub.callback);
      if (unsubFn) {
        unsubscribeFns.set(key, unsubFn);
      }
    });

    // Cleanup when component unmounts or dependencies change
    return () => {
      subscriptions.forEach(sub => {
        const key = `${sub.entityType}:${sub.entityId}`;
        const unsubFn = unsubscribeFns.get(key);
        if (unsubFn) {
          unsubFn();
          // Explicitly tell the server we're unsubscribing
          wsService.unsubscribeFromRoom(sub.entityType, sub.entityId);
        }
      });
      unsubscribeFns.clear();
    };
  }, [subscriptions, wsService]);

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

/**
 * A React hook that specifically handles room ping messages and automatically responds with room_ping_response
 * This ensures that the client maintains active room memberships by responding to server ping requests
 *
 * @param options Optional configuration for room ping handling
 * @returns Object with information about the latest ping activity
 */
export const useRoomPingHandler = (options?: {
  onPing?: (pingMessage: IWSRoomPingMessage) => void;
  onResponse?: (responseMessage: IWSRoomPingResponseMessage) => void;
  autoRespond?: boolean;
}) => {
  const [lastPing, setLastPing] = useState<IWSRoomPingMessage | null>(null);
  const [lastResponse, setLastResponse] = useState<IWSRoomPingResponseMessage | null>(null);
  const [pingCount, setPingCount] = useState(0);
  const [responseCount, setResponseCount] = useState(0);

  // Handle ping messages
  const handlePing = useCallback((message: IWSRoomPingMessage) => {
    setLastPing(message);
    setPingCount(prev => prev + 1);

    // Auto-respond to ping if enabled (default true)
    const shouldAutoRespond = options?.autoRespond !== false;
    if (shouldAutoRespond) {
      const wsService = WebSocketService.getInstance();
      wsService.sendMessage('room_ping_response', `Auto room ping response for room ${message.roomId}`, {
        roomId: message.roomId,
        pingId: message.pingId
      });
      wsLog(`Auto-responded to room_ping for ${message.roomId}`);
    }

    // Call optional onPing callback
    if (options?.onPing) {
      options.onPing(message);
    }
  }, [options]);

  // Handle ping response messages
  const handleResponse = useCallback((message: IWSRoomPingResponseMessage) => {
    setLastResponse(message);
    setResponseCount(prev => prev + 1);

    // Call optional onResponse callback
    if (options?.onResponse) {
      options.onResponse(message);
    }
  }, [options]);

  // Subscribe to ping messages
  useEffect(() => {
    const wsService = WebSocketService.getInstance();

    // Subscribe to room_ping messages
    const pingUnsub = wsService.subscribeToMessageType('room_ping',
      (message) => handlePing(message as IWSRoomPingMessage));

    // Subscribe to room_ping_response messages
    const responseUnsub = wsService.subscribeToMessageType('room_ping_response',
      (message) => handleResponse(message as IWSRoomPingResponseMessage));

    // Cleanup subscriptions
    return () => {
      pingUnsub();
      responseUnsub();
    };
  }, [handlePing, handleResponse]);

  return {
    lastPing,
    lastResponse,
    pingCount,
    responseCount
  };
};

/**
 * A combined hook that handles both entity subscription and room ping handling
 * This ensures that the client maintains active room memberships by responding to server ping requests
 *
 * @param entityType Type of entity (e.g., 'resource', 'building')
 * @param entityId ID of the entity
 * @param callback Callback function to execute when events for this entity occur
 * @param pingOptions Optional configuration for room ping handling
 * @returns Combined object with subscription and ping handling info
 */
export const useEntitySubscriptionWithPing = (
  entityType: string,
  entityId: number | string,
  callback: SubscriptionCallback,
  pingOptions?: {
    onPing?: (pingMessage: IWSRoomPingMessage) => void;
    onResponse?: (responseMessage: IWSRoomPingResponseMessage) => void;
    autoRespond?: boolean;
  }
) => {
  // Use the entity subscription hook
  const subscription = useEntitySubscription(entityType, entityId, callback);

  // Use the room ping handler for the same entity
  const roomId = `${entityType}_${entityId}`;

  // Set up a callback to capture ping messages for this specific room
  const roomSpecificPingHandler = useCallback((message: IWSRoomPingMessage) => {
    if (message.roomId === roomId && pingOptions?.onPing) {
      pingOptions.onPing(message);
    }
  }, [roomId, pingOptions]);

  // Set up a callback to capture ping response messages for this specific room
  const roomSpecificResponseHandler = useCallback((message: IWSRoomPingResponseMessage) => {
    if (message.roomId === roomId && pingOptions?.onResponse) {
      pingOptions.onResponse(message);
    }
  }, [roomId, pingOptions]);

  // Use the ping handler with our room-specific callbacks
  const pingHandler = useRoomPingHandler({
    onPing: roomSpecificPingHandler,
    onResponse: roomSpecificResponseHandler,
    autoRespond: pingOptions?.autoRespond
  });

  return {
    ...subscription,
    ping: {
      lastPing: pingHandler.lastPing,
      lastResponse: pingHandler.lastResponse,
      pingCount: pingHandler.pingCount,
      responseCount: pingHandler.responseCount
    }
  };
};