// websocket-sw.js - WebSocket Service Worker
'use strict';

// Debug toggle - set to true for verbose logging
const DEBUG_MODE = false;

// Cache name for storing assets
const CACHE_NAME = 'websocket-cache-v1';

// WebSocket connection
let ws = null;
let reconnectInterval = 5000; // 5 seconds
let pingInterval = 600000; // 10 minutes
let pingIntervalId = null;
let reconnectTimeoutId = null;
let isConnecting = false;
let wsUrl = null;

// Client tracking
let activeClients = new Map(); // Map of client IDs to their last activity timestamp
let clientActivityInterval = null;
let primaryClientId = null; // The client ID that initiated the connection

// Log helper
const log = (message, data) => {
  if (!DEBUG_MODE) return;
  
  if (data) {
    console.log(`[Service Worker] ${message}`, data);
  } else {
    console.log(`[Service Worker] ${message}`);
  }
};

// Error logging helper - errors are always logged regardless of debug mode
const logError = (message, error) => {
  console.error(`[Service Worker] ${message}`, error);
};

// Track clients and their activity
function startClientTracking() {
  // Check client activity every 30 seconds
  if (clientActivityInterval) {
    clearInterval(clientActivityInterval);
  }
  
  clientActivityInterval = setInterval(() => {
    const now = Date.now();
    let anyClientsActive = false;
    
    // Check for active clients
    activeClients.forEach((lastActivity, clientId) => {
      // If client hasn't been active for 2 minutes, consider it inactive
      if (now - lastActivity > 120000) {
        activeClients.delete(clientId);
        log(`Client ${clientId} considered inactive and removed`);
      } else {
        anyClientsActive = true;
      }
    });
    
    // If we have no active clients and a connection exists, close it
    if (!anyClientsActive && ws) {
      log('No active clients, closing WebSocket connection');
      try {
        ws.close();
        cleanupConnection();
      } catch (e) {
        logError('Error closing WebSocket due to no active clients:', e);
      }
    }
    
    // Update the primary client if needed
    updatePrimaryClient();
  }, 30000);
}

// Update the primary client (the one responsible for reconnection)
function updatePrimaryClient() {
  if (activeClients.size === 0) {
    primaryClientId = null;
    return;
  }
  
  // If no primary client or the primary client is no longer active, select a new one
  if (!primaryClientId || !activeClients.has(primaryClientId)) {
    // Select the client with the most recent activity
    let mostRecentActivity = 0;
    let mostRecentClientId = null;
    
    activeClients.forEach((lastActivity, clientId) => {
      if (lastActivity > mostRecentActivity) {
        mostRecentActivity = lastActivity;
        mostRecentClientId = clientId;
      }
    });
    
    primaryClientId = mostRecentClientId;
    log(`New primary client selected: ${primaryClientId}`);
  }
}

// Register a client as active
function registerClient(clientId) {
  activeClients.set(clientId, Date.now());
  
  if (!primaryClientId) {
    primaryClientId = clientId;
    log(`Initial primary client set to: ${primaryClientId}`);
  }
  
  // Start tracking if not already started
  if (!clientActivityInterval) {
    startClientTracking();
  }
}

// Update client activity timestamp
function updateClientActivity(clientId) {
  if (activeClients.has(clientId)) {
    activeClients.set(clientId, Date.now());
  } else {
    registerClient(clientId);
  }
}

// Clean up connection resources
function cleanupConnection() {
  ws = null;
  
  if (pingIntervalId) {
    clearInterval(pingIntervalId);
    pingIntervalId = null;
  }
  
  if (reconnectTimeoutId) {
    clearTimeout(reconnectTimeoutId);
    reconnectTimeoutId = null;
  }
  
  isConnecting = false;
}

// Initialize the WebSocket connection
function connectWebSocket() {
  if (isConnecting || !wsUrl) return;
  
  isConnecting = true;
  
  if (ws) {
    try {
      ws.close();
    } catch (e) {
      logError('Error closing existing WebSocket:', e);
    }
  }
  
  log('Connecting to WebSocket:', wsUrl);
  
  try {
    ws = new WebSocket(wsUrl);
    
    // Add event listener for ping events - this is for protocol-level WebSocket ping frames
    // Note: The WebSocket API should automatically respond with pong frames
    ws.addEventListener('ping', (event) => {
      log('Received protocol-level ping');
      // No need to explicitly respond as browser handles this automatically
    });
    
    ws.onopen = (event) => {
      log('WebSocket connection established');
      isConnecting = false;
      
      // Setup ping interval
      if (pingIntervalId) {
        clearInterval(pingIntervalId);
      }
      
      pingIntervalId = setInterval(() => {
        if (ws && ws.readyState === WebSocket.OPEN) {
          ws.send(JSON.stringify({
            type: 'ping',
            timestamp: new Date().toISOString()
          }));
          log('Ping sent');
        }
      }, pingInterval);
      
      // Notify all clients that the connection is open
      broadcastToClients({
        type: 'websocket_status',
        status: 'OPEN',
        timestamp: new Date().toISOString()
      });
    };
    
    ws.onmessage = (event) => {
      log('Message received:', event.data);
      try {
        // First check if this is a binary message (WebSocket protocol ping)
        if (event.data instanceof ArrayBuffer) {
          // This is a protocol-level ping, respond with a pong
          if (ws && ws.readyState === WebSocket.OPEN) {
            // Send pong response (WebSocket API handles this internally)
            log('Received WebSocket protocol ping (binary)');
          }
          return; // Don't broadcast binary messages/protocol pings
        }
        
        // Check for string "ping" message (some servers send this instead of a proper ping frame)
        if (event.data === "ping") {
          if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send("pong");
            log('Received plain text ping, responded with pong');
          }
          return; // Don't broadcast ping/pong messages
        }
        
        const data = JSON.parse(event.data);
        
        // Handle specific message types
        if (data.type === 'server_ping') {
          // Respond to server pings with pong
          ws.send(JSON.stringify({
            type: 'pong',
            timestamp: new Date().toISOString()
          }));
          return; // Don't broadcast pings
        } else if (data.type === 'reconnect_required') {
          // Handle server-requested reconnection
          log('Server requested reconnection:', data.message);
          
          // Clear existing intervals
          if (pingIntervalId) {
            clearInterval(pingIntervalId);
            pingIntervalId = null;
          }
          
          if (reconnectTimeoutId) {
            clearTimeout(reconnectTimeoutId);
          }
          
          // Notify clients of pending reconnection
          broadcastToClients({
            type: 'websocket_message',
            data: data,
            timestamp: new Date().toISOString()
          });
          
          broadcastToClients({
            type: 'websocket_status',
            status: 'RECONNECTING',
            timestamp: new Date().toISOString()
          });
          
          // Close current connection
          ws.close();
          
          // Set a brief timeout before reconnecting
          reconnectTimeoutId = setTimeout(() => {
            connectWebSocket();
          }, 1000);
          
          return; // We've handled this special message
        }
        
        // Broadcast the message to all clients
        broadcastToClients({
          type: 'websocket_message',
          data: data,
          timestamp: new Date().toISOString()
        });
      } catch (e) {
        logError('Error parsing message:', e);
        // Send non-JSON messages too
        broadcastToClients({
          type: 'websocket_message',
          data: { type: 'raw', message: event.data },
          timestamp: new Date().toISOString()
        });
      }
    };
    
    ws.onclose = (event) => {
      log('WebSocket connection closed:', event.code, event.reason);
      isConnecting = false;
      
      // Clear ping interval
      if (pingIntervalId) {
        clearInterval(pingIntervalId);
        pingIntervalId = null;
      }
      
      // Notify clients
      broadcastToClients({
        type: 'websocket_status',
        status: 'CLOSED',
        timestamp: new Date().toISOString()
      });
      
      // Only the primary client should attempt to reconnect
      if (activeClients.size > 0) {
        log(`Attempting to reconnect in ${reconnectInterval / 1000} seconds...`);
        
        if (reconnectTimeoutId) {
          clearTimeout(reconnectTimeoutId);
        }
        
        // Notify clients of reconnection attempt
        broadcastToClients({
          type: 'websocket_status',
          status: 'RECONNECTING',
          timestamp: new Date().toISOString()
        });
        
        reconnectTimeoutId = setTimeout(() => {
          connectWebSocket();
        }, reconnectInterval);
      } else {
        log('No active clients, not attempting to reconnect');
        cleanupConnection();
      }
    };
    
    ws.onerror = (event) => {
      logError('WebSocket error:', event);
      isConnecting = false;
      
      // Notify clients
      broadcastToClients({
        type: 'websocket_status',
        status: 'ERROR',
        timestamp: new Date().toISOString()
      });
    };
  } catch (error) {
    logError('Failed to create WebSocket:', error);
    isConnecting = false;
    
    // Try to reconnect
    if (reconnectTimeoutId) {
      clearTimeout(reconnectTimeoutId);
    }
    
    reconnectTimeoutId = setTimeout(() => {
      connectWebSocket();
    }, reconnectInterval);
  }
}

// Broadcast a message to all connected clients
function broadcastToClients(message) {
  self.clients.matchAll().then(clientList => {
    clientList.forEach(client => {
      try {
        client.postMessage(message);
        // Update client activity when sending messages
        updateClientActivity(client.id);
      } catch (error) {
        logError('Error broadcasting to client:', error);
        // If we can't post a message to a client, consider it inactive
        activeClients.delete(client.id);
      }
    });
  }).catch(error => {
    logError('Error matching clients:', error);
  });
}

// Event listener for when the service worker is installed
self.addEventListener('install', (event) => {
  log('Service Worker installed');
  
  // Force activation without waiting for all clients to close
  event.waitUntil(self.skipWaiting());
});

// Event listener for when the service worker is activated
self.addEventListener('activate', (event) => {
  log('Service Worker activated');
  
  // Claim all clients to ensure the service worker is in control
  event.waitUntil(self.clients.claim());
});

// Handle messages from clients
self.addEventListener('message', (event) => {
  try {
    const message = event.data;
    const clientId = event.source.id;
    
    // Register or update client activity
    updateClientActivity(clientId);
    
    if (!message || !message.type) {
      logError('Invalid message received:', message);
      return;
    }
    
    log(`Message received from client ${clientId}: ${message.type}`);
    
    switch (message.type) {
      case 'connect':
        // Initialize WebSocket URL from client
        if (message.url) {
          // Store configuration values
          wsUrl = message.url;
          if (message.reconnectInterval) {
            reconnectInterval = message.reconnectInterval;
          }
          if (message.pingInterval) {
            pingInterval = message.pingInterval;
          }
          
          // If we already have a working connection, just notify the client and don't reconnect
          if (ws && ws.readyState === WebSocket.OPEN) {
            log(`Client ${clientId} joining existing WebSocket connection`);
            event.source.postMessage({
              type: 'websocket_status',
              status: 'OPEN',
              timestamp: new Date().toISOString()
            });
          } 
          // If we're in the process of connecting, also just notify the client
          else if (isConnecting) {
            log(`Client ${clientId} joining connection in progress`);
            event.source.postMessage({
              type: 'websocket_status',
              status: 'CONNECTING',
              timestamp: new Date().toISOString()
            });
          }
          // Otherwise initiate a new connection
          else {
            log(`Client ${clientId} initiating new WebSocket connection`);
            connectWebSocket();
          }
        }
        break;
        
      case 'disconnect':
        // Remove this client from active clients
        activeClients.delete(clientId);
        
        // If this was the primary client, select a new one
        if (clientId === primaryClientId) {
          updatePrimaryClient();
        }
        
        // Only close if no clients are left
        if (activeClients.size === 0 && ws) {
          // Close the WebSocket connection
          log('Last client disconnected, closing WebSocket');
          try {
            ws.close();
            cleanupConnection();
          } catch (e) {
            logError('Error closing WebSocket:', e);
          }
          
          // Notify the disconnecting client
          event.source.postMessage({
            type: 'websocket_status',
            status: 'CLOSED',
            timestamp: new Date().toISOString()
          });
        } else {
          // Just notify this client it's disconnected but keep the connection for others
          event.source.postMessage({
            type: 'websocket_status',
            status: 'CLOSED',
            timestamp: new Date().toISOString()
          });
          
          log(`Client ${clientId} disconnected, but ${activeClients.size} clients still active`);
        }
        break;
        
      case 'send':
        // Send a message through the WebSocket
        if (ws && ws.readyState === WebSocket.OPEN && message.data) {
          try {
            ws.send(typeof message.data === 'string' ? message.data : JSON.stringify(message.data));
            log('Message sent:', message.data);
          } catch (e) {
            logError('Error sending message:', e);
          }
        } else {
          logError('Cannot send message: WebSocket not open');
          event.source.postMessage({
            type: 'websocket_error',
            error: 'WebSocket not open',
            timestamp: new Date().toISOString()
          });
        }
        break;
        
      case 'ping':
        // Check connection and respond to client
        if (ws) {
          const status = ws.readyState === WebSocket.OPEN ? 'OPEN' : 
                      ws.readyState === WebSocket.CONNECTING ? 'CONNECTING' : 
                      ws.readyState === WebSocket.CLOSING ? 'CLOSING' : 'CLOSED';
                      
          event.source.postMessage({
            type: 'websocket_status',
            status: status,
            timestamp: new Date().toISOString()
          });
        } else {
          event.source.postMessage({
            type: 'websocket_status',
            status: 'CLOSED',
            timestamp: new Date().toISOString()
          });
        }
        break;
        
      case 'test':
        // Special case for testing service worker functionality
        // This message is sent when we just want to verify service worker support
        log(`Service worker test request from client ${clientId}`);
        event.source.postMessage({
          type: 'test_response',
          success: true,
          timestamp: new Date().toISOString()
        });
        
        // If this was just a test, we can remove this client immediately
        if (message.testOnly === true) {
          activeClients.delete(clientId);
          log(`Test-only client ${clientId} removed after test`);
        }
        break;
        
      default:
        logError('Unknown message type:', message.type);
    }
  } catch (error) {
    logError('Error processing message:', error);
  }
});

// Fetch event - handle network requests
self.addEventListener('fetch', (event) => {
  // Simply pass through - no caching needed for this service worker
});