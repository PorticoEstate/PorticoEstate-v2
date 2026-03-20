#!/bin/bash

git config --global --add safe.directory /var/www/html

# Log entrypoint output to a file for debugging
ENTRYPOINT_LOG="/tmp/entrypoint.log"
echo "=== Entrypoint started at $(date -Iseconds) ===" > "$ENTRYPOINT_LOG" 2>&1 || echo "FAILED TO WRITE LOG" >&2
log() { echo "$1"; echo "$1" >> "$ENTRYPOINT_LOG" 2>/dev/null || true; }
log "Shell: $SHELL, Bash: $BASH_VERSION, PID: $$"
log "pwd: $(pwd), whoami: $(whoami)"

# Install WebSocket health check cron job if exists
if [ -f /var/www/html/src/WebSocket/websocket_cron ]; then
    log "Installing WebSocket health check cron job"
    # Ensure file has newline at end
    sed -i -e '$a\' /var/www/html/src/WebSocket/websocket_cron
    cp /var/www/html/src/WebSocket/websocket_cron /etc/cron.d/websocket_cron
    chmod 0644 /etc/cron.d/websocket_cron
    log "Cron job installed in /etc/cron.d/"
fi

# Create symlink for WebSocket manager tool
if [ -f /var/www/html/src/WebSocket/ws ]; then
    log "Installing WebSocket manager tool"
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
        log "Updating Composer dependencies..."
        cd /var/www/html && XDEBUG_MODE=off composer install --no-dev --optimize-autoloader
    else
        log "Composer dependencies are up to date"
    fi
fi

# Sync node_modules from image backup into the Docker volume if stale.
# The image stores a pre-built copy at /opt/node_modules_build with a
# checksum at /opt/.package-lock-hash. We compare against the volume's copy.
IMAGE_HASH_FILE="/opt/.package-lock-hash"
VOLUME_HASH_FILE="/var/www/html/node_modules/.package-lock-hash"

if [ -f "$IMAGE_HASH_FILE" ]; then
    IMAGE_HASH=$(cat "$IMAGE_HASH_FILE")
    VOLUME_HASH=""
    if [ -f "$VOLUME_HASH_FILE" ]; then
        VOLUME_HASH=$(cat "$VOLUME_HASH_FILE")
    fi
    log "Image hash: $IMAGE_HASH"
    log "Volume hash: $VOLUME_HASH"

    if [ "$IMAGE_HASH" != "$VOLUME_HASH" ]; then
        log "node_modules volume is stale, syncing from image backup..."
        rm -rf /var/www/html/node_modules/*
        cp -a /opt/node_modules_build/. /var/www/html/node_modules/
        cp "$IMAGE_HASH_FILE" "$VOLUME_HASH_FILE"
        log "node_modules synced ($(ls /var/www/html/node_modules | wc -l) packages)"
    else
        log "npm dependencies are up to date"
    fi
else
    log "WARNING: Image hash file not found at $IMAGE_HASH_FILE (old image?)"
    # Fallback: run npm ci if node_modules looks empty
    if [ -f /var/www/html/package.json ] && [ ! -d /var/www/html/node_modules/.bin ]; then
        log "Fallback: running npm ci..."
        cd /var/www/html && npm ci
    fi
fi

# Install asyncservices cron job
/bin/sh -c "echo '*/5 * * * * /usr/local/bin/php -q /var/www/html/src/modules/phpgwapi/cron/asyncservices.php default' | sudo -u www-data crontab - "

set -e

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
log "Starting all services with Supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
