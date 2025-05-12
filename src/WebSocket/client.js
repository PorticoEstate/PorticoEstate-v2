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

        // Try to auto-detect userId from the page if available
        // This helps with session validation
        setTimeout(() => {
            if (ws.readyState === WebSocket.OPEN) {
                // Try to get the user ID from a hidden field, globally defined variable, or data attribute
                const userId = getUserIdFromPage();
                if (userId) {
                    console.log('Auto-detected user ID:', userId);

                    ws.send(JSON.stringify({
                        type: 'update_user_info',
                        userId: userId,
                        timestamp: new Date().toISOString()
                    }));
                }
            }
        }, 1000); // Slight delay to ensure connection is fully established
    };

    // Helper function to try to get userId from the page
    function getUserIdFromPage() {
        // Try different methods to get the user ID

        // Method 1: Look for a hidden input field with a specific ID
        const userIdInput = document.getElementById('userId') ||
                           document.querySelector('input[name="userId"]') ||
                           document.querySelector('[data-user-id]');
        if (userIdInput) {
            return userIdInput.value || userIdInput.getAttribute('data-user-id');
        }

        // Method 2: Check for a global variable
        if (window.userId !== undefined) {
            return window.userId;
        }

        // Method 3: Look for data attributes on body or html element
        const bodyUserId = document.body.getAttribute('data-user-id');
        if (bodyUserId) {
            return bodyUserId;
        }

        // Method 4: Look for data in localStorage (if the app uses it)
        try {
            const userData = localStorage.getItem('user_data');
            if (userData) {
                const parsed = JSON.parse(userData);
                if (parsed && parsed.id) {
                    return parsed.id;
                }
            }
        } catch (e) {
            console.log('Could not parse user data from localStorage');
        }

        return null;
    }
    
    ws.onmessage = function(event) {
        console.log('Message received:', event.data);
        try {
            const data = JSON.parse(event.data);

            // Handle different message types
            switch(data.type) {
                case 'notification':
                    console.log('Notification:', data.message);
                    // Trigger custom event for notification
                    window.dispatchEvent(new CustomEvent('portico:notification', { detail: data }));
                    break;

                case 'chat':
                    console.log('Chat message:', data.message);
                    // Trigger custom event for chat message
                    window.dispatchEvent(new CustomEvent('portico:chat', { detail: data }));
                    break;

                case 'pong':
                    console.log('Received pong from server');
                    // No need to do anything with pongs
                    break;

                case 'server_ping':
                    console.log('Received server ping');
                    // Send a pong response back to the server
                    ws.send(JSON.stringify({
                        type: 'pong',
                        timestamp: new Date().toISOString()
                    }));
                    break;

                case 'partial_applications_response':
                    console.log('Received partial applications response:', data.data);

                    // Check if we need to provide user info
                    if (data.data && data.data.requiresUserInfo) {
                        console.warn('User ID required for partial applications. Use client.updateUserInfo(userId) to set it.');
                        // Trigger a special event for requiring user info
                        window.dispatchEvent(new CustomEvent('portico:requires_user_info', {
                            detail: { requestType: 'partial_applications' }
                        }));
                    }

                    // Check if this is a server push update
                    const isServerPush = data.data && data.data.source === 'server_push';
                    if (isServerPush) {
                        console.log('Received server-initiated partial applications update');
                    }

                    // Trigger specific event for partial applications data
                    window.dispatchEvent(new CustomEvent('portico:partial_applications', {
                        detail: {
                            ...data.data,
                            isServerPush: isServerPush
                        }
                    }));

                    // Also trigger generic message event (for promise resolution)
                    // We only do this for client-initiated requests to avoid resolving
                    // promises that weren't made
                    if (!isServerPush) {
                        window.dispatchEvent(new CustomEvent('portico:message', { detail: data }));
                    }
                    break;

                case 'user_info_update_confirmation':
                    console.log('User info update confirmation:', data.message);
                    // Trigger specific event for user info update
                    window.dispatchEvent(new CustomEvent('portico:user_info_updated', {
                        detail: { userId: data.userId, success: data.success }
                    }));
                    break;

                case 'connection_success':
                    console.log('Connection success:', data.message);
                    // Trigger connected event
                    window.dispatchEvent(new CustomEvent('portico:connected', { detail: data }));
                    break;

                default:
                    // Generic message event for unhandled types
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

        // Update user information on the WebSocket server
        updateUserInfo: function(userId) {
            if (ws.readyState === WebSocket.OPEN) {
                const data = {
                    type: 'update_user_info',
                    userId: userId,
                    timestamp: new Date().toISOString()
                };

                ws.send(JSON.stringify(data));
                console.log('User info update sent:', data);

                return true;
            } else {
                console.error('WebSocket is not connected');
                return false;
            }
        },

        // Get partial applications for the current user
        getPartialApplications: function() {
            if (ws.readyState === WebSocket.OPEN) {
                const data = {
                    type: 'get_partial_applications',
                    timestamp: new Date().toISOString()
                };

                ws.send(JSON.stringify(data));
                console.log('Partial applications request sent');

                // Register a one-time event listener for the response
                const responsePromise = new Promise((resolve, reject) => {
                    const handler = (event) => {
                        if (event.detail.type === 'partial_applications_response') {
                            window.removeEventListener('portico:message', handler);
                            resolve(event.detail.data);
                        }
                    };

                    // Set a timeout to reject the promise after 10 seconds
                    const timeout = setTimeout(() => {
                        window.removeEventListener('portico:message', handler);
                        reject(new Error('Timeout waiting for partial applications response'));
                    }, 10000);

                    window.addEventListener('portico:message', handler);
                });

                return responsePromise;
            } else {
                console.error('WebSocket is not connected');
                return Promise.reject(new Error('WebSocket is not connected'));
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
//
// 5. Set user ID after connection (required for accessing user-specific data):
// client.updateUserInfo(123); // Replace with actual user ID
//
// 6. Get partial applications for the current user:
// client.getPartialApplications()
//   .then(data => {
//     if (!data.error) {
//       console.log('Partial applications:', data.applications);
//       // Process applications here
//     } else {
//       console.error('Error fetching partial applications:', data.message);
//     }
//   })
//   .catch(err => console.error('Error:', err));
//
// 7. Listen for partial applications events:
// window.addEventListener('portico:partial_applications', event => {
//   const data = event.detail;
//   if (!data.error) {
//     console.log('Received partial applications:', data.applications);
//     // Update UI with applications
//   }
// });
//
// 8. Advanced: Set up connection and user authentication in sequence:
// const setupWebSocketWithAuth = async (userId) => {
//   const client = createWebSocketClient();
//
//   // Wait for connection to be established
//   await new Promise(resolve => {
//     const checkConnection = () => {
//       if (client.getStatus() === 'OPEN') {
//         resolve();
//       } else {
//         setTimeout(checkConnection, 100);
//       }
//     };
//     checkConnection();
//   });
//
//   // Set user ID
//   client.updateUserInfo(userId);
//
//   return client;
// };
//
// // Usage:
// setupWebSocketWithAuth(123).then(client => {
//   // Client is now connected and authenticated
//   client.getPartialApplications().then(data => console.log(data));
// });