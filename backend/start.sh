#!/usr/bin/env bash
set -e

php artisan config:clear
php artisan migrate --force
php artisan route:cache
php artisan view:cache

exec apache2-foreground