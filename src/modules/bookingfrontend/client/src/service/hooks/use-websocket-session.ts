'use client';

import { useEffect, useRef, useCallback } from 'react';
import { WebSocketService } from '../websocket/websocket-service';
import { useSessionId } from './api-hooks';
import { WebSocketMessage, IWSSessionIdRequiredMessage } from '../websocket/websocket.types';
import {wsLog as wslogbase} from "@/service/websocket/util";
const wsLog = (message: string, data: any = null, ...optionalParams: any[]) => wslogbase('WSSocketSession', message, data, optionalParams)

/**
 * Name of the session cookie the browser holds. This is the exact cookie the
 * WebSocket gateway reads out of the handshake headers
 * (src/WebSocket/node/src/modules/session/session.service.ts), so binding the
 * socket to this value is what guarantees it joins the room the server will
 * actually address.
 */
const SESSION_COOKIE_NAME = 'bookingfrontendsession';

/**
 * Read the session cookie the browser is holding right now.
 *
 * NOTE: this is only possible because the session cookie is deliberately NOT
 * HttpOnly — see src/modules/phpgwapi/security/Sessions.php ('httponly' =>
 * false in session_set_cookie_params). That is the sole reason this function
 * can exist. If the cookie is ever made HttpOnly for security reasons this
 * approach becomes unimplementable, and preventing a mis-bound socket falls
 * entirely on the ownership check in SessionService.updateSessionId().
 */
const readSessionCookie = (): string | null => {
  if (typeof document === 'undefined') {
    return null;
  }

  const prefix = `${SESSION_COOKIE_NAME}=`;
  for (const part of document.cookie.split(';')) {
    const trimmed = part.trim();
    if (trimmed.startsWith(prefix)) {
      return decodeURIComponent(trimmed.slice(prefix.length)) || null;
    }
  }

  return null;
};

/**
 * Hook to manage WebSocket session updates
 *
 * This hook will:
 * 1. Derive the session ID from the session cookie the browser holds
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
    // The binding MUST be derived from the cookie the browser is holding right
    // now, never from the cached /user/session response body.
    //
    // On a cold load two endpoints mint a session a millisecond apart:
    //   GET /bookingfrontend/user/session (200) -> mints A, and useSessionId() caches A
    //   GET /bookingfrontend/user         (401) -> mints B
    // whichever response is applied last owns the cookie. Sending the cached A
    // while the cookie holds B binds the socket to a room the server never
    // addresses: broadcasts go to B's room and never arrive, while direct reads
    // are answered for A and succeed with an empty result. Nothing errors, so
    // the client believes it is connected, stops polling, and never recovers.
    // Reading the cookie removes the divergence at the source — there is no
    // longer a second value that could disagree.
    let sessionId = readSessionCookie();

    if (!sessionId) {
      // No cookie yet. Ask the server for a session — the Set-Cookie on that
      // response is what establishes it — then re-read the cookie rather than
      // trusting the response body we just received.
      await refetch();
      sessionId = readSessionCookie();

      if (!sessionId) {
        // Nothing to bind to. Staying unbound is the safe state: the client
        // keeps polling over REST instead of trusting a push channel that
        // would never deliver.
        return;
      }
    }

    // accountId/ssn come from the cached response body and describe the session
    // that body was fetched for. If the cookie has moved on since, that auth
    // info is not ours to send — drop it and realign the cache instead.
    const authInfoMatchesCookie = sessionData?.sessionId === sessionId;
    if (!authInfoMatchesCookie) {
      void refetch();
    }

    wsLog('Updating WebSocket session ID');

    // Send the update_session message with the held session ID + auth info
    wsService.sendMessage('update_session', 'Updating session ID', {
      sessionId,
      ...(authInfoMatchesCookie && sessionData?.accountId && { accountId: sessionData.accountId }),
      ...(authInfoMatchesCookie && sessionData?.ssn && { ssn: sessionData.ssn }),
    });

    // Update the last update timestamp
    lastUpdateRef.current = Date.now();
  }, [sessionData?.sessionId, sessionData?.accountId, sessionData?.ssn, refetch, wsService]);

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
        // Only update if we have a session ID and haven't updated in the last minute.
        // The cookie is the authoritative source here too — on a cold load it can
        // be set before the useSessionId() query has resolved, and gating on the
        // cached body alone would skip the bind that this connection needs.
        if ((readSessionCookie() || sessionData?.sessionId) && Date.now() - lastUpdateRef.current > 60000) {
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