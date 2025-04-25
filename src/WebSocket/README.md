# WebSocket Implementation for PorticoEstate

This directory contains the WebSocket server implementation for PorticoEstate using Ratchet. The WebSocket server enables real-time bidirectional communication between clients and the server.

## Overview

The WebSocket server runs on port 8080 inside the Docker container and is accessible through the Apache proxy at `/wss` endpoint. The implementation supports both Redis pub/sub for message distribution and a fallback file-based notification system.

## Components

- `WebSocketServer.php` - The main WebSocket server implementation using Ratchet
- `server.php` - The entry point script to start the WebSocket server
- `run_websocket.sh` - Shell script to run the WebSocket server in the background
- `client.js` - JavaScript client for connecting to the WebSocket server
- `send_notification.php` - Helper script to send notifications via the WebSocket server (supports Redis or file-based notifications)
- `health_check.sh` - Script to check if the WebSocket server is running
- `view_logs.sh` - Script to view WebSocket logs
- `websocket_cron` - Cron job configuration for WebSocket health checks
- `ws` - Command-line tool for managing the WebSocket server

## How to Use WebSockets in Your Application

### 1. Connect to the WebSocket Server (Client-Side JavaScript)

```javascript
// Create WebSocket connection
const wsUrl = window.location.protocol === 'https:' 
    ? `wss://${window.location.host}/wss` 
    : `ws://${window.location.host}/wss`;
    
const ws = new WebSocket(wsUrl);

// Handle connection open
ws.onopen = function() {
    console.log('WebSocket connection established');
};

// Handle incoming messages
ws.onmessage = function(event) {
    try {
        const data = JSON.parse(event.data);
        console.log('Received message:', data);
        // Handle different message types
        if (data.type === 'notification') {
            console.log('Notification:', data.message);
        }
    } catch (e) {
        console.log('Received non-JSON message:', event.data);
    }
};

// Handle errors
ws.onerror = function(error) {
    console.error('WebSocket error:', error);
};

// Handle connection close
ws.onclose = function() {
    console.log('WebSocket connection closed');
};

// Send a message
function sendMessage(type, message, additionalData = {}) {
    if (ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({
            type: type,
            message: message,
            ...additionalData,
            timestamp: new Date().toISOString()
        }));
    } else {
        console.error('WebSocket is not connected');
    }
}
```

### 2. Send Notifications from the Server-Side (PHP)

You can use the `send_notification.php` script to send notifications to connected clients:

```php
require_once 'src/WebSocket/send_notification.php';

// Send a simple notification
sendWebSocketNotification('New booking created');

// Send a notification with additional data
sendWebSocketNotification('New booking created', [
    'id' => 123,
    'name' => 'Test Booking',
    'user' => 'John Doe'
]);
```

## Message Types

The WebSocket server currently supports the following message types:

1. `chat` - General chat messages
2. `notification` - System notifications

All messages should be JSON formatted with at least the following properties:
- `type` - The message type (e.g., 'chat', 'notification')
- `message` - The main message text

## Testing

You can test the WebSocket functionality by accessing the test page at `/websocket-test`. This page allows you to:

1. Connect to the WebSocket server
2. Send chat messages
3. Send notifications
4. View incoming messages

## Redis Integration

The WebSocket server supports Redis pub/sub for distributing messages. This provides several advantages:
1. More efficient message broadcasting between multiple application instances
2. Reliable message delivery even when clients are temporarily disconnected
3. Improved scalability across multiple servers

### Sending Messages with Redis

You can send messages via Redis using the `send_notification.php` helper:

```php
// Include the helper
require_once 'src/WebSocket/send_notification.php';

// Send a notification
sendWebSocketNotification('New booking created', [
    'id' => 123,
    'type' => 'booking'
]);
```

Alternatively, you can publish directly to Redis:

```php
use Predis\Client as RedisClient;

// Connect to Redis
$redis = new RedisClient([
    'scheme' => 'tcp',
    'host' => getenv('REDIS_HOST') ?: 'redis',
    'port' => getenv('REDIS_PORT') ?: 6379
]);

// Create notification payload
$notification = [
    'type' => 'notification',
    'message' => 'New booking created',
    'data' => ['id' => 123],
    'timestamp' => date('c')
];

// Publish to the 'notifications' channel
$redis->publish('notifications', json_encode($notification));
```

### Fallback Mechanism

If Redis is unavailable, the system will automatically fall back to a file-based notification system, ensuring reliable message delivery in all scenarios.

## Troubleshooting

If you encounter any issues with the WebSocket server, check the following:

1. Verify the WebSocket server is running: `docker exec portico_api ps aux | grep server.php`
2. Check the WebSocket logs: `docker exec portico_api cat /var/log/apache2/websocket.log`
3. Verify the WebSocket port (8080) is properly exposed in the Docker configuration
4. Check Redis connection: `docker exec portico_api redis-cli -h redis ping`
5. Check the Redis logs: `docker logs portico_redis`