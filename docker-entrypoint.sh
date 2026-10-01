#!/bin/sh
set -eu

# Which role does this container play?
#  web    : the command is apache2-foreground. Applies migrations; may run the scheduler beside Apache.
#  worker : anything else (for example `php artisan schedule:work`). Never migrates unless RUN_MIGRATIONS=true.
# RUN_MIGRATIONS=true|false overrides the default; RUN_SCHEDULER=true starts `schedule:work` next to Apache
# (for hosts with a single container and no cron, such as Sevalla).
role=worker
[ "${1:-}" = "apache2-foreground" ] && role=web

migrate=no
if [ "${RUN_MIGRATIONS:-}" = "true" ] || { [ -z "${RUN_MIGRATIONS:-}" ] && [ "$role" = "web" ]; }; then
  migrate=yes
fi

scheduler=no
[ "$role" = "web" ] && [ "${RUN_SCHEDULER:-}" = "true" ] && scheduler=yes

if [ "${ENTRYPOINT_PLAN:-}" = "1" ]; then
  echo "role=$role migrate=$migrate scheduler=$scheduler"
  exit 0
fi

cd /var/www/html

mkdir -p \
  bootstrap/cache \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs

chown -R www-data:www-data storage bootstrap/cache
php artisan optimize:clear

if [ "$migrate" = "yes" ]; then
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
fi

if [ "$scheduler" = "yes" ]; then
  # A tiny supervisor: if the scheduler ever exits, log it and start it again. Runs as www-data like Apache.
  (
    while true; do
      setpriv --reuid=www-data --regid=www-data --init-groups php artisan schedule:work || true
      echo "Scheduler exited; restarting in 5 seconds." >&2
      sleep 5
    done
  ) &
fi

if [ "$role" = "worker" ]; then
  # Do not run workers as root: files they create in storage/ must stay writable by Apache.
  exec setpriv --reuid=www-data --regid=www-data --init-groups docker-php-entrypoint "$@"
fi

exec docker-php-entrypoint "$@"
