# Scheduled Jobs Docker Scheduler/Cron Runner Plan

Audience: Maintainers (historical reference only).
Status: archived.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Superseded by current code and the active docs; kept for history.

## Goal

Implement an industry-grade scheduled-job runner for the Symfony backend.

The backend already persists all executable work in `scheduled_jobs`. The missing piece is a robust Docker scheduler runner that wakes up every minute, checks due queued jobs, and executes them through the same per-job execution function used by manual execution and immediate action jobs.

Default behavior:

- Runner is enabled by default.
- Docker scheduler container ticks every 1 minute.
- Application-level interval defaults to 60 seconds.
- Admins can inspect runner status, disable/enable it, change the interval, and run due jobs manually from the UI.
- Existing plugin scheduled-job dispatch must not be broken, but new plugin job-type expansion is not required for the first Docker scheduler MVP.

## Current Findings

### Legacy behavior

Old implementation:

- File: `sh-selfhelp/server/cronjobs/ScheduledJobsQueue.php` (legacy CMS repo)
- It loaded the legacy services and called:

```php
$this->job_scheduler->check_queue_and_execute(transactionBy_by_cron_job);
```

Legacy `JobScheduler::check_queue_and_execute()` did this:

1. Select jobs from `view_scheduledJobs`.
2. Filter by `date_to_be_executed <= NOW()`.
3. Filter by queued status.
4. Execute each job through `execute_job()`.
5. Log a `check_scheduledJobs` transaction with job count and execution time.

### Symfony backend behavior today

The Symfony backend already has the correct core pieces:

- `src/Entity/ScheduledJob.php`
  - Doctrine entity for `scheduled_jobs`.
- `src/Repository/ScheduledJobRepository.php`
  - `findJobsToExecute()` finds queued jobs due now or earlier.
- `src/Service/Core/JobSchedulerService.php`
  - `executeJob(int $jobId, string $transactionBy)` is the central per-job executor.
  - Dispatches core `email`, `notification`, and `task` jobs.
  - Calls plugin scheduled-job handlers before core fallback.
- `src/Command/ScheduledJobsExecuteDueCommand.php`
  - Existing command: `php bin/console app:scheduled-jobs:execute-due`.
- `src/Command/ScheduledJobExecuteOneCommand.php`
  - Existing command: `php bin/console app:scheduled-jobs:execute-one <jobId>`.
- `src/Service/Action/ActionImmediateExecutorService.php`
  - Executes jobs that are due immediately after action scheduling.
- `src/Service/CMS/Admin/AdminScheduledJobService.php`
  - Manual admin execution calls `JobSchedulerService::executeJob()`.

Important verification: immediate execution, manual admin execution, one-off CLI execution, user-validation execution, and due-job CLI execution already call `JobSchedulerService::executeJob()`. That should remain the single per-job execution entrypoint.

### Plugin support verified, but not MVP-blocking

Plugin scheduled-job execution is already partially supported:

- `src/Plugin/Event/ScheduledJobTypeEvent.php`
  - Plugins can advertise job types.
- `src/Plugin/ScheduledJob/PluginScheduledJobHandlerInterface.php`
  - Plugins implement runtime handlers.
- `src/Plugin/ScheduledJob/PluginScheduledJobRegistry.php`
  - Collects tagged handlers through `selfhelp.plugin.scheduled_job_handler`.
- `config/services.yaml`
  - Wires the tagged iterator into `PluginScheduledJobRegistry`.
- `JobSchedulerService::executeByType()`
  - Calls plugin handlers first, then core handlers.

Decision for this Docker scheduler runner:

- Do not make new plugin scheduled-job functionality part of the first implementation.
- Preserve the existing plugin handler dispatch path.
- Document and test core `email`, `notification`, and `task` execution first.
- Treat deeper plugin job-type persistence/schema work as a later slice unless a plugin needs it immediately.

Known plugin gaps for the later slice:

- Plugin job types still need a real `jobTypes` lookup row because `scheduled_jobs.id_job_types` is a foreign key to `lookups`.
- `plugin.json#scheduledJobs` is validated, but the code search only shows runtime read/validation, not a full synchronizer that persists job type lookup rows.
- `JobSchedulerService::storeJobConfig()` currently preserves core payloads (`email_config`, `notification_config`, `task_config`) but does not define a standard generic payload path for plugin jobs.

## Architecture Decision

Use a deployment-level scheduler as the reliable tick source, and keep scheduling policy inside Symfony.

Docker-only v1 assumptions:

- First official production target: Linux server with Docker Compose.
- Windows support: local/demo installs through Docker Desktop with WSL2, not Windows Server production in v1.
- No manual PHP/Node/shared-hosting install path in v1.
- Installer owns generated `compose.yaml`, generated `.env`, service secrets, image versions, and scheduler service wiring.
- The scheduler container has no public ports and contains no business logic outside the Symfony command.

For Docker-only v1, the scheduler is a dedicated container generated by the installer. It uses the same backend image as the Symfony API and runs the due-job command once per minute.

Production `compose.yaml` concept:

```yaml
scheduler:
  image: selfhelp/backend:${SELFHELP_VERSION}
  restart: unless-stopped
  depends_on:
    backend:
      condition: service_started
    redis:
      condition: service_healthy
    mysql:
      condition: service_healthy
  env_file:
    - .env
  command: >
    sh -lc "while true; do
      php bin/console app:scheduled-jobs:execute-due --env=prod --no-interaction;
      sleep $${SCHEDULED_JOBS_TICK_SECONDS:-60};
    done"
```

If the installer profile uses an external database or external Redis, the generated service should omit the matching bundled-service dependency and point `DATABASE_URL` / `LOCK_DSN` at the external service.

Why:

- No host crontab is required.
- It works the same on a Linux server and on Windows through Docker Desktop with WSL2.
- The installer can generate and manage the service inside `compose.yaml`.
- The container only provides the regular tick; Symfony owns enabled/disabled state, interval checks, locking, due-job selection, and execution.
- The UI should not rewrite crontab files. That is deployment-specific and unsafe.
- The UI can still control effective behavior by updating DB-backed runner settings.
- If the runner is disabled or the configured interval has not elapsed, the command exits cleanly without executing jobs.

Host cron and systemd timers are fallback documentation for non-Docker/manual deployments only, not the v1 installer path.

Kubernetes can later use a Kubernetes CronJob or a scheduler Deployment, but this is not a v1 requirement.

Do not add Symfony Scheduler or a long-running Messenger worker just for this 1-minute polling flow. Revisit those only if the product needs sub-minute execution, high-throughput async queues, or long-running worker orchestration.

Future improvement: replace the shell `while true` loop with a dedicated foreground Symfony command, for example `app:scheduled-jobs:run-loop`, for cleaner Docker signal handling and shutdown behavior.

## Target Execution Model

Keep one per-job function:

```php
JobSchedulerService::executeJob(int $jobId, string $transactionBy): ScheduledJob|false
```

Add one due-runner function:

```php
ScheduledJobRunnerService::runDueJobs(RunnerTrigger $trigger, ?int $limit = null, bool $force = false): ScheduledJobRunResult
```

Callers:

- Docker scheduler container calls the console command, which calls `runDueJobs(trigger: scheduler)`.
- Admin "Run due jobs now" endpoint calls `runDueJobs(trigger: manual, force: true)`.
- Existing single-job admin endpoint keeps calling `executeJob()`.
- Immediate action execution keeps calling `executeJob()`.
- Plugin handlers are called only from inside `executeJob()` through `executeByType()`.

Terminology:

- In code and API, prefer `scheduler` / `runner`.
- In legacy references, `cron` means the same scheduled-job tick source.
- In Docker v1, the tick source is the scheduler container, not the host.

## Required Backend Work

### 1. Harden per-job execution

Update `JobSchedulerService::executeJob()` so a job cannot be executed twice by overlapping callers.

Required behavior:

- Only `queued` jobs are executable by default.
- Manual execution may execute a future queued job, but not `done`, `failed`, `cancelled`, `deleted`, or already `running`.
- Scheduler/cron execution only receives jobs from the due-queued query.
- Status change from `queued` to `running` should be atomic.
- If another process already moved the job out of `queued`, return `false` or a typed result without executing side effects.
- Always set `date_executed` when a job reaches `done` or `failed`.
- Preserve current no-auto-retry behavior unless a retry feature is explicitly designed later.

Implementation options:

- Preferred: repository/DBAL method that atomically updates queued -> running with `WHERE id = :id AND id_job_status = :queuedStatusId`, then checks affected rows.
- Alternative: transaction with pessimistic row lock where Doctrine/DBAL support is clean.

### 2. Add a runner service

Create a service such as:

```text
src/Service/Core/ScheduledJobRunnerService.php
```

Responsibilities:

- Read runner settings.
- Check enabled/disabled state.
- Check whether the configured interval has elapsed.
- Acquire a Symfony Lock before selecting jobs.
- Fetch due queued jobs with a DB-level limit.
- Call `JobSchedulerService::executeJob()` for each job.
- Track counts: due, attempted, done, failed, skipped.
- Write run history/status.
- Log a `check_scheduledJobs` transaction summary, matching the old system intent.
- Invalidate scheduled-job caches after state changes.

Do not put admin settings, lock handling, or run-history concerns into `JobSchedulerService`; keep that service focused on scheduling and per-job execution.

### 3. Use Symfony Lock

The project already has `symfony/lock` and `config/packages/lock.yaml`.

Add a dedicated lock resource or use the default lock factory:

- Lock name: `scheduled_jobs_runner`
- TTL: configurable, default 120 seconds.
- Acquisition: non-blocking.

Behavior:

- If lock is unavailable, mark/run-result as `skipped_locked` and exit success.
- Lock prevents overlapping minute ticks and multi-server double sweeps.
- Per-job atomic queued -> running still protects against a manual admin execution racing with the scheduler.

Production note:

- For Docker production installs, `LOCK_DSN` must always point to Redis. The installer should generate this by default:

```dotenv
LOCK_DSN=redis://redis:6379
```

- A local/file lock may only be used for local development.
- `PLUGIN_LOCK_DSN` remains for plugin lifecycle locks; the scheduled-job runner can use `LOCK_DSN` unless a dedicated lock resource is added.
- In clustered deployments, configure `LOCK_DSN` to Redis or another shared lock backend.

Even with a single scheduler container, keep the lock. It protects future scale-out, operator mistakes, manual `docker compose run scheduler ...`, and concurrent admin run-now calls.

### 4. Add runner settings and status persistence

Use dedicated operational tables instead of the CMS preferences page. This is operational scheduler state, not page/content configuration.

Generate the migration with the repository command, for example:

```bash
php bin/console make:migration
```

Do not manually invent the migration class name.

Proposed tables:

#### `scheduled_job_runner_settings`

Single-row settings table.

Fields:

- `id`
- `enabled` boolean, default `1`
- `interval_seconds` integer, default `60`, minimum `60` in scheduler mode
- `max_jobs_per_run` integer nullable or default `100`
- `lock_ttl_seconds` integer default `120`
- `stale_running_after_seconds` integer default `900`
- `updated_at`
- `id_updated_by_users` nullable FK to `users`

#### `scheduled_job_runner_runs`

Append-only run history for admin status and audit.

Fields:

- `id`
- `trigger_type` string: `scheduler`, `manual`, `system`
- `status` string: `running`, `succeeded`, `failed`, `skipped_disabled`, `skipped_interval`, `skipped_locked`
- `started_at`
- `finished_at` nullable
- `duration_ms` nullable
- `due_count`
- `attempted_count`
- `done_count`
- `failed_count`
- `skipped_count`
- `lock_acquired` boolean
- `error_message` nullable text
- `settings_snapshot` json nullable

The UI status endpoint can derive:

- enabled state
- configured interval
- last started/finished run
- last outcome
- next eligible run time
- whether the scheduler appears stale
- due job count
- oldest overdue job age
- currently running jobs

### 5. Improve due-job querying

Update `ScheduledJobRepository` with methods such as:

- `countDueQueuedJobs(DateTimeInterface $now): int`
- `findDueQueuedJobs(DateTimeInterface $now, int $limit): array`
- `countRunningJobs(): int`
- `findStaleRunningJobs(DateTimeInterface $threshold): array` if stale recovery is implemented.

The due query must:

- Filter `status.lookupCode = queued`.
- Filter `dateToBeExecuted <= now UTC`.
- Sort by `dateToBeExecuted ASC`, then `id ASC`.
- Apply `setMaxResults($limit)` at query level, not by fetching all rows and slicing in PHP.

### 6. Update the console command

Refactor `ScheduledJobsExecuteDueCommand` so it delegates to `ScheduledJobRunnerService`.

Options:

- `--limit=<n>` override max jobs for this invocation.
- `--force` bypass enabled/interval checks for admin/operator use.
- `--dry-run` show due counts and status without executing jobs.
- `--json` optional machine-readable output for deployment health tooling.

Exit-code policy:

- `0`: runner completed, skipped by policy, or individual jobs failed cleanly and were marked `failed`.
- `1`: runner infrastructure failure, invalid configuration, lock subsystem failure that is not a normal contention skip, database failure, or uncaught exception.

Individual job domain failures should be reflected in job status and run metrics, not treated as scheduler process failure by default.

Docker behavior:

- The command must be safe to run every 60 seconds forever.
- It must write concise logs to stdout/stderr so `docker compose logs scheduler` is useful.
- It must not require an interactive shell or TTY.
- The scheduler command must tolerate temporary startup failures when MySQL or Redis are not ready yet. Infrastructure errors should be logged clearly and the container should continue/retry on the next tick.
- The MVP can use the simple shell loop from the architecture section; later, replace it with `app:scheduled-jobs:run-loop` for cleaner foreground execution.

### 7. Admin API for UI controls

Add admin endpoints under `/cms-api/v1/admin/scheduled-jobs/runner`.

Suggested endpoints:

- `GET /admin/scheduled-jobs/runner/status`
  - Read current settings, latest run, health flags, queue counts.
- `PUT /admin/scheduled-jobs/runner/settings`
  - Update `enabled`, `intervalSeconds`, `maxJobsPerRun`, `lockTtlSeconds`.
- `POST /admin/scheduled-jobs/runner/enable`
  - Convenience toggle.
- `POST /admin/scheduled-jobs/runner/disable`
  - Convenience toggle.
- `POST /admin/scheduled-jobs/runner/run-now`
  - Execute due jobs immediately through the runner service.
- `GET /admin/scheduled-jobs/types`
  - Expose core job types now; include plugin job-type metadata only if the later plugin slice is implemented.

Route and permission work:

- Add controller methods.
- Add JSON request schemas.
- Add JSON response schemas.
- Generate a Doctrine migration for new `api_routes` rows and `rel_api_routes_permissions`.
- Clear/rebuild API route cache after deployment.

Permissions:

- `admin.scheduled_job.read`
  - status, run history, job type catalog.
- `admin.scheduled_job.execute`
  - run-now and existing single-job execute.
- Add `admin.scheduled_job.manage` or reuse `admin.settings` for settings changes.
  - Preferred: add `admin.scheduled_job.manage` for least privilege.

### 8. Admin UI and frontend work

The overall implementation includes the frontend changes. The backend must provide the API contract above, and the admin UI in the frontend repo must consume it. Before implementing the UI, read the frontend repository `AGENTS.md` and update the shared/frontend types if this API contract is consumed there.

Frontend deliverables:

- Add scheduler status/settings API client calls.
- Add or extend the scheduled-jobs admin page with a runner status/settings panel.
- Add form validation for interval, max jobs per run, and enable/disable state.
- Add loading, saving, disabled, error, and stale-scheduler states.
- Add a "Run due jobs now" action wired to the backend run-now endpoint.
- Update shared TypeScript types/schemas if the frontend consumes typed API contracts from the shared repo.
- Add focused frontend tests for rendering status, changing settings, and triggering run-now.

UI should show:

- Runner enabled/disabled toggle.
- Interval selector/input, default 1 minute.
- Max jobs per run.
- Last run status.
- Last started/finished times.
- Next eligible run time.
- Due queued jobs count.
- Oldest overdue job.
- Running jobs count.
- Last error message.
- "Run due jobs now" button.
- Link to filtered scheduled jobs list for due, failed, running, and overdue jobs.
- Job type catalog for core jobs. Plugin badges can be added in the later plugin slice.

Do not expose raw crontab editing in the UI. Show deployment guidance instead:

- "Docker scheduler tick expected every minute."
- "Application interval controls whether the runner executes on a tick."

### 9. Plugin scheduled-job extensibility, later slice

Keep current runtime dispatch:

- Plugin handler implements `PluginScheduledJobHandlerInterface`.
- Handler returns `true` for success and `false` for domain failure.
- Handler should not update `scheduled_jobs.status`; the scheduler owns status transitions.
- Handler should be idempotent or at least duplicate-safe.
- Handler can log plugin-specific transactions with the same `$transactionBy`.

Not required for the Docker scheduler MVP:

- Plugin job-type persistence and plugin payload schema improvements are not part of the Docker scheduler MVP. The MVP only preserves the existing plugin dispatch path and focuses on core email, notification, and task jobs.
- Do not block the scheduled-job runner on plugin job-type persistence.
- Do not add a plugin synchronizer unless a real plugin needs scheduled-job creation now.
- Keep `executeByType()` plugin dispatch intact so existing or future handlers still work once valid jobs exist.

Close these gaps in a later plugin-focused slice:

- Ensure plugin job types get a persistent `jobTypes` lookup row.
  - Either require plugins to declare `lookups.extends[]` for `jobTypes`, or add a synchronizer that maps `plugin.json#scheduledJobs` into lookup rows.
  - Document one authoritative path.
- Add a standard plugin payload path in `JobSchedulerService::storeJobConfig()`, for example:

```php
$config['plugin'] = $jobData['plugin_config'] ?? [];
```

- Extend `plugin.json#scheduledJobs` schema if needed:
  - `type`
  - `description`
  - `handlerServiceId`
  - `configSchemaPath`
  - `capability`
- Expose plugin job type metadata in `GET /admin/scheduled-jobs/types`.
- Add plugin integration tests proving register -> schedule -> execute -> cleanup.

### 10. Stale running jobs and retry policy

Current behavior has no automatic retry. Preserve that first.

Add explicit future slice only if product wants retries:

- `attempt_count`
- `max_attempts`
- `retry_after_seconds`
- `last_error`
- retry-safe job type declarations

For the initial runner, stale `running` jobs should be visible in status. Recovery should be conservative:

- Detect running jobs older than `stale_running_after_seconds`.
- Show them in admin status.
- Do not auto-retry by default.
- Provide a future admin action to mark stale running jobs as failed after review.

If implementing stale detection requires job-level timing, add a `date_started` column to `scheduled_jobs` in a generated migration and set it when transitioning to `running`.

## Testing Plan

Test impact analysis:

- Workflows affected:
  - action scheduling -> immediate execution
  - due queued job scheduler execution
  - admin manual execution
  - admin UI scheduler status/settings workflow
  - user validation email scheduling/execution
  - existing plugin scheduled-job dispatch must remain unchanged, but new plugin scheduling is not in MVP scope
- Services/controllers affected:
  - `JobSchedulerService`
  - `ScheduledJobRepository`
  - new `ScheduledJobRunnerService`
  - `ScheduledJobsExecuteDueCommand`
  - `AdminScheduledJobController`
  - `AdminScheduledJobService`
  - frontend scheduled-jobs admin page/API client
  - shared/frontend API contract types if consumed
  - plugin scheduled-job registry only if the later plugin slice is implemented

Required tests:

- Unit tests for runner settings:
  - disabled runner skips.
  - interval not elapsed skips.
  - force bypasses interval.
  - lock contention skips.
  - limit is honored.
- Unit/integration tests for per-job guard:
  - queued job executes.
  - done job is not re-executed.
  - failed job is not automatically retried.
  - concurrent queued -> running transition executes only once.
- Command tests:
  - `app:scheduled-jobs:execute-due` delegates to runner.
  - `--limit`, `--force`, `--dry-run` behavior.
  - job domain failure records failed job but command exits success.
- Admin API tests:
  - status endpoint success shape.
  - settings update validation.
  - run-now success path.
  - permission matrix for read/execute/manage.
- Frontend tests:
  - scheduler status/settings panel renders backend state.
  - enable/disable and interval changes call the settings endpoint.
  - run-now action calls the run-now endpoint and refreshes status.
  - stale/disabled/locked/error states are visible and do not break the scheduled-jobs list.
- Migration tests:
  - New migration round-trip test under `tests/Integration/Migrations`.
- Golden/smoke:
  - Extend existing `tests/Golden/FormActionJobChainTest.php` only as needed.
  - Existing smoke job execution should continue to pass.
- Plugin tests:
  - Not required for the Docker scheduler MVP unless plugin behavior is changed.
  - Later plugin slice: fixture plugin registers a job type and handler, then a plugin scheduled job executes through `JobSchedulerService::executeJob()`.

Verification commands:

```bash
php bin/phpunit tests/path/to/new/or/changed/Test.php
composer test:changed
composer phpstan
composer validate-db
```

Run broader suites only when the test database is prepared.

For frontend/shared changes, run the standard commands from those repositories after reading their `AGENTS.md`; do not invent command names in this backend plan.

## Cache, Auth, Permissions, and API Impact

Cache:

- Existing scheduled-job writes should keep invalidating `CacheService::CATEGORY_SCHEDULED_JOBS`.
- Runner setting updates should invalidate runner status/settings cache if cached.
- Low-volume status endpoints can be uncached initially.

Auth/permissions:

- All runner admin endpoints are JWT-protected under `/cms-api/v1/admin`.
- Use existing route permission system through `api_routes` and `rel_api_routes_permissions`.
- Add or reuse permission as noted above for settings management.

API:

- New endpoints require JSON schemas under `config/schemas/api/v1`.
- Frontend/shared consumers must not depend on undocumented fields.
- If shared TypeScript types exist for admin scheduled jobs in another repo, update them in the same implementation phase.

Database:

- Use generated Doctrine migrations only.
- Do not edit legacy SQL files for new behavior.
- Use lowercase snake_case table, column, index, and constraint names.
- Store all timestamps in UTC.

## Deployment Plan

1. Deploy DB migration.
2. Deploy backend code.
3. Clear API route cache.
4. Installer generates a production `compose.yaml` with at least:
   - reverse proxy/TLS service
   - backend API service
   - frontend/BFF service
   - Messenger worker service
   - scheduler service
   - Redis service or external Redis connection
   - Mercure service or external Mercure connection
   - MySQL service or external DB connection
5. Scheduler service uses the backend image and runs the due-job command every 60 seconds:

```yaml
scheduler:
  image: selfhelp/backend:${SELFHELP_VERSION}
  restart: unless-stopped
  env_file:
    - .env
  command: >
    sh -lc "while true; do
      php bin/console app:scheduled-jobs:execute-due --env=prod --no-interaction;
      sleep $${SCHEDULED_JOBS_TICK_SECONDS:-60};
    done"
```

6. Configure `LOCK_DSN` to Redis in generated `.env`. For Docker production installs, this is mandatory:

```dotenv
LOCK_DSN=redis://redis:6379
```

7. In clustered deployments:
   - configure shared `LOCK_DSN`.
   - use Kubernetes `concurrencyPolicy: Forbid` if using Kubernetes CronJob.
   - keep the app-level Symfony lock anyway.
8. Verify admin status:
   - enabled is true.
   - interval is 60 seconds.
   - last run updates after the next scheduler tick.
   - due queued job count drops after execution.

Host cron fallback for non-Docker/manual deployments:

```cron
* * * * * cd /path/to/sh-selfhelp_backend && php bin/console app:scheduled-jobs:execute-due --env=prod --no-interaction >> var/log/scheduled-jobs.log 2>&1
```

This fallback should not be generated by the Docker-only v1 installer.

## Definition of Done

- Docker-only installer includes a scheduler container in generated `compose.yaml`.
- Scheduler container runs the due-job command every minute and respects DB-backed enabled/interval settings.
- Scheduler container can run on Linux production hosts and on Windows local/demo installs through Docker Desktop with WSL2.
- All due queued jobs execute through `JobSchedulerService::executeJob()`.
- Manual single-job execution and immediate action execution still use the same function.
- Overlapping scheduler ticks cannot execute the same job twice.
- Admin API can read status, update settings, enable/disable, and run due jobs now.
- Runner status clearly reports disabled, locked, stale, last error, due count, and last run.
- Existing plugin scheduled-job dispatch remains compatible; deeper plugin job-type registration/persistence is documented as a later slice.
- JSON schemas, permissions, route migrations, cache invalidation, docs, and tests are updated.
- `composer phpstan` reports 0 errors after code changes.
