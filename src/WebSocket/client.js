/**
 * WebSocket Client for PorticoEstate
 * 
 * This client supports multiple connection strategies:
 * 1. Direct connection to WebSocket server for local development
 * 2. Proxy through Apache/Nginx for production
 * 3. Custom domain with path-based routing
 */
const createWebSocketClient = (customUrl = null) => {
    // Determine the WebSocket URL based on the environment
    let wsUrl;
    
    if (customUrl) {
        // Use custom URL if provided
        wsUrl = customUrl;
    } else {
        // Auto-detect environment
        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const host = window.location.host;
        const hostname = window.location.hostname;
        
        // Always use the /wss path, no direct port connection
        // This works with both custom domains (.test) and production
        wsUrl = `${protocol}//${host}/wss`;
    }

    console.log('Connecting to WebSocket server at:', wsUrl);
    
    const ws = new WebSocket(wsUrl);
    
    ws.onopen = function() {
        console.log('WebSocket connection established');
        // Update UI elements if they exist
        if (document.getElementById('connection-status')) {
            document.getElementById('connection-status').textContent = 'Connected';
            document.getElementById('connection-status').style.color = 'green';
        }
        
        // Start sending ping messages every 30 seconds to keep the connection alive
        setInterval(() => {
            if (ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({
                    type: 'ping',
                    timestamp: new Date().toISOString()
                }));
                console.log('Ping sent');
            }
        }, 30000);
    };
    
    ws.onmessage = function(event) {
        console.log('Message received:', event.data);
        try {
            const data = JSON.parse(event.data);
            // Handle different message types
            if (data.type === 'notification') {
                console.log('Notification:', data.message);
                // Trigger custom event for notification
                window.dispatchEvent(new CustomEvent('portico:notification', { detail: data }));
            } else if (data.type === 'chat') {
                console.log('Chat message:', data.message);
                // Trigger custom event for chat message
                window.dispatchEvent(new CustomEvent('portico:chat', { detail: data }));
            } else if (data.type === 'pong') {
                console.log('Received pong from server');
                // No need to do anything with pongs
            } else if (data.type === 'server_ping') {
                console.log('Received server ping');
                // Send a pong response back to the server
                ws.send(JSON.stringify({
                    type: 'pong',
                    timestamp: new Date().toISOString()
                }));
            } else {
                // Generic message event
                window.dispatchEvent(new CustomEvent('portico:message', { detail: data }));
            }
        } catch (e) {
            console.log('Received non-JSON message:', event.data);
            // Trigger custom event for raw message
            window.dispatchEvent(new CustomEvent('portico:raw', { detail: event.data }));
        }
    };
    
    ws.onclose = function(event) {
        console.log('WebSocket connection closed', event.code, event.reason);
        // Update UI elements if they exist
        if (document.getElementById('connection-status')) {
            document.getElementById('connection-status').textContent = 'Disconnected - Reconnecting...';
            document.getElementById('connection-status').style.color = 'orange';
        }
        
        // Try to reconnect after 5 seconds
        console.log('Attempting to reconnect in 5 seconds...');
        setTimeout(() => {
            console.log('Reconnecting...');
            
            // Trigger a custom event so the page knows we're reconnecting
            window.dispatchEvent(new CustomEvent('portico:reconnecting'));
            
            // Create a new connection
            const newClient = createWebSocketClient(wsUrl);
            
            // Replace the current client with the new one
            if (window.porticoWsClient) {
                window.porticoWsClient = newClient;
            }
        }, 5000);
    };
    
    ws.onerror = function(error) {
        console.error('WebSocket error:', error);
        // Update UI elements if they exist
        if (document.getElementById('connection-status')) {
            document.getElementById('connection-status').textContent = 'Error: ' + (error.message || 'Unknown error');
            document.getElementById('connection-status').style.color = 'red';
        }
    };
    
    // Create the client object
    const client = {
        sendMessage: function(type, message, additionalData = {}) {
            if (ws.readyState === WebSocket.OPEN) {
                const data = {
                    type: type,
                    message: message,
                    ...additionalData,
                    timestamp: new Date().toISOString()
                };
                
                ws.send(JSON.stringify(data));
                console.log('Message sent:', data);
                
                // Trigger custom event for sent message
                window.dispatchEvent(new CustomEvent('portico:sent', { detail: data }));
                
                return true;
            } else {
                console.error('WebSocket is not connected');
                return false;
            }
        },
        close: function() {
            ws.close();
        },
        getStatus: function() {
            const states = ['CONNECTING', 'OPEN', 'CLOSING', 'CLOSED'];
            return states[ws.readyState] || 'UNKNOWN';
        }
    };
    
    // Store the client in a global variable for reconnection
    window.porticoWsClient = client;
    
    return client;
};

// Usage examples:
// 
// 1. Auto-detect environment (recommended):
// const client = createWebSocketClient();
// 
// 2. Direct connection to WebSocket server:
// const client = createWebSocketClient('ws://example.test:8080');
// 
// 3. Connect through nginx proxy:
// const client = createWebSocketClient('ws://example.test/wss');
// 
// 4. Send messages:
// client.sendMessage('chat', 'Hello, world!');
// client.sendMessage('notification', 'Alert!', { priority: 'high' });