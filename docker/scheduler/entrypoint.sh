#!/bin/sh
set -eu

cd /app

if [ ! -f composer.json ]; then
  echo "scheduler: /app does not contain composer.json; mount the backend repository into the container." >&2
  exit 1
fi

# When the dev database runs on the host, localhost inside Docker points back
# at the container itself. Rewrite the common local hosts to the Docker host
# gateway name for the scheduler container only.
case "${DATABASE_URL:-}" in
  *127.0.0.1*|*localhost*)
    DATABASE_URL="$(printf '%s' "${DATABASE_URL}" | sed 's/127\.0\.0\.1/host.docker.internal/g; s/localhost/host.docker.internal/g')"
    export DATABASE_URL
    ;;
esac

# Same rewrite for the mailer: the scheduler inherits MAILER_DSN from the env
# files (so it matches the web app), but a host-based SMTP such as the default
# smtp://localhost:1025 (Mailpit) is unreachable as "localhost" from inside the
# container. Point it at the Docker host gateway so scheduled mail is delivered
# wherever the web app delivers it.
case "${MAILER_DSN:-}" in
  *127.0.0.1*|*localhost*)
    MAILER_DSN="$(printf '%s' "${MAILER_DSN}" | sed 's/127\.0\.0\.1/host.docker.internal/g; s/localhost/host.docker.internal/g')"
    export MAILER_DSN
    ;;
esac

if [ ! -f vendor/autoload.php ]; then
  echo "scheduler: vendor/ is missing; running composer install inside the container..."
  composer install --no-interaction --prefer-dist
fi

tick_seconds="${SCHEDULED_JOBS_TICK_SECONDS:-60}"
concurrency="${SCHEDULED_JOBS_CONCURRENCY:-4}"

echo "scheduler: starting due-job loop every ${tick_seconds}s (concurrency=${concurrency}); a job that is due at once wakes the loop"

# `app:scheduled-jobs:wait` replaces a plain sleep: it returns after the tick, or as soon as a
# job is created that is due now (it prints `woken`). A woken tick skips the interval gate, so
# an immediate job starts within about two seconds; due-time jobs are unchanged.
woken=""

while true; do
  php bin/console app:scheduled-jobs:execute-due --concurrency="${concurrency}" ${woken} --env="${APP_ENV:-dev}" --no-interaction
  waited="$(php bin/console app:scheduled-jobs:wait --timeout="${tick_seconds}" --env="${APP_ENV:-dev}" --no-interaction || true)"
  case "${waited}" in
    *woken*) woken="--woken" ;;
    *) woken="" ;;
  esac
done
