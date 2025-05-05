'use client';

import React, {createContext, useContext, ReactNode, useState, useEffect, useCallback, useRef} from 'react';
import { WebSocketMessage, WebSocketStatus, ServiceWorkerWebSocketOptions, IWSServerDeletedMessage } from './websocket.types';
import { WebSocketService } from './websocket-service';
import { hasServiceWorkerAPI } from './service-worker-check';
import { getWebSocketUrl, wsLog as wslogbase } from './util';
import { useQueryClient } from '@tanstack/react-query';
import { SubscriptionManager } from './subscription-manager';
import { useWebSocketSession } from '../hooks/use-websocket-session';

interface WebSocketContextValue {
  status: WebSocketStatus;
  lastMessage: WebSocketMessage | null;
  sendMessage: (type: string, message: string, additionalData?: Record<string, any>) => boolean;
  reconnect: () => void;
  closeConnection: () => void;
  isReady: boolean;
}

const wsLog = (message: string, ...optionalParams: any[]) => wslogbase('WSContext', message, optionalParams)

const WebSocketContext = createContext<WebSocketContextValue | undefined>(undefined);

interface WebSocketProviderProps {
  children: ReactNode;
  customUrl?: string;
  autoReconnect?: boolean;
  reconnectInterval?: number;
  pingInterval?: number;
  disableServiceWorker?: boolean;
}

export const WebSocketProvider: React.FC<WebSocketProviderProps> = ({
  children,
  customUrl,
  autoReconnect = true,
  reconnectInterval = 5000,
  pingInterval = 600000, // Changed from 30000 (30s) to 600000 (10min)
  disableServiceWorker = false,
}) => {
  const [status, setStatus] = useState<WebSocketStatus>('CLOSED');
  const [lastMessage, setLastMessage] = useState<WebSocketMessage | null>(null);
  const [isReady, setIsReady] = useState(false);
  const [wsService] = useState(() => WebSocketService.getInstance());
  const wsRef = useRef<WebSocket | null>(null);
  const pingIntervalRef = useRef<NodeJS.Timeout | null>(null);
  // Add a ref to store the direct message listener function for cleanup
  const wsDirectMessageListenerRef = useRef<((event: any) => void) | null>(null);
  const queryClient = useQueryClient();

  // Initialize session management
  useWebSocketSession();


  // Keep track of initialization state with a ref to handle StrictMode double-invocation
  const isInitializedRef = useRef(false);

  // Initialize the WebSocket service on mount
  useEffect(() => {
    // StrictMode protection - prevent double initialization
    if (isInitializedRef.current) {
      wsLog('WebSocket already initialized, skipping redundant initialization (StrictMode protection)');
      return;
    }

    if (typeof window === 'undefined') return; // Skip on server-side rendering

    // Setup function for direct WebSocket connection as fallback
    const setupDirectWebSocket = () => {
      // StrictMode protection - prevent creating a new connection if one already exists and is open
      if (wsRef.current && wsRef.current.readyState === WebSocket.OPEN) {
        wsLog('Direct WebSocket connection already exists and is open, skipping creation');
        return;
      }

      // If we have a reference but it's not in OPEN state, clean it up before creating a new one
      if (wsRef.current && wsRef.current.readyState !== WebSocket.OPEN) {
        wsLog('Existing WebSocket is not in OPEN state, cleaning up before reconnecting');
        try {
          wsRef.current.close();
        } catch (e) {
          console.error('Error closing non-open WebSocket:', e);
        }
        wsRef.current = null;
      }

      try {
        const wsUrl = customUrl || getDefaultWebSocketUrl();
        if (!wsUrl) {
          console.error('No WebSocket URL available');
          setStatus('ERROR');
          return;
        }

        wsLog('Creating new direct WebSocket connection');
        // Create a new WebSocket connection
        const ws = new WebSocket(wsUrl);
        isInitializedRef.current = true;

        // Store WebSocket reference in window object for direct WebSocket mode detection
        if (typeof window !== 'undefined') {
          // @ts-ignore - Store a reference to the active WebSocket for direct mode
          window.__directWebSocketRef = ws;
        }

        // Set up WebSocket event handlers
        ws.onopen = () => {
          wsLog('Direct WebSocket connection established');
          setStatus('OPEN');

          // Important: Mark the WebSocketService as initialized when direct WebSocket connects
          // This ensures useEntitySubscription and other hooks work properly with direct WebSocket mode
          const wsService = WebSocketService.getInstance();
          if (!wsService.isReady()) {
            wsLog('Auto-initializing WebSocketService for direct WebSocket mode');
            // @ts-ignore - Force set the internal isInitialized flag
            wsService['isInitialized'] = true;

            // Set up event listener for direct messages from WebSocketService
            const directMessageListener = (event: any) => {
              if (ws.readyState === WebSocket.OPEN && event.data) {
                try {
                  // The data structure is different from what we expected
                  // The direct_message event has a 'data' property which is the actual message to send
                  wsLog('Received direct_message event, sending via WebSocket', event.data);

                  // Use the data property directly - it already contains the properly formatted message
                  ws.send(JSON.stringify(event.data));
                } catch (error) {
                  console.error('Error sending direct message via WebSocket:', error);
                }
              }
            };

            // Remove any existing listener for cleanup
            if (wsDirectMessageListenerRef.current) {
              wsService.removeEventListener('direct_message', wsDirectMessageListenerRef.current);
            }

            // Add the new listener
            wsService.addEventListener('direct_message', directMessageListener);
            wsDirectMessageListenerRef.current = directMessageListener;
          }

          // Setup ping interval
          if (pingIntervalRef.current) {
            clearInterval(pingIntervalRef.current);
          }
          pingIntervalRef.current = setInterval(() => {
            if (ws.readyState === WebSocket.OPEN) {
              ws.send(JSON.stringify({
                type: 'ping',
                timestamp: new Date().toISOString()
              }));
            }
          }, pingInterval || 600000);

          // Store interval ID for cleanup
          setTimeout(() => {
            // This is a hack to avoid the "cannot update state during render" error
            setIsReady(true);
          }, 0);
        };

        ws.onmessage = (event) => {
          try {
            const data = JSON.parse(event.data) as WebSocketMessage;
            setTimeout(() => {
              setLastMessage(data);

              // Specialized handling for reconnect_required in the initial setup
              if (data.type === 'reconnect_required') {
                wsLog('Server requested reconnection:', data.message);

                // Clear existing ping interval
                if (pingIntervalRef.current) {
                  clearInterval(pingIntervalRef.current);
                  pingIntervalRef.current = null;
                }

                // Close current connection
                ws.close();
                wsRef.current = null;

                // Trigger reconnection after a brief delay
                setStatus('RECONNECTING');
                setTimeout(() => {
                  setStatus('CONNECTING');
                }, 1000);
              }
              // Handle server_ping directly in the WebSocket handler
              else if (data.type === 'server_ping') {
                // For direct connection mode, immediately respond with a pong
                try {
                  ws.send(JSON.stringify({
                    type: 'pong',
                    timestamp: new Date().toISOString(),
                    reply_to: data.id || null
                  }));
                } catch (error) {
                  console.error('Error sending pong response to server_ping:', error);
                }

                // Process through subscription manager for direct WebSocket mode
                const subscriptionManager = SubscriptionManager.getInstance();
                subscriptionManager.handleMessage(data);

                // Also use the standard message handler
                handleMessage(data);
              }
              // For all other messages, use the standard handler
              else {
                // Process through subscription manager for direct WebSocket mode
                // This ensures message subscriptions work even without service worker
                const subscriptionManager = SubscriptionManager.getInstance();
                subscriptionManager.handleMessage(data);

                // Also use the standard message handler for backward compatibility
                handleMessage(data);
              }
            }, 0);
          } catch (e) {
            console.error('Error parsing WebSocket message:', e);
          }
        };

        ws.onclose = (event) => {
          wsLog('Direct WebSocket connection closed', event.code, event.reason);
          setStatus('CLOSED');

          // Important: Set wsRef to null if the connection is actually closed
          // This ensures we don't have a reference to a closed socket
          if (wsRef.current && wsRef.current.readyState === WebSocket.CLOSED) {
            wsLog('Setting websocket reference to null since connection is closed');
            wsRef.current = null;
          }

          // Reconnect if needed
          if (autoReconnect) {
            wsLog(`Attempting to reconnect in ${reconnectInterval || 5000}ms`);
            setStatus('RECONNECTING');

            // Only setup a new reconnection if not already reconnecting
            setTimeout(() => {
              if (!isReconnectingRef.current) {
                setupDirectWebSocket();
              } else {
                wsLog('Reconnection already in progress, skipping additional reconnect attempt');
              }
            }, reconnectInterval || 5000);
          } else {
            // Reset initialization flags when not auto-reconnecting
            isInitializedRef.current = false;
            isReconnectingRef.current = false;
          }
        };

        ws.onerror = () => {
          console.error('Direct WebSocket error');
          setStatus('ERROR');
          // Reset reconnection flag on error
          isReconnectingRef.current = false;
        };

        // Store the WebSocket instance in a ref for later use
        wsRef.current = ws;
      } catch (error) {
        console.error('Failed to setup direct WebSocket:', error);
        setStatus('ERROR');
        isInitializedRef.current = false;
        isReconnectingRef.current = false;
      }
    };

    // Check if service worker is explicitly disabled via prop
    if (disableServiceWorker) {
      wsLog('Service Worker explicitly disabled via disableServiceWorker prop - using direct WebSocket');
      // Setup a direct WebSocket connection as fallback
      setupDirectWebSocket();
      return;
    }

    // Perform a basic check for service worker support
    const swSupported = hasServiceWorkerAPI(disableServiceWorker);

    // If service workers aren't supported, use direct WebSocket
    if (!swSupported) {
      console.warn('Service Workers are not supported in this browser - using direct WebSocket fallback');

      // Setup a direct WebSocket connection as fallback
      setupDirectWebSocket();
      return;
    }

    // Configure options for WebSocket
    const options: ServiceWorkerWebSocketOptions = {
      url: customUrl || getDefaultWebSocketUrl(),
      autoReconnect,
      reconnectInterval,
      pingInterval,
      disableServiceWorker
    };

    // Set up event listeners
    const statusListener = (event: { status: WebSocketStatus }) => {
      setStatus(event.status);
    };

    const messageListener = (event: { data: WebSocketMessage }) => {
      setLastMessage(event.data);
      handleMessage(event.data);
    };

    // Register event listeners
    wsService.addEventListener('status', statusListener);
    wsService.addEventListener('message', messageListener);

    // Attempt to initialize the WebSocket service with error handling
    const initWebSocketService = async () => {
      wsLog('Starting WebSocket service initialization...');
      try {
        const success = await wsService.initialize(options);

        // If service worker initialization succeeded
        if (success) {
          wsLog('WebSocket service initialization successful!');
          setIsReady(success);
        }
        // If service worker initialization failed, check if fallback is needed
        else if (status === 'FALLBACK_REQUIRED') {
          console.warn('Service worker initialization failed, falling back to direct WebSocket connection');
          // Don't set isReady yet - the direct connection will handle this

          // Let the existing direct WebSocket code handle it by setting status to CONNECTING
          // This will trigger the useEffect that watches for CONNECTING status
          setStatus('CONNECTING');
        }
        else {
          console.error('Failed to initialize WebSocket service worker');
          setStatus('ERROR');
        }
      } catch (error) {
        console.error('Error initializing WebSocket service:', error);
        setIsReady(false);
        setStatus('ERROR');
      }
    };

    initWebSocketService();

    // Cleanup function
    return () => {
      // Clean up service worker event listeners
      wsService.removeEventListener('status', statusListener);
      wsService.removeEventListener('message', messageListener);

      // Clean up direct message listener if it exists
      if (wsDirectMessageListenerRef.current) {
        wsService.removeEventListener('direct_message', wsDirectMessageListenerRef.current);
        wsDirectMessageListenerRef.current = null;
      }

      // Clean up direct WebSocket connection if it exists
      if (wsRef.current) {
        try {
          wsRef.current.close();
          wsRef.current = null;

          // Clear the window reference
          if (typeof window !== 'undefined') {
            // @ts-ignore - Clear the reference on cleanup
            window.__directWebSocketRef = null;
          }
        } catch (error) {
          console.error('Error closing WebSocket:', error);
        }
      }

      // Clear any intervals
      if (pingIntervalRef.current) {
        clearInterval(pingIntervalRef.current);
        pingIntervalRef.current = null;
      }

      // Reset the initialization flags only if the component is actually unmounting
      // In React StrictMode, components are mounted, unmounted, and remounted
      // during development, so we don't want to reset these flags unless necessary
      // This is managed automatically by React's useEffect cleanup mechanism
    };
  }, [wsService, customUrl, autoReconnect, reconnectInterval, pingInterval, disableServiceWorker]);

  // Handle global message processing - define this before handleReconnection to break circular dependency
  const handleMessage = useCallback((data: WebSocketMessage) => {
    // Handle messages based on their type - TypeScript will properly discriminate
    switch (data.type) {
      case 'notification': {
        // Process notification and show browser notification if needed
        if (typeof window !== 'undefined') {
          import('./notification-helper').then(({ processWebSocketMessageForNotification }) => {
            processWebSocketMessageForNotification(data).catch(error => {
              console.error('Error processing notification:', error);
            });
          });
        }
        wsLog('Received notification:', data.message);
        break;
      }

      case 'server_message': {
        // Server message handling has been moved to useServerMessages hook
        // This just ensures messages are forwarded through the subscription system
        // Events are handled in a dedicated hook now
        wsLog(`Received server message [action: ${data.action}], forwarding to subscribers`);
        break;
      }

      case 'reconnect_required': {
        // Handle server-requested reconnection in direct connection mode
        wsLog('Server requested reconnection:', data.message);

        // Only handle if we're using direct WebSocket connection
        if (wsRef.current) {
          // We'll handle reconnection directly here instead of calling handleReconnection
          // to avoid circular dependencies
          // Clear existing ping interval
          if (pingIntervalRef.current) {
            clearInterval(pingIntervalRef.current);
            pingIntervalRef.current = null;
          }

          // Set reconnecting flag to prevent duplicate reconnection attempts
          isReconnectingRef.current = true;

          // Close the current connection
          try {
            setStatus('RECONNECTING');
            const ws = wsRef.current; // Store reference to close
            wsRef.current = null; // Immediately clear reference to allow new connections
            ws.close(); // Now close the old connection

            // Wait a short time before reconnecting - this will trigger the useEffect
            // that watches status === 'CONNECTING' to initiate a new connection
            setTimeout(() => {
              setStatus('CONNECTING');
            }, 1000);
          } catch (error) {
            console.error('Error during server requested reconnection:', error);
            // Reset reconnection flag on error
            isReconnectingRef.current = false;
          }
        }
        break;
      }

      case 'session_id_required': {
        // This will now be handled by the useWebSocketSession hook
        wsLog('Received session_id_required message:', data.message);
        break;
      }

      case 'ping':
      case 'pong':
      case 'server_ping': {
        // Ping/pong messages are primarily handled directly in the WebSocket handlers

        // Handle entity-specific ping
        if (data.type === 'ping' && 'entityType' in data && 'entityId' in data) {
          wsLog(`Received entity ping for ${data.entityType} ${data.entityId}`);

          // The subscription manager will handle sending the pong response
          // This is processed in subscription-manager.ts
        } else if (data.type === 'server_ping' && wsRef.current && wsRef.current.readyState === WebSocket.OPEN) {
          // For direct connection mode, we should respond to server_ping with a pong
          try {
            wsRef.current.send(JSON.stringify({
              type: 'pong',
              timestamp: new Date().toISOString(),
              reply_to: data.id || null
            }));
          } catch (error) {
            console.error('Error sending pong response to server_ping:', error);
          }
        } else {
          // Regular ping/pong handling
          // wsLog(`Received ${data.type} message`);
        }
        break;
      }

      case 'room_message': {
        // Room messages are handled by subscription callbacks
        // wsLog(`Received room message [action: ${data.action}]`, data);
        break;
      }
      case 'subscription_confirmation': {
        // Room messages are handled by subscription callbacks
        // wsLog(`Received subscription confirmation [action: ${data.action}]`, data);
        break;
      }

      default: {
        // Handle any other message types
        wsLog(`Received unhandled message type: ${data.type}`, data);
        break;
      }
    }
  }, [queryClient]);

  // Track if reconnection is in progress to avoid multiple simultaneous reconnections
  const isReconnectingRef = useRef(false);

  // Define a reconnection handler function that can be called from various places
  const handleReconnection = useCallback(() => {
    // Prevent multiple reconnection attempts (StrictMode protection)
    if (isReconnectingRef.current) {
      wsLog('Reconnection already in progress, ignoring redundant request');
      return;
    }

    // Only proceed if we have a direct WebSocket connection
    if (wsRef.current) {
      // Set reconnection flag
      isReconnectingRef.current = true;

      // Clear existing ping interval
      if (pingIntervalRef.current) {
        clearInterval(pingIntervalRef.current);
        pingIntervalRef.current = null;
      }

      // Close the current connection
      try {
        setStatus('RECONNECTING');
        const ws = wsRef.current; // Store reference to close
        wsRef.current = null; // Immediately clear reference to allow new connections
        ws.close(); // Now close the old connection

        // Wait a short time before reconnecting
        setTimeout(() => {
          const wsUrl = customUrl || getDefaultWebSocketUrl();
          if (!wsUrl) {
            console.error('No WebSocket URL available for reconnection');
            setStatus('ERROR');
            isReconnectingRef.current = false;
            return;
          }

          try {
            wsLog('Creating new WebSocket connection after reconnect request');
            const ws = new WebSocket(wsUrl);
            wsRef.current = ws;

            // Set up the WebSocket event handlers again
            ws.onopen = () => {
              wsLog('Direct WebSocket reconnected');
              setStatus('OPEN');

              // Store WebSocket reference in window object for direct WebSocket mode detection
              if (typeof window !== 'undefined') {
                // @ts-ignore - Store a reference to the active WebSocket for direct mode
                window.__directWebSocketRef = ws;
              }

              // Important: Mark the WebSocketService as initialized when direct WebSocket connects
              const wsService = WebSocketService.getInstance();
              if (!wsService.isReady()) {
                wsLog('Auto-initializing WebSocketService for direct WebSocket mode on reconnect');
                // @ts-ignore - Force set the internal isInitialized flag
                wsService['isInitialized'] = true;

                // Set up event listener for direct messages from WebSocketService
                const directMessageListener = (event: any) => {
                  if (ws.readyState === WebSocket.OPEN && event.data) {
                    try {
                      // The data structure is different from what we expected
                      wsLog('Received direct_message event on reconnect, sending via WebSocket', event.data);

                      // Use the data property directly
                      ws.send(JSON.stringify(event.data));
                    } catch (error) {
                      console.error('Error sending direct message via WebSocket on reconnect:', error);
                    }
                  }
                };

                // Remove any existing listener for cleanup
                if (wsDirectMessageListenerRef.current) {
                  wsService.removeEventListener('direct_message', wsDirectMessageListenerRef.current);
                }

                // Add the new listener
                wsService.addEventListener('direct_message', directMessageListener);
                wsDirectMessageListenerRef.current = directMessageListener;
              }

              // Setup ping interval
              pingIntervalRef.current = setInterval(() => {
                if (ws.readyState === WebSocket.OPEN) {
                  ws.send(JSON.stringify({
                    type: 'ping',
                    timestamp: new Date().toISOString()
                  }));
                }
              }, pingInterval || 600000);

              setIsReady(true);
              // Reset reconnection flag
              isReconnectingRef.current = false;
            };

            // Set up message handler with proper message handling
            // This avoids circular references by not calling handleMessage directly
            ws.onmessage = (event) => {
              try {
                const data = JSON.parse(event.data) as WebSocketMessage;
                setLastMessage(data);

                // Specialized handler for reconnect_required to avoid circular refs
                if (data.type === 'reconnect_required') {
                  wsLog('Server requested reconnection:', data.message);

                  // Clear existing ping interval
                  if (pingIntervalRef.current) {
                    clearInterval(pingIntervalRef.current);
                    pingIntervalRef.current = null;
                  }

                  // Set reconnection flag to prevent duplicate reconnections
                  isReconnectingRef.current = true;

                  // Close current connection
                  wsRef.current = null; // Immediately clear reference to allow new connections
                  ws.close(); // Now close the old connection

                  // Trigger reconnection after a brief delay
                  setTimeout(() => {
                    setStatus('CONNECTING');
                  }, 1000);
                }
                // Handle server_ping directly in the WebSocket handler
                else if (data.type === 'server_ping') {
                  // For direct connection mode, immediately respond with a pong
                  try {
                    ws.send(JSON.stringify({
                      type: 'pong',
                      timestamp: new Date().toISOString(),
                      reply_to: data.id || null
                    }));
                  } catch (error) {
                    console.error('Error sending pong response to server_ping:', error);
                  }

                  // Process through subscription manager for direct WebSocket mode
                  const subscriptionManager = SubscriptionManager.getInstance();
                  subscriptionManager.handleMessage(data);

                  // Also use the standard message handler
                  handleMessage(data);
                }
                // Handle other message types
                else {
                  // Process through subscription manager for direct WebSocket mode
                  // This ensures message subscriptions work even without service worker
                  const subscriptionManager = SubscriptionManager.getInstance();
                  subscriptionManager.handleMessage(data);

                  // For all other messages use the standard handler for backward compatibility
                  handleMessage(data);
                }
              } catch (e) {
                console.error('Error parsing WebSocket message:', e);
              }
            };

            ws.onclose = (event) => {
              wsLog('Direct WebSocket connection closed', event.code, event.reason);
              setStatus('CLOSED');

              // Important: Set wsRef to null if the connection is actually closed
              // This ensures we don't have a reference to a closed socket
              if (wsRef.current && wsRef.current.readyState === WebSocket.CLOSED) {
                wsLog('Setting websocket reference to null since connection is closed');
                wsRef.current = null;
              }

              // Reconnect if needed
              if (autoReconnect) {
                wsLog(`Attempting to reconnect in ${reconnectInterval || 5000}ms`);
                setStatus('RECONNECTING');
                setTimeout(() => {
                  // Only trigger reconnection if not already reconnecting
                  if (!isReconnectingRef.current) {
                    setStatus('CONNECTING');
                  } else {
                    wsLog('Reconnection already in progress, skipping additional reconnect attempt');
                  }
                }, reconnectInterval || 5000);
              } else {
                // Reset reconnection flag when not auto-reconnecting
                isReconnectingRef.current = false;
              }
            };

            ws.onerror = () => {
              console.error('Direct WebSocket error');
              setStatus('ERROR');
              // Reset reconnection flag on error
              isReconnectingRef.current = false;
            };
          } catch (error) {
            console.error('Failed to reconnect direct WebSocket:', error);
            setStatus('ERROR');
            // Reset reconnection flag when reconnection fails
            isReconnectingRef.current = false;
          }
        }, 1000);
      } catch (error) {
        console.error('Error during reconnection:', error);
        // Reset reconnection flag when reconnection fails
        isReconnectingRef.current = false;
      }
    } else {
      // If no websocket exists, reset reconnection flag
      isReconnectingRef.current = false;
    }
  }, [customUrl, pingInterval, autoReconnect, reconnectInterval, handleMessage]);

  // Get default WebSocket URL based on current window location
  const getDefaultWebSocketUrl = (): string => {
    if (typeof window === 'undefined') return '';

    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const host = window.location.host;

    // WebSocket endpoint should be at the app root level, not in the client directory
    const wsPath = '/wss';

    return `${protocol}//${host}${wsPath}`;
  };

  // Send a message through the WebSocket
  const sendMessage = useCallback((type: string, message: string, additionalData: Record<string, any> = {}): boolean => {
    // If we're using the direct WebSocket connection
    if (wsRef.current && wsRef.current.readyState === WebSocket.OPEN) {
      try {
        const data = {
          type,
          message,
          ...additionalData,
          timestamp: new Date().toISOString()
        };
        wsRef.current.send(JSON.stringify(data));
        return true;
      } catch (error) {
        console.error('Error sending message through direct WebSocket:', error);
        return false;
      }
    }
    // Otherwise use the service worker
    else {
      return wsService.sendMessage(type, message, additionalData);
    }
  }, [wsService]);

  // Close the WebSocket connection
  const closeConnection = useCallback(() => {
    // If using direct WebSocket
    if (wsRef.current) {
      try {
        wsRef.current.close();
        wsRef.current = null;

        // Clear ping interval
        if (pingIntervalRef.current) {
          clearInterval(pingIntervalRef.current);
          pingIntervalRef.current = null;
        }

        setStatus('CLOSED');
      } catch (error) {
        console.error('Error closing direct WebSocket:', error);
      }
    }
    // Otherwise use service worker
    else {
      wsService.close();
    }
  }, [wsService]);

  // Reconnect the WebSocket
  const reconnect = useCallback(() => {
    const swSupported = hasServiceWorkerAPI(disableServiceWorker);

    // If using direct WebSocket fallback
    if (wsRef.current || !swSupported) {
      // Use our handleReconnection function for direct WebSocket connections
      handleReconnection();
    }
    // Otherwise use service worker
    else {
      wsService.reconnect({
        url: customUrl || getDefaultWebSocketUrl(),
        reconnectInterval,
        pingInterval,
        disableServiceWorker
      });
    }
  }, [wsService, customUrl, reconnectInterval, pingInterval, handleReconnection, disableServiceWorker]);

  // Handle reconnection when status changes to CONNECTING
  useEffect(() => {
    // If status is CONNECTING and we don't have an active WebSocket reference, connect
    if (status === 'CONNECTING') {
      wsLog('Status changed to CONNECTING, initiating connection');

      // In case there is a stale socket reference, clean it up
      if (wsRef.current) {
        try {
          wsLog('Cleaning up existing websocket reference before reconnecting');
          const ws = wsRef.current;
          wsRef.current = null;
          ws.close();
        } catch (error) {
          wsLog('Error cleaning up existing WebSocket:', error);
        }
      }

      // Initialize a new connection
      const swSupported = hasServiceWorkerAPI(disableServiceWorker);

      // For direct WebSocket mode
      if (!swSupported) {
        // If we're using direct WebSocket, call setupDirectWebSocket which we defined in the mount effect
        const wsUrl = customUrl || getDefaultWebSocketUrl();
        if (wsUrl) {
          try {
            wsLog('Creating new WebSocket connection:', wsUrl);
            const ws = new WebSocket(wsUrl);
            wsRef.current = ws;

            // Set up the WebSocket event handlers (similar to those in setupDirectWebSocket)
            ws.onopen = () => {
              wsLog('Direct WebSocket reconnected via status effect');
              setStatus('OPEN');

              // Store WebSocket reference in window object for direct WebSocket mode detection
              if (typeof window !== 'undefined') {
                // @ts-ignore - Store a reference to the active WebSocket for direct mode
                window.__directWebSocketRef = ws;
              }

              // Important: Mark the WebSocketService as initialized when direct WebSocket connects
              const wsService = WebSocketService.getInstance();
              if (!wsService.isReady()) {
                wsLog('Auto-initializing WebSocketService for direct WebSocket mode via status effect');
                // @ts-ignore - Force set the internal isInitialized flag
                wsService['isInitialized'] = true;

                // Set up event listener for direct messages from WebSocketService
                const directMessageListener = (event: any) => {
                  if (ws.readyState === WebSocket.OPEN && event.data) {
                    try {
                      // The data structure is different from what we expected
                      wsLog('Received direct_message event via status effect, sending via WebSocket', event.data);

                      // Use the data property directly
                      ws.send(JSON.stringify(event.data));
                    } catch (error) {
                      console.error('Error sending direct message via WebSocket via status effect:', error);
                    }
                  }
                };

                // Remove any existing listener for cleanup
                if (wsDirectMessageListenerRef.current) {
                  wsService.removeEventListener('direct_message', wsDirectMessageListenerRef.current);
                }

                // Add the new listener
                wsService.addEventListener('direct_message', directMessageListener);
                wsDirectMessageListenerRef.current = directMessageListener;
              }

              // Setup ping interval
              if (pingIntervalRef.current) {
                clearInterval(pingIntervalRef.current);
              }
              pingIntervalRef.current = setInterval(() => {
                if (ws.readyState === WebSocket.OPEN) {
                  ws.send(JSON.stringify({
                    type: 'ping',
                    timestamp: new Date().toISOString()
                  }));
                }
              }, pingInterval || 600000);

              // Reset reconnection flag
              isReconnectingRef.current = false;
              setIsReady(true);
            };

            // Set up event handlers largely as in the setupDirectWebSocket function
            ws.onmessage = (event) => {
              try {
                const data = JSON.parse(event.data) as WebSocketMessage;
                setLastMessage(data);

                // Handle server_ping directly in the WebSocket handler
                if (data.type === 'server_ping') {
                  // For direct connection mode, immediately respond with a pong
                  try {
                    ws.send(JSON.stringify({
                      type: 'pong',
                      timestamp: new Date().toISOString(),
                      reply_to: data.id || null
                    }));
                  } catch (error) {
                    console.error('Error sending pong response to server_ping:', error);
                  }
                }

                // Process through subscription manager for direct WebSocket mode
                // This ensures message subscriptions work even without service worker
                const subscriptionManager = SubscriptionManager.getInstance();
                subscriptionManager.handleMessage(data);

                // Also use standard handler for backward compatibility
                handleMessage(data);
              } catch (e) {
                console.error('Error parsing WebSocket message:', e);
              }
            };

            ws.onclose = (event) => {
              wsLog('Direct WebSocket connection closed', event.code, event.reason);
              setStatus('CLOSED');

              // Important: Set wsRef to null if the connection is actually closed
              if (wsRef.current && wsRef.current.readyState === WebSocket.CLOSED) {
                wsLog('Setting websocket reference to null since connection is closed');
                wsRef.current = null;
              }

              // Reconnect if needed
              if (autoReconnect) {
                wsLog(`Attempting to reconnect in ${reconnectInterval || 5000}ms`);
                setStatus('RECONNECTING');
                setTimeout(() => {
                  if (!isReconnectingRef.current) {
                    setStatus('CONNECTING');
                  } else {
                    wsLog('Reconnection already in progress, skipping additional reconnect attempt');
                  }
                }, reconnectInterval || 5000);
              } else {
                isReconnectingRef.current = false;
              }
            };

            ws.onerror = () => {
              console.error('Direct WebSocket error');
              setStatus('ERROR');
              isReconnectingRef.current = false;
            };
          } catch (error) {
            console.error('Failed to establish WebSocket connection:', error);
            setStatus('ERROR');
            isReconnectingRef.current = false;
          }
        } else {
          console.error('No WebSocket URL available');
          setStatus('ERROR');
          isReconnectingRef.current = false;
        }
      } else {
        // For service worker mode, use the service to reconnect
        wsService.reconnect({
          url: customUrl || getDefaultWebSocketUrl(),
          reconnectInterval,
          pingInterval,
          disableServiceWorker
        });
      }
    }
  }, [status, customUrl, pingInterval, reconnectInterval, autoReconnect, wsService, handleMessage, disableServiceWorker]);

  const value = {
    status,
    lastMessage,
    sendMessage,
    reconnect,
    closeConnection,
    isReady
  };

  return (
    <WebSocketContext.Provider value={value}>
      {children}
    </WebSocketContext.Provider>
  );
};

export const useWebSocketContext = (): WebSocketContextValue => {
  const context = useContext(WebSocketContext);
  if (context === undefined) {
    throw new Error('useWebSocketContext must be used within a WebSocketProvider');
  }
  return context;
};

// Example status indicator component
export const WebSocketStatusIndicator: React.FC = () => {
  const { status, isReady } = useWebSocketContext();

  const getStatusColor = () => {
    if (!isReady) return 'var(--gray-500)';

    switch(status) {
      case 'OPEN': return 'var(--green-500)';
      case 'CONNECTING':
      case 'RECONNECTING': return 'var(--yellow-500)';
      case 'ERROR':
      case 'CLOSED': return 'var(--red-500)';
      default: return 'var(--gray-500)';
    }
  };

  return (
    <div style={{
      display: 'inline-flex',
      alignItems: 'center',
      gap: '0.5rem',
      padding: '0.25rem 0.5rem',
      borderRadius: '0.25rem',
      fontSize: '0.75rem',
      backgroundColor: 'rgba(0,0,0,0.05)',
    }}>
      <span style={{
        width: '0.5rem',
        height: '0.5rem',
        borderRadius: '50%',
        backgroundColor: getStatusColor(),
      }} />
      <span>WebSocket: {isReady ? status : 'INITIALIZING'}</span>
    </div>
  );
};