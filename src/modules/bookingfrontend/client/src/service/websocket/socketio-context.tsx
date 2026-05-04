'use client';

import React, {
  ReactNode,
  useState,
  useEffect,
  useCallback,
  useRef,
} from 'react';
import { io, Socket } from 'socket.io-client';
import { WebSocketMessage, WebSocketStatus } from './websocket.types';
import { WebSocketContext, WebSocketContextValue } from './websocket-context';
import { WebSocketService } from './websocket-service';
import { SubscriptionManager } from './subscription-manager';
import { useWebSocketSession } from '../hooks/use-websocket-session';
import { useCacheInvalidation } from '@/service/hooks/use-cache-invalidation';
import { wsLog as wslogbase } from './util';

const wsLog = (message: string, ...args: any[]) =>
  wslogbase('SocketIO', message, ...args);

interface SocketIOProviderProps {
  children: ReactNode;
  url?: string;
}

/**
 * Socket.IO-based WebSocket provider.
 * Provides to the same WebSocketContext as the raw WebSocket provider,
 * so all existing hooks (useWebSocketContext, useEntitySubscription, etc.)
 * work without any import changes.
 *
 * Activate by setting NEXT_PUBLIC_WS_TRANSPORT=socketio
 */
export const SocketIOProvider: React.FC<SocketIOProviderProps> = ({
  children,
  url,
}) => {
  const [status, setStatus] = useState<WebSocketStatus>('CLOSED');
  const [lastMessage, setLastMessage] = useState<WebSocketMessage | null>(null);
  const [isReady, setIsReady] = useState(false);
  const [sessionConnected, setSessionConnected] = useState(false);
  const socketRef = useRef<Socket | null>(null);
  const isInitializedRef = useRef(false);
  const subscriptionManager = useRef(SubscriptionManager.getInstance());

  useWebSocketSession();
  useCacheInvalidation();

  const handleMessage = useCallback((data: WebSocketMessage) => {
    setLastMessage(data);
    subscriptionManager.current.handleMessage(data);

    switch (data.type) {
      case 'connection_success':
        wsLog(`Connected to session room ${data.roomId}`);
        setSessionConnected(true);
        break;

      case 'notification':
        if (typeof window !== 'undefined') {
          import('./notification-helper').then(
            ({ processWebSocketMessageForNotification }) => {
              processWebSocketMessageForNotification(data).catch((e) =>
                console.error('Notification error:', e),
              );
            },
          );
        }
        break;

      case 'session_id_required':
        wsLog('Session ID required');
        break;

      case 'server_ping':
        socketRef.current?.emit('message', {
          type: 'pong',
          timestamp: new Date().toISOString(),
          reply_to: data.id || null,
        });
        break;

      case 'reconnect_required':
        wsLog('Server requested reconnection');
        socketRef.current?.disconnect();
        socketRef.current?.connect();
        break;
    }
  }, []);

  useEffect(() => {
    if (typeof window === 'undefined') return;
    if (isInitializedRef.current) return;
    isInitializedRef.current = true;

    const socketUrl = url || getSocketIOUrl();
    if (!socketUrl) {
      console.error('No Socket.IO URL available');
      setStatus('ERROR');
      return;
    }

    wsLog(`Connecting to ${socketUrl}`);
    setStatus('CONNECTING');

    const socket = io(socketUrl, {
      transports: ['polling', 'websocket'],
      reconnection: true,
      reconnectionDelay: 5000,
      reconnectionAttempts: Infinity,
      withCredentials: true,
      path: '/wss-io/socket.io',
    });

    socketRef.current = socket;

    // Bridge the WebSocketService singleton to Socket.IO.
    // Hooks like useEntitySubscription call wsService.sendMessage() which
    // dispatches 'direct_message' events. We intercept those and forward
    // them through the Socket.IO socket.
    const wsService = WebSocketService.getInstance();

    // Mark the singleton as initialized so hooks don't bail out
    // @ts-ignore – accessing private field
    wsService['isInitialized'] = true;
    // @ts-ignore
    wsService['status'] = 'OPEN';

    const directMessageHandler = (event: any) => {
      if (socket.connected && event.data) {
        socket.emit('message', event.data);
      }
    };
    wsService.addEventListener('direct_message', directMessageHandler);

    socket.on('connect', () => {
      wsLog('Connected');
      setStatus('OPEN');
      setIsReady(true);

      // Resubscribe to entity rooms after reconnect
      const subs = subscriptionManager.current.getActiveEntitySubscriptions();
      subs.forEach((sub, i) => {
        setTimeout(() => {
          socket.emit('message', {
            type: 'subscribe',
            message: `Subscribing to ${sub.entityType} ${sub.entityId}`,
            entityType: sub.entityType,
            entityId: sub.entityId,
            timestamp: new Date().toISOString(),
          });
        }, i * 50);
      });
    });

    socket.on('disconnect', (reason) => {
      wsLog(`Disconnected: ${reason}`);
      setStatus('CLOSED');
      setSessionConnected(false);

      if (reason === 'io server disconnect') {
        socket.connect();
      }
    });

    socket.on('connect_error', (err) => {
      wsLog(`Connection error: ${err.message}`);
      setStatus('ERROR');
    });

    socket.io.on('reconnect_attempt', () => {
      setStatus('RECONNECTING');
    });

    socket.io.on('reconnect', () => {
      wsLog('Reconnected');
      setStatus('OPEN');
    });

    socket.on('message', (data: WebSocketMessage) => {
      handleMessage(data);
    });

    return () => {
      wsService.removeEventListener('direct_message', directMessageHandler);
      socket.disconnect();
      socketRef.current = null;
      isInitializedRef.current = false;
    };
  }, [url, handleMessage]);

  const sendMessage = useCallback(
    (
      type: string,
      message: string,
      additionalData: Record<string, any> = {},
    ): boolean => {
      const socket = socketRef.current;
      if (!socket?.connected) {
        console.error('Socket.IO not connected');
        return false;
      }

      socket.emit('message', {
        type,
        message,
        ...additionalData,
        timestamp: new Date().toISOString(),
      });
      return true;
    },
    [],
  );

  const closeConnection = useCallback(() => {
    socketRef.current?.disconnect();
    setStatus('CLOSED');
  }, []);

  const reconnect = useCallback(() => {
    socketRef.current?.disconnect();
    socketRef.current?.connect();
  }, []);

  const value: WebSocketContextValue = {
    status,
    lastMessage,
    sendMessage,
    reconnect,
    closeConnection,
    isReady,
    sessionConnected,
  };

  return (
    <WebSocketContext.Provider value={value}>
      {children}
    </WebSocketContext.Provider>
  );
};

function getSocketIOUrl(): string {
  if (typeof window === 'undefined') return '';
  // Socket.IO connects to the same origin — the path determines the backend
  const protocol = window.location.protocol === 'https:' ? 'https:' : 'http:';
  const host = window.location.host;
  return `${protocol}//${host}`;
}
