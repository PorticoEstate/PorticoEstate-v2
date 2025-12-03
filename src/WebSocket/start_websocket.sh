#!/bin/bash

# Direct script to start the WebSocket server in foreground mode for testing
echo "Starting WebSocket server in foreground mode..."
echo "Press Ctrl+C to stop"
echo ""

# Kill any existing WebSocket server instances
pkill -f "php /var/www/html/src/WebSocket/server.php" >/dev/null 2>&1

# Start WebSocket server in foreground
php /var/www/html/src/WebSocket/server.php