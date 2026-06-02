# Scheduled Jobs Cron Runner Plan

## Goal

Implement an industry-grade scheduled-job runner for the Symfony backend.

The backend already persists all executable work in `scheduled_jobs`. The missing piece is a robust cron-controlled runner that wakes up every minute, checks due queued jobs, and executes them through the same per-job execution function used by manual execution and immediate action jobs.

Default behavior:

- Runner is enabled by default.
- External cron ticks every 1 minute.
- Application-level interval defaults to 60 seconds.
- Admins can inspect runner status, disable/enable it, change the interval, and run due jobs manually from the UI.
- Future plugins can contribute scheduled-job types and handlers without changing core dispatch code.

## Current Findings

### Legacy behavior

Old implementation:

- File: `D:\TPF\SelfHelp\sh-selfhelp\server\cronjobs\ScheduledJobsQueue.php`
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

### Plugin support verified

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

Gaps to address for plugins:

- Plugin job types still need a real `jobTypes` lookup row because `scheduled_jobs.id_job_types` is a foreign key to `lookups`.
- `plugin.json#scheduledJobs` is validated, but the code search only shows runtime read/validation, not a full synchronizer that persists job type lookup rows.
- `JobSchedulerService::storeJobConfig()` currently preserves core payloads (`email_config`, `notification_config`, `task_config`) but does not define a standard generic payload path for plugin jobs.

## Architecture Decision

Use a server-level scheduler as the reliable tick source, and keep scheduling policy inside Symfony.

Recommended production cron:

```cron
* * * * * cd /path/to/sh-selfhelp_backend && php bin/console app:scheduled-jobs:execute-due --env=prod --no-interaction >> var/log/scheduled-jobs.log 2>&1
```

Why:

- OS cron, systemd timers, Docker/Kubernetes CronJobs, or a platform scheduler are the right place to guarantee process startup.
- The UI should not rewrite crontab files. That is deployment-specific and unsafe.
- The UI can still control effective behavior by updating DB-backed runner settings.
- If the runner is disabled or the configured interval has not elapsed, the command exits cleanly without executing jobs.

Do not add Symfony Scheduler or a long-running Messenger worker just for this 1-minute polling flow. Revisit those only if the product needs sub-minute execution, high-throughput async queues, or long-running worker orchestration.

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

- Cron command calls `runDueJobs(trigger: cron)`.
- Admin "Run due jobs now" endpoint calls `runDueJobs(trigger: manual, force: true)`.
- Existing single-job admin endpoint keeps calling `executeJob()`.
- Immediate action execution keeps calling `executeJob()`.
- Plugin handlers are called only from inside `executeJob()` through `executeByType()`.

## Required Backend Work

### 1. Harden per-job execution

Update `JobSchedulerService::executeJob()` so a job cannot be executed twice by overlapping callers.

Required behavior:

- Only `queued` jobs are executable by default.
- Manual execution may execute a future queued job, but not `done`, `failed`, `cancelled`, `deleted`, or already `running`.
- Cron execution only receives jobs from the due-queued query.
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
- Per-job atomic queued -> running still protects against a manual admin execution racing with cron.

Production note:

- In clustered deployments, configure `LOCK_DSN` to Redis or another shared lock backend.
- Local/dev can use the existing fallback.

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
- `interval_seconds` integer, default `60`, minimum `60` in cron mode
- `max_jobs_per_run` integer nullable or default `100`
- `lock_ttl_seconds` integer default `120`
- `stale_running_after_seconds` integer default `900`
- `updated_at`
- `id_updated_by_users` nullable FK to `users`

#### `scheduled_job_runner_runs`

Append-only run history for admin status and audit.

Fields:

- `id`
- `trigger_type` string: `cron`, `manual`, `system`
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
- whether cron appears stale
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

Individual job domain failures should be reflected in job status and run metrics, not treated as cron process failure by default.

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
  - Expose `AdminScheduledJobService::getJobTypeCatalog()` for core plus plugin job types.

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

### 8. Admin UI behavior

Frontend implementation is a separate repo change, but the backend should provide this contract.

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
- Job type catalog including plugin badges.

Do not expose raw crontab editing in the UI. Show deployment guidance instead:

- "Server tick expected every minute."
- "Application interval controls whether the runner executes on a tick."

### 9. Plugin scheduled-job extensibility

Keep current runtime dispatch:

- Plugin handler implements `PluginScheduledJobHandlerInterface`.
- Handler returns `true` for success and `false` for domain failure.
- Handler should not update `scheduled_jobs.status`; the scheduler owns status transitions.
- Handler should be idempotent or at least duplicate-safe.
- Handler can log plugin-specific transactions with the same `$transactionBy`.

Close the current gaps:

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
  - due queued job cron execution
  - admin manual execution
  - user validation email scheduling/execution
  - plugin scheduled-job execution
- Services/controllers affected:
  - `JobSchedulerService`
  - `ScheduledJobRepository`
  - new `ScheduledJobRunnerService`
  - `ScheduledJobsExecuteDueCommand`
  - `AdminScheduledJobController`
  - `AdminScheduledJobService`
  - plugin scheduled-job registry

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
- Migration tests:
  - New migration round-trip test under `tests/Integration/Migrations`.
- Golden/smoke:
  - Extend existing `tests/Golden/FormActionJobChainTest.php` only as needed.
  - Existing smoke job execution should continue to pass.
- Plugin tests:
  - A fixture plugin registers a job type and handler.
  - A plugin scheduled job executes through `JobSchedulerService::executeJob()`.

Verification commands:

```bash
php bin/phpunit tests/path/to/new/or/changed/Test.php
composer test:changed
composer phpstan
composer validate-db
```

Run broader suites only when the test database is prepared.

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
4. Install one external cron tick per environment:

```cron
* * * * * cd /path/to/sh-selfhelp_backend && php bin/console app:scheduled-jobs:execute-due --env=prod --no-interaction >> var/log/scheduled-jobs.log 2>&1
```

5. In clustered deployments:
   - configure shared `LOCK_DSN`.
   - use Kubernetes `concurrencyPolicy: Forbid` if using Kubernetes CronJob.
   - keep the app-level Symfony lock anyway.
6. Verify admin status:
   - enabled is true.
   - interval is 60 seconds.
   - last run updates after the next cron tick.
   - due queued job count drops after execution.

## Definition of Done

- Cron command runs every minute and respects DB-backed enabled/interval settings.
- All due queued jobs execute through `JobSchedulerService::executeJob()`.
- Manual single-job execution and immediate action execution still use the same function.
- Overlapping cron ticks cannot execute the same job twice.
- Admin API can read status, update settings, enable/disable, and run due jobs now.
- Runner status clearly reports disabled, locked, stale, last error, due count, and last run.
- Plugin job types can be registered, persisted as lookup-backed scheduled jobs, and executed through tagged handlers.
- JSON schemas, permissions, route migrations, cache invalidation, docs, and tests are updated.
- `composer phpstan` reports 0 errors after code changes.
