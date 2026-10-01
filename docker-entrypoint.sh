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

# True when the text on stdin is a database CONNECTION problem (waiting can fix it). Anything else, such as
# "table already exists", a bad password or a PHP error, will not fix itself and must not be retried.
is_connection_error() {
  grep -Eiq 'SQLSTATE\[HY000\] \[(2002|2003|2006|2013|2054)\]|SQLSTATE\[08[0-9]{3}\]|server has gone away|Lost connection to (MySQL )?server|php_network_getaddresses|getaddrinfo|Connection refused|Connection timed out|No route to host|Name or service not known|Temporary failure in name resolution|Can.t connect to (MySQL )?server'
}

# Applies pending migrations. Retries only while the database cannot be reached (it may still be waking up);
# any other failure prints the error once and stops the container start.
run_migrations() {
  max="${MIGRATE_MAX_ATTEMPTS:-12}"
  delay="${MIGRATE_RETRY_DELAY:-5}"
  attempt=1
  while true; do
    if output=$(php artisan migrate --force 2>&1); then
      [ -z "$output" ] || printf '%s\n' "$output"
      return 0
    fi
    if printf '%s\n' "$output" | is_connection_error; then
      if [ "$attempt" -ge "$max" ]; then
        echo "Database unreachable after $attempt attempts. Last error:"
        printf '%s\n' "$output"
        return 1
      fi
      echo "Database connection failed; retrying in ${delay}s ($attempt/$max)."
      attempt=$((attempt + 1))
      sleep "$delay"
      continue
    fi
    echo "Migration failed (not a connection problem, so waiting will not help):"
    printf '%s\n' "$output"
    return 1
  done
}

if [ "${ENTRYPOINT_TEST:-}" = "migrate" ]; then
  run_migrations
  exit $?
fi

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
  run_migrations || exit 1
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
