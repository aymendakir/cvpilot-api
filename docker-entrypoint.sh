#!/bin/sh
set -eu

cd /var/www/html

mkdir -p \
  bootstrap/cache \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs

chown -R www-data:www-data storage bootstrap/cache
php artisan optimize:clear

attempt=1
until php artisan migrate --force; do
  if [ "$attempt" -ge 12 ]; then
    echo "Database migrations failed after $attempt attempts."
    exit 1
  fi
  echo "Database is not ready; retrying migration in 5 seconds ($attempt/12)."
  attempt=$((attempt + 1))
  sleep 5
done

# Run the minute scheduler in the web container so expired uploads are actually removed.
if [ "${RUN_SCHEDULER:-true}" = "true" ] && [ "${1:-}" = "apache2-foreground" ]; then
  su -s /bin/sh www-data -c 'php artisan schedule:work' &
fi

exec docker-php-entrypoint "$@"
