#!/bin/sh
set -e

# start cron
service cron start

# Start PHP-FPM
php-fpm &

# Start Apache
exec apache2ctl -D FOREGROUND