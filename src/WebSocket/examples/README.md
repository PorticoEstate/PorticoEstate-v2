# WebSocket Partial Applications Update

This feature allows the Slim server to notify WebSocket clients about changes to partial applications without requiring a page refresh.

## How It Works

1. The WebSocket server has a new message type: `update_partial_applications`
2. When the Slim server wants to update partial applications for a session, it sends a message to Redis
3. The WebSocket server receives this message, fetches the partial applications, and sends them to clients
4. Connected clients receive an update event with the latest partial applications data

## Server-Side Integration

To trigger updates when partial applications change:

```php
// In your controller where you create/update/delete partial applications
use App\modules\bookingfrontend\helpers\WebSocketHelper;

// After modifying any partial application:
$sessionId = session_id(); // Get the user's session ID
WebSocketHelper::triggerPartialApplicationsUpdate($sessionId);
```

## Client-Side Integration

The client.js has been updated to handle server-initiated updates:

```javascript
// Initialize the WebSocket client
const client = createWebSocketClient();

// Listen for partial applications updates
window.addEventListener('portico:partial_applications', event => {
  const data = event.detail;
  
  // Check if this is a server-initiated update
  if (data.isServerPush) {
    console.log('Received server-initiated update with', data.count, 'applications');
  }
  
  // Update UI with the applications
  updateApplicationsUI(data.applications);
});

// You can also manually request applications
client.getPartialApplications()
  .then(data => {
    console.log('Manually requested applications:', data);
  });
```

## Technical Details

- The WebSocket server uses the session ID to find the corresponding session room
- It fetches the applications from the database using `DatabaseService.getPartialApplicationsBySessionId()`
- The message is marked with `source: 'server_push'` to differentiate from client-requested updates
- Redis channel `session_messages` is used for these updates