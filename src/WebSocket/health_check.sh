#!/bin/bash

# WebSocket Server Health Check Script
# This script checks if the WebSocket server is running and restarts it if needed

# Log file for health check
LOG_FILE="/var/log/apache2/websocket_health.log"

# Function to log messages
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> $LOG_FILE
    echo "$1"
}

# Check if WebSocket server is running
if pgrep -f "php /var/www/html/src/WebSocket/server.php" > /dev/null; then
    # Check if it's actually listening on port 8080
    if netstat -tlpn | grep 8080 > /dev/null; then
        log "WebSocket server is running and listening on port 8080"
        
        # Optionally check if server can accept connections
        # by trying to establish a socket connection
        if command -v nc >/dev/null 2>&1; then
            if nc -z localhost 8080; then
                log "Port 8080 is accepting connections"
            else
                log "WARNING: Port 8080 is not accepting connections, restarting server..."
                # Kill and restart
                pkill -f "php /var/www/html/src/WebSocket/server.php" > /dev/null 2>&1
                sleep 1
                /var/www/html/src/WebSocket/run_websocket.sh
            fi
        fi
        
        exit 0
    else
        log "WebSocket server process is running but NOT listening on port 8080, restarting..."
        
        # Kill and restart
        pkill -f "php /var/www/html/src/WebSocket/server.php" > /dev/null 2>&1
        sleep 1
        /var/www/html/src/WebSocket/run_websocket.sh
    fi
else
    log "WebSocket server is not running, restarting..."
    
    # Kill any zombie processes
    pkill -f "php /var/www/html/src/WebSocket/server.php" > /dev/null 2>&1
    
    # Wait a moment
    sleep 1
    
    # Start WebSocket server
    /var/www/html/src/WebSocket/run_websocket.sh
    
    # Check if it started successfully
    sleep 3
    if pgrep -f "php /var/www/html/src/WebSocket/server.php" > /dev/null; then
        # Also check if it's listening
        if netstat -tlpn | grep 8080 > /dev/null; then
            log "WebSocket server restarted successfully and is listening on port 8080"
            exit 0
        else
            log "WebSocket server restarted but is NOT listening on port 8080"
            # Try one more restart
            pkill -f "php /var/www/html/src/WebSocket/server.php" > /dev/null 2>&1
            sleep 1
            /var/www/html/src/WebSocket/run_websocket.sh
            sleep 3
            if netstat -tlpn | grep 8080 > /dev/null; then
                log "WebSocket server successfully listening on port 8080 after second restart"
                exit 0
            else
                log "WebSocket server still not listening on port 8080 after second restart"
                exit 1
            fi
        fi
    else
        log "Failed to restart WebSocket server"
        exit 1
    fi
fi