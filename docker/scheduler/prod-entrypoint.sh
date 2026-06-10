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

echo "selfhelp-scheduler: due-job loop every ${tick_seconds}s (limit=${limit}, env=${APP_ENV:-prod})"

while true; do
  if ! php bin/console app:scheduled-jobs:execute-due --limit="${limit}" --env="${APP_ENV:-prod}" --no-interaction; then
    echo "selfhelp-scheduler: tick failed; continuing after ${tick_seconds}s" >&2
  fi
  sleep "${tick_seconds}"
done
