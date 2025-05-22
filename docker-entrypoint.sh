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

# Set Apache environment variables
NEXTJS_SERVER="${NEXTJS_HOST:-portico_nextjs}:3000"
WEBSOCKET_SERVER="${WEBSOCKET_HOST:-portico_websocket}:8080"

# Export variables for Apache
export NEXTJS_SERVER
export SLIM_SERVER
export WEBSOCKET_SERVER

# Pass environment variables to Apache
echo "SetEnv NEXTJS_SERVER ${NEXTJS_SERVER}" >> /etc/apache2/conf-enabled/environment.conf
echo "SetEnv WEBSOCKET_SERVER ${WEBSOCKET_SERVER}" >> /etc/apache2/conf-enabled/environment.conf

# Make sure Ratchet is installed
if [ ! -d /var/www/html/vendor/cboden/ratchet ]; then
    echo "Installing Ratchet WebSocket library..."
    cd /var/www/html && composer require cboden/ratchet react/socket
fi

# Create log directory for Supervisor
mkdir -p /var/log/supervisor

# Enable required modules for WebSocket
a2enmod proxy proxy_http proxy_wstunnel rewrite

# Start a background process to tail the WebSocket log to stdout
mkdir -p /var/log/apache2
touch /var/log/apache2/websocket.log
(tail -f /var/log/apache2/websocket.log | sed 's/^/WEBSOCKET: /' &)

# Start all services with Supervisor
echo "Starting all services with Supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf