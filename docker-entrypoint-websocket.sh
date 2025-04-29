#!/bin/bash
set -e

# Check if Redis extension is installed
if ! php -m | grep -q "redis"; then
    echo "Redis extension is not installed! Installing..."
    pecl install redis
    docker-php-ext-enable redis
fi

# Check if composer dependencies are installed
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."
    composer install --no-dev --optimize-autoloader
fi

# Create redis_data directory if it doesn't exist
if [ ! -d "/data/redis_data" ]; then
    mkdir -p /data/redis_data
    chmod -R 777 /data/redis_data
fi

# Create logs directory if it doesn't exist
if [ ! -d "/var/log/websocket" ]; then
    mkdir -p /var/log/websocket
    chmod -R 777 /var/log/websocket
fi

# Check Redis connection
echo "Checking Redis connection..."
attempts=0
max_attempts=30
connected=false

while [ $attempts -lt $max_attempts ] && [ "$connected" = false ]; do
    if php -r "try { \$redis = new \Redis(); \$redis->connect('$REDIS_HOST', $REDIS_PORT); echo 'Redis connection successful'; } catch (\Exception \$e) { echo \$e->getMessage(); exit(1); }"; then
        connected=true
    else
        attempts=$((attempts+1))
        echo "Waiting for Redis connection (attempt $attempts/$max_attempts)..."
        sleep 2
    fi
done

if [ "$connected" = false ]; then
    echo "Failed to connect to Redis after $max_attempts attempts. Check Redis connection settings."
    # Don't exit, just warn
    # exit 1
fi

# Log environment for debugging
echo "Environment:"
echo "  REDIS_HOST: $REDIS_HOST"
echo "  REDIS_PORT: $REDIS_PORT"

# Execute the original command
exec "$@"