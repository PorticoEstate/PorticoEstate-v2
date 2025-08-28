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
# Use full path to PHP to avoid "command not found" errors
# Explicitly disable xdebug to prevent debugger pauses
# Use 'tee' to send output to both the log file and stdout
/usr/local/bin/php -dxdebug.mode=off -dxdebug.start_with_request=no /var/www/html/src/WebSocket/server.php 2>&1 | tee /var/log/apache2/websocket.log &
pid=$!

# Verify the process is running
if ps -p $pid > /dev/null; then
    echo "WebSocket server started with PID: $pid"
    echo "WebSocket logs are being sent to both stdout and /var/log/apache2/websocket.log"
    # Give it a moment to start up
    sleep 2
else
    echo "Failed to start WebSocket server!"
    echo "Check logs for details: /var/log/apache2/websocket.log"
    cat /var/log/apache2/websocket.log
fi