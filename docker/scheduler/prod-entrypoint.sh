#!/bin/sh
# SPDX-FileCopyrightText: 2026 Humdek, University of Bern
# SPDX-License-Identifier: MPL-2.0
#
# Production scheduler entrypoint for the `selfhelp-scheduler` image. Unlike the
# dev `entrypoint.sh` (which bind-mounts source and runs composer install), this
# runs against the source + vendor already baked into the image.
set -eu

cd /app

tick_seconds="${SCHEDULED_JOBS_TICK_SECONDS:-60}"
limit="${SCHEDULED_JOBS_LIMIT:-50}"
concurrency="${SCHEDULED_JOBS_CONCURRENCY:-4}"

echo "selfhelp-scheduler: due-job loop every ${tick_seconds}s (limit=${limit}, concurrency=${concurrency}, env=${APP_ENV:-prod}); a job that is due at once wakes the loop"

# `app:scheduled-jobs:wait` replaces a plain sleep: it returns after the tick, or as soon as a
# job is created that is due now (it prints `woken`). A woken tick skips the interval gate, so
# an immediate job starts within about two seconds; due-time jobs are unchanged.
woken=""

while true; do
  if ! php bin/console app:scheduled-jobs:execute-due --limit="${limit}" --concurrency="${concurrency}" ${woken} --env="${APP_ENV:-prod}" --no-interaction; then
    echo "selfhelp-scheduler: tick failed; continuing after ${tick_seconds}s" >&2
  fi
  waited="$(php bin/console app:scheduled-jobs:wait --timeout="${tick_seconds}" --env="${APP_ENV:-prod}" --no-interaction || true)"
  case "${waited}" in
    *woken*) woken="--woken" ;;
    *) woken="" ;;
  esac
done
