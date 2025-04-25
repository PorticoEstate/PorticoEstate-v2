// websocket-sw.js - WebSocket Service Worker
'use strict';

// Cache name for storing assets
const CACHE_NAME = 'websocket-cache-v1';

// WebSocket connection
let ws = null;
let reconnectInterval = 5000; // 5 seconds
let pingInterval = 30000; // 30 seconds
let pingIntervalId = null;
let reconnectTimeoutId = null;
let isConnecting = false;
let wsUrl = null;

// Log helper
const log = (message, data) => {
  if (data) {
    console.log(`[Service Worker] ${message}`, data);
  } else {
    console.log(`[Service Worker] ${message}`);
  }
};

// Error logging helper
const logError = (message, error) => {
  console.error(`[Service Worker] ${message}`, error);
};

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
      
      // Reconnect
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
      } catch (error) {
        logError('Error broadcasting to client:', error);
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
    
    if (!message || !message.type) {
      logError('Invalid message received:', message);
      return;
    }
    
    log('Message received from client:', message.type);
    
    switch (message.type) {
      case 'connect':
        // Initialize WebSocket URL from client
        if (message.url) {
          wsUrl = message.url;
          if (message.reconnectInterval) {
            reconnectInterval = message.reconnectInterval;
          }
          if (message.pingInterval) {
            pingInterval = message.pingInterval;
          }
          connectWebSocket();
        }
        break;
        
      case 'disconnect':
        // Close the WebSocket connection
        if (ws) {
          try {
            ws.close();
          } catch (e) {
            logError('Error closing WebSocket:', e);
          }
          ws = null;
        }
        
        // Clear intervals and timeouts
        if (pingIntervalId) {
          clearInterval(pingIntervalId);
          pingIntervalId = null;
        }
        
        if (reconnectTimeoutId) {
          clearTimeout(reconnectTimeoutId);
          reconnectTimeoutId = null;
        }
        
        broadcastToClients({
          type: 'websocket_status',
          status: 'CLOSED',
          timestamp: new Date().toISOString()
        });
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
          broadcastToClients({
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