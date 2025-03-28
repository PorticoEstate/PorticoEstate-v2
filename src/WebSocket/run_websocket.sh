#!/bin/bash

# Simple script to run the WebSocket server
# This script is intended to be called by docker-entrypoint.sh or health_check.sh

# Make sure PHP dependencies are available
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "ERROR: Composer dependencies not found. Please run composer install."
    exit 1
fi

# Check if Ratchet is installed
if [ ! -d /var/www/html/vendor/cboden/ratchet ]; then
    echo "ERROR: Ratchet WebSocket library not found. Please run composer require cboden/ratchet"
    exit 1
fi

# Run the WebSocket server in the background
php /var/www/html/src/WebSocket/server.php > /var/log/apache2/websocket.log 2>&1 &
pid=$!

# Verify the process is running
if ps -p $pid > /dev/null; then
    echo "WebSocket server started with PID: $pid"
    echo "WebSocket server log: /var/log/apache2/websocket.log"
    # Give it a moment to start up
    sleep 2
    # Show the first few lines of the log
    head -n 10 /var/log/apache2/websocket.log
else
    echo "Failed to start WebSocket server!"
    echo "Check logs for details: /var/log/apache2/websocket.log"
    cat /var/log/apache2/websocket.log
fi