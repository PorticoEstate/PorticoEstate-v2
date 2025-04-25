#!/bin/sh
set -e

# start cron
service cron start

# Set Apache environment variables
NEXTJS_SERVER="${NEXTJS_HOST:-nextjs}:3000"

# Export variables for Apache
export NEXTJS_SERVER
export SLIM_SERVER

# Pass environment variables to Apache
echo "SetEnv NEXTJS_SERVER ${NEXTJS_SERVER}" >> /etc/apache2/conf-enabled/environment.conf

# Enable SSL module and site
a2enmod ssl
make-ssl-cert generate-default-snakeoil --force-overwrite

# Start PHP-FPM
php-fpm &

# Start Apache
exec apache2ctl -D FOREGROUND