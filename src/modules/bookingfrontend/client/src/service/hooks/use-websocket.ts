import { useEffect, useRef, useState, useCallback } from 'react';

/**
 * Custom hook for WebSocket communication
 * 
 * Features:
 * - Auto-connects to WebSocket server based on environment
 * - Handles reconnection on connection loss
 * - Supports ping/pong for keeping connections alive
 * - Provides methods for sending messages
 * - Exposes connection state and last message
 */
import { WebSocketMessage, WebSocketStatus } from '../websocket/websocket.types';

export interface UseWebSocketOptions {
  customUrl?: string | null;
  autoReconnect?: boolean;
  reconnectInterval?: number;
  pingInterval?: number;
  onOpen?: (event: Event) => void;
  onMessage?: (data: WebSocketMessage | string) => void;
  onClose?: (event: CloseEvent) => void;
  onError?: (event: Event) => void;
}

export const useWebSocket = (options: UseWebSocketOptions = {}) => {
  const {
    customUrl = null,
    autoReconnect = true,
    reconnectInterval = 5000,
    pingInterval = 30000,
    onOpen,
    onMessage,
    onClose,
    onError
  } = options;

  const [status, setStatus] = useState<WebSocketStatus>('CLOSED');
  const [lastMessage, setLastMessage] = useState<WebSocketMessage | null>(null);
  const wsRef = useRef<WebSocket | null>(null);
  const reconnectTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const pingIntervalRef = useRef<NodeJS.Timeout | null>(null);

  const getWebSocketUrl = useCallback(() => {
    if (customUrl) return customUrl;

    // Use relative URL when in browser environment
    if (typeof window !== 'undefined') {
      const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      const host = window.location.host;
      return `${protocol}//${host}/wss`;
    }

    // Return null when in server-side rendering
    return null;
  }, [customUrl]);

  const connect = useCallback(() => {
    if (typeof window === 'undefined') return; // Don't connect during SSR

    // Clean up existing connection
    if (wsRef.current) {
      wsRef.current.close();
    }

    const wsUrl = getWebSocketUrl();
    if (!wsUrl) return;
    
    console.log('Connecting to WebSocket server:', wsUrl);
    setStatus('CONNECTING');
    
    // Note: The WebSocket constructor will automatically include cookies
    // for its origin domain, including the bookingfrontendsession cookie
    const ws = new WebSocket(wsUrl);
    wsRef.current = ws;

    ws.onopen = (event) => {
      console.log('WebSocket connection established');
      setStatus('OPEN');
      
      // Setup ping interval for keeping connection alive
      if (pingIntervalRef.current) {
        clearInterval(pingIntervalRef.current);
      }
      
      pingIntervalRef.current = setInterval(() => {
        if (ws.readyState === WebSocket.OPEN) {
          ws.send(JSON.stringify({
            type: 'ping',
            timestamp: new Date().toISOString()
          }));
          console.log('Ping sent');
        }
      }, pingInterval);
      
      if (onOpen) onOpen(event);
    };

    ws.onmessage = (event) => {
      console.log('Message received:', event.data);
      try {
        const data = JSON.parse(event.data) as WebSocketMessage;
        setLastMessage(data);
        
        // Handle special message types
        if (data.type === 'server_ping') {
          // Respond to server pings with pong
          ws.send(JSON.stringify({
            type: 'pong',
            timestamp: new Date().toISOString()
          }));
        } else if (data.type === 'reconnect_required') {
          // Server is requesting the client to reconnect
          console.log('Server requested reconnection:', data.message);
          
          // Clear existing intervals first
          if (pingIntervalRef.current) {
            clearInterval(pingIntervalRef.current);
            pingIntervalRef.current = null;
          }
          
          if (reconnectTimeoutRef.current) {
            clearTimeout(reconnectTimeoutRef.current);
            reconnectTimeoutRef.current = null;
          }
          
          // Close current connection
          ws.close();
          
          // Wait briefly before reconnecting
          console.log('Attempting to reconnect as requested by server...');
          setStatus('RECONNECTING');
          
          // Setting a timeout to trigger reconnection
          reconnectTimeoutRef.current = setTimeout(() => {
            if (wsRef.current) {
              wsRef.current.close();
              wsRef.current = null;
            }
            // The connect function will be called in the useEffect
            // which monitors reconnectTimeoutRef changes
            setStatus('CONNECTING');
          }, 1000);
        }
        
        if (onMessage) onMessage(data);
      } catch (e) {
        // Handle non-JSON messages
        console.log('Received non-JSON message:', event.data);
        if (onMessage) onMessage(event.data);
      }
    };

    ws.onclose = (event) => {
      console.log('WebSocket connection closed:', event.code, event.reason);
      setStatus('CLOSED');
      
      // Clear intervals
      if (pingIntervalRef.current) {
        clearInterval(pingIntervalRef.current);
        pingIntervalRef.current = null;
      }
      
      if (onClose) onClose(event);
      
      // Reconnect if autoReconnect is enabled
      if (autoReconnect) {
        console.log(`Attempting to reconnect in ${reconnectInterval / 1000} seconds...`);
        setStatus('RECONNECTING');
        
        if (reconnectTimeoutRef.current) {
          clearTimeout(reconnectTimeoutRef.current);
        }
        
        reconnectTimeoutRef.current = setTimeout(() => {
          connect();
        }, reconnectInterval);
      }
    };

    ws.onerror = (event) => {
      console.error('WebSocket error:', event);
      setStatus('ERROR');
      if (onError) onError(event);
    };
  }, [
    getWebSocketUrl, 
    autoReconnect, 
    reconnectInterval, 
    pingInterval, 
    onOpen, 
    onMessage, 
    onClose, 
    onError
  ]);

  const sendMessage = useCallback((type: string, message: string, additionalData: Record<string, any> = {}) => {
    if (!wsRef.current || wsRef.current.readyState !== WebSocket.OPEN) {
      console.error('WebSocket is not connected');
      return false;
    }

    const data = {
      type,
      message,
      ...additionalData,
      timestamp: new Date().toISOString()
    };

    wsRef.current.send(JSON.stringify(data));
    console.log('Message sent:', data);
    return true;
  }, []);

  const closeConnection = useCallback(() => {
    if (wsRef.current) {
      wsRef.current.close();
    }
    
    if (pingIntervalRef.current) {
      clearInterval(pingIntervalRef.current);
      pingIntervalRef.current = null;
    }
    
    if (reconnectTimeoutRef.current) {
      clearTimeout(reconnectTimeoutRef.current);
      reconnectTimeoutRef.current = null;
    }
  }, []);

  // Connect on component mount, disconnect on unmount
  useEffect(() => {
    connect();
    
    return () => {
      closeConnection();
    };
  }, [connect, closeConnection]);
  
  // Handle reconnection when status changes to CONNECTING
  useEffect(() => {
    if (status === 'CONNECTING' && !wsRef.current) {
      connect();
    }
  }, [status, connect]);

  return {
    status,
    lastMessage,
    sendMessage,
    closeConnection,
    reconnect: connect
  };
};