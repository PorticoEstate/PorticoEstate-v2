#!/bin/bash

# Test script for WebSocket server in development mode

# Check if the WebSocket server is running
echo "Checking if WebSocket server is running..."
docker exec portico_api ps aux | grep server.php

# View WebSocket server logs
echo ""
echo "WebSocket server logs:"
docker exec portico_api cat /var/log/apache2/websocket.log

# Send a test notification
echo ""
echo "Sending a test notification..."

docker exec portico_api php -r '
require_once "/var/www/html/src/WebSocket/send_notification.php"; 
sendWebSocketNotification("Test notification from script", ["time" => date("Y-m-d H:i:s")]);
echo "Notification sent\n";
'

echo ""
echo "Test complete. Check the WebSocket test page at http://localhost:8088/websocket-test"