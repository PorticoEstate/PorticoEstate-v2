#!/bin/sh
set -e

# Install WebSocket health check cron job if exists
if [ -f /var/www/html/src/WebSocket/websocket_cron ]; then
    echo "Installing WebSocket health check cron job"
    # Ensure file has newline at end
    sed -i -e '$a\' /var/www/html/src/WebSocket/websocket_cron
    cp /var/www/html/src/WebSocket/websocket_cron /etc/cron.d/websocket_cron
    chmod 0644 /etc/cron.d/websocket_cron
    # Use direct cron.d installation instead of crontab command
    echo "Cron job installed in /etc/cron.d/"
fi

# Create symlink for WebSocket manager tool
if [ -f /var/www/html/src/WebSocket/ws ]; then
    echo "Installing WebSocket manager tool"
    ln -sf /var/www/html/src/WebSocket/ws /usr/local/bin/ws
    chmod +x /usr/local/bin/ws
fi

# start cron
service cron start

# Set Apache environment variables
NEXTJS_SERVER="${NEXTJS_HOST:-nextjs}:3000"

# Export variables for Apache
export NEXTJS_SERVER
export SLIM_SERVER

# Pass environment variables to Apache
echo "SetEnv NEXTJS_SERVER ${NEXTJS_SERVER}" >> /etc/apache2/conf-enabled/environment.conf

# Start WebSocket server with delay and retries
if [ -f /var/www/html/src/WebSocket/run_websocket.sh ]; then
    echo "*** Setting up WebSocket server ***"
    
    # Wait for other services to initialize
    echo "Waiting for system to stabilize before starting WebSocket server..."
    sleep 10
    
    # Make sure Ratchet is installed
    if [ ! -d /var/www/html/vendor/cboden/ratchet ]; then
        echo "Installing Ratchet WebSocket library..."
        cd /var/www/html && composer require cboden/ratchet react/socket
    fi
    
    # Define a function to check if port 8080 is in use
    port_in_use() {
        netstat -tlpn | grep 8080 > /dev/null
        return $?
    }
    
    # Try to start WebSocket server (with multiple attempts)
    MAX_ATTEMPTS=3
    for attempt in $(seq 1 $MAX_ATTEMPTS); do
        echo "==============================================="
        echo "Starting WebSocket server (attempt $attempt of $MAX_ATTEMPTS)..."
        
        # Kill any existing WebSocket server processes
        echo "Terminating any existing WebSocket processes..."
        pkill -f "php /var/www/html/src/WebSocket/server.php" > /dev/null 2>&1 || true
        
        # Wait for processes to terminate
        sleep 2
        
        # Check for processes that might be using port 8080
        echo "Checking if port 8080 is already in use..."
        if port_in_use; then
            echo "⚠️ WARNING: Port 8080 is already in use by another process!"
            netstat -tlpn | grep 8080
            echo "Trying to kill processes using port 8080..."
            fuser -k 8080/tcp > /dev/null 2>&1 || true
            sleep 2
        else
            echo "✅ Port 8080 is available"
        fi
        
        # Run the WebSocket server
        echo "Launching WebSocket server..."
        /var/www/html/src/WebSocket/run_websocket.sh
        
        # Verify it's running and listening
        echo "Verifying WebSocket server status..."
        sleep 5  # Give it more time to initialize
        
        if pgrep -f "php /var/www/html/src/WebSocket/server.php" > /dev/null; then
            echo "✅ WebSocket server process is running (PID: $(pgrep -f "php /var/www/html/src/WebSocket/server.php"))"
            
            if port_in_use; then
                echo "✅ SUCCESS: WebSocket server is listening on port 8080"
                # Create a status file to indicate successful start
                echo "$(date)" > /tmp/websocket_started_successfully
                break  # Success! Exit the loop
            else
                echo "⚠️ WARNING: WebSocket server is running but NOT listening on port 8080"
                echo "Checking WebSocket server log for errors..."
                if [ -f /var/log/apache2/websocket.log ]; then
                    tail -n 20 /var/log/apache2/websocket.log
                else
                    echo "No log file found at /var/log/apache2/websocket.log"
                fi
                
                if [ $attempt -lt $MAX_ATTEMPTS ]; then
                    echo "Retrying in 5 seconds..."
                    sleep 5
                else
                    echo "❌ FAILED: Maximum attempts reached. Will rely on health check for recovery."
                fi
            fi
        else
            echo "❌ ERROR: WebSocket server process failed to start"
            if [ -f /var/log/apache2/websocket.log ]; then
                echo "Last lines from log:"
                tail -n 20 /var/log/apache2/websocket.log
            fi
            
            if [ $attempt -lt $MAX_ATTEMPTS ]; then
                echo "Retrying in 5 seconds..."
                sleep 5
            else
                echo "❌ FAILED: Maximum attempts reached. Will rely on health check for recovery."
            fi
        fi
        echo "==============================================="
    done
    
    # Set up recovery cron job if not already added
    if [ ! -f /etc/cron.d/websocket_recovery ]; then
        echo "Setting up WebSocket recovery cron job..."
        echo "*/5 * * * * root /var/www/html/src/WebSocket/health_check.sh >> /var/log/apache2/websocket_health.log 2>&1" > /etc/cron.d/websocket_recovery
        chmod 0644 /etc/cron.d/websocket_recovery
    fi
fi

# Start PHP-FPM
php-fpm &

# Enable required modules for WebSocket
a2enmod proxy proxy_http proxy_wstunnel rewrite

# Start Apache
exec apache2ctl -D FOREGROUND