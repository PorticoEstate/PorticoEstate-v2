#!/bin/bash

# Kill any existing WebSocket servers
pkill -f "php /var/www/html/src/WebSocket/server.php" > /dev/null 2>&1
pkill -f "php /var/www/html/src/WebSocket/simple_server.php" > /dev/null 2>&1

# Wait for processes to terminate
sleep 1

echo "Starting simple WebSocket server..."
php /var/www/html/src/WebSocket/simple_server.php > /var/log/apache2/simple_websocket.log 2>&1 &
pid=$!

sleep 2

if ps -p $pid > /dev/null; then
    echo "WebSocket server started successfully with PID: $pid"
    
    # Check if it's listening
    if netstat -an | grep -q 8080; then
        echo "WebSocket server is LISTENING on port 8080!"
    else
        echo "WARNING: WebSocket server is running but NOT listening on port 8080"
    fi
    
    # Show log content
    echo -e "\nLog content:"
    cat /var/www/html/src/WebSocket/websocket_debug.log
else
    echo "Failed to start WebSocket server!"
fi