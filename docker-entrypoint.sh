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

# Check if composer dependencies need to be updated (development scenario with mounted volumes)
if [ -f /var/www/html/composer.json ]; then
    if [ ! -d /var/www/html/vendor ] || [ ! -f /var/www/html/vendor/autoload.php ] || [ /var/www/html/composer.json -nt /var/www/html/vendor/composer/installed.json ]; then
        echo "Updating Composer dependencies..."
        cd /var/www/html && XDEBUG_MODE=off composer install --no-dev --optimize-autoloader
    else
        echo "Composer dependencies are up to date"
    fi
fi

# Check if npm dependencies need to be installed or updated.
# The Docker image stores a checksum of package-lock.json at /tmp/.package-lock-hash
# during build. We compare it against a checksum saved inside the node_modules volume
# to detect when the volume is stale after an image rebuild.
if [ -f /var/www/html/package.json ]; then
    NPM_NEEDS_INSTALL=false
    IMAGE_HASH_FILE="/tmp/.package-lock-hash"
    VOLUME_HASH_FILE="/var/www/html/node_modules/.package-lock-hash"

    if [ ! -d /var/www/html/node_modules ]; then
        NPM_NEEDS_INSTALL=true
        echo "node_modules missing, npm install required"
    elif [ -f "$IMAGE_HASH_FILE" ]; then
        IMAGE_HASH=$(cat "$IMAGE_HASH_FILE")
        VOLUME_HASH=""
        if [ -f "$VOLUME_HASH_FILE" ]; then
            VOLUME_HASH=$(cat "$VOLUME_HASH_FILE")
        fi
        if [ "$IMAGE_HASH" != "$VOLUME_HASH" ]; then
            NPM_NEEDS_INSTALL=true
            echo "package-lock.json checksum changed (image vs volume), npm install required"
        fi
    fi

    if [ "$NPM_NEEDS_INSTALL" = "true" ]; then
        if ! command -v npm > /dev/null 2>&1; then
            echo "npm not found, installing Node.js and npm..."
            apt-get update && apt-get install -y nodejs npm && rm -rf /var/lib/apt/lists/*
        fi
        echo "Installing npm dependencies..."
        cd /var/www/html && npm ci
        # Save the checksum into the volume so subsequent starts skip the install
        if [ -f "$IMAGE_HASH_FILE" ]; then
            cp "$IMAGE_HASH_FILE" "$VOLUME_HASH_FILE"
        fi
    else
        echo "npm dependencies are up to date"
    fi
fi

# Create log directory for Supervisor
mkdir -p /var/log/supervisor

# Clean up stale Apache2 PID files to prevent startup issues
rm -f /var/run/apache2/apache2.pid

# Enable required modules for WebSocket
a2enmod proxy proxy_http proxy_wstunnel rewrite

# Start a background process to tail the WebSocket log to stdout
mkdir -p /var/log/apache2
touch /var/log/apache2/websocket.log
(tail -f /var/log/apache2/websocket.log | sed 's/^/WEBSOCKET: /' &)

# Start all services with Supervisor
echo "Starting all services with Supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf