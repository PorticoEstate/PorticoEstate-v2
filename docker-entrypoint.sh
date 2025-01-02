#!/bin/sh
set -e

# Start PHP-FPM
php-fpm &

# Start Apache
exec apache2ctl -D FOREGROUND