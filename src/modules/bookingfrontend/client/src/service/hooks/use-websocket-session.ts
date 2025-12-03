'use client';

import { useEffect, useRef, useCallback } from 'react';
import { WebSocketService } from '../websocket/websocket-service';
import { useSessionId } from './api-hooks';
import { WebSocketMessage, IWSSessionIdRequiredMessage } from '../websocket/websocket.types';
import {wsLog as wslogbase} from "@/service/websocket/util";
const wsLog = (message: string, data: any = null, ...optionalParams: any[]) => wslogbase('WSSocketSession', message, data, optionalParams)

/**
 * Hook to manage WebSocket session updates
 *
 * This hook will:
 * 1. Fetch the session ID from the server
 * 2. Listen for session_id_required messages from the WebSocket server
 * 3. Automatically update the session ID when required
 * 4. Periodically update the session ID every 5 minutes
 *
 * @returns An object containing the session update status
 */
export const useWebSocketSession = () => {
  const wsService = WebSocketService.getInstance();
  const lastUpdateRef = useRef<number>(0);
  const updateIntervalRef = useRef<NodeJS.Timeout | null>(null);
  const { data: sessionData, refetch } = useSessionId();

  // Function to send session ID update to WebSocket server
  const updateSessionId = useCallback(async () => {
    if (!sessionData?.sessionId) {
      // If we don't have a session ID yet, refetch it
      await refetch();
      return;
    }

    wsLog('Updating WebSocket session ID');

    // Send the update_session message with the current session ID
    wsService.sendMessage('update_session', 'Updating session ID', {
      sessionId: sessionData.sessionId
    });

    // Update the last update timestamp
    lastUpdateRef.current = Date.now();
  }, [sessionData?.sessionId, refetch, wsService]);

  // Handler for session_id_required messages
  const handleSessionRequired = useCallback((message: IWSSessionIdRequiredMessage) => {
    wsLog('Session ID required:', message);
    updateSessionId();
  }, [updateSessionId]);

  // Set up the session ID update interval (every 5 minutes)
  useEffect(() => {
    // Clear any existing interval
    if (updateIntervalRef.current) {
      clearInterval(updateIntervalRef.current);
    }

    // Set up a new interval to update the session ID every 5 minutes
    updateIntervalRef.current = setInterval(() => {
      updateSessionId();
    }, 5 * 60 * 1000); // 5 minutes

    // Cleanup on unmount
    return () => {
      if (updateIntervalRef.current) {
        clearInterval(updateIntervalRef.current);
        updateIntervalRef.current = null;
      }
    };
  }, [updateSessionId]);

  // Set up the WebSocket message handler
  useEffect(() => {
    // Handler for WebSocket messages
    const messageHandler = (event: { data: WebSocketMessage }) => {
      try {
        const message = event.data;

        // Handle session_id_required messages
        if (message.type === 'session_id_required') {
          handleSessionRequired(message as IWSSessionIdRequiredMessage);
        }
      } catch (error) {
        console.error('Error handling WebSocket message:', error);
      }
    };

    try {
      // Add the message event listener
      wsService.addEventListener('message', messageHandler);

      // Cleanup on unmount
      return () => {
        try {
          wsService.removeEventListener('message', messageHandler);
        } catch (error) {
          console.error('Error removing message event listener:', error);
        }
      };
    } catch (error) {
      console.error('Error setting up WebSocket message handler:', error);
      return () => {}; // Return empty cleanup function
    }
  }, [wsService, handleSessionRequired]);

  // Initial session ID update when the connection is established
  useEffect(() => {
    // Function to check if we need to update the session ID
    const checkAndUpdateSession = () => {
      try {
        // Only update if we have a session ID and haven't updated in the last minute
        if (sessionData?.sessionId && Date.now() - lastUpdateRef.current > 60000) {
          updateSessionId();
        }
      } catch (error) {
        console.error('Error in checkAndUpdateSession:', error);
      }
    };

    // Status change handler
    const statusHandler = (event: { status: string }) => {
      if (event.status === 'OPEN') {
        // Wait a bit after connection is established to update the session ID
        setTimeout(checkAndUpdateSession, 1000);
      }
    };

    try {
      // Add the status event listener
      wsService.addEventListener('status', statusHandler);

      // If the service is already ready, update the session ID
      if (wsService.isReady()) {
        checkAndUpdateSession();
      }

      // Cleanup on unmount
      return () => {
        try {
          wsService.removeEventListener('status', statusHandler);
        } catch (error) {
          console.error('Error removing status event listener:', error);
        }
      };
    } catch (error) {
      console.error('Error setting up WebSocket session management:', error);
      return () => {}; // Return empty cleanup function
    }
  }, [wsService, sessionData?.sessionId, updateSessionId]);

  return {
    sessionId: sessionData?.sessionId,
    isSessionUpdated: !!lastUpdateRef.current,
    updateSessionId
  };
};