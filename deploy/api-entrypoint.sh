#!/bin/sh
# Entry point of the API image. Caches the configuration, the routes and the events with the environment of THIS container (the
# variables arrive when it starts, never in the image) and then runs the command: php-fpm, queue:work or reverb:start.
set -eu

cd /var/www/html

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan event:cache --no-interaction

exec "$@"
