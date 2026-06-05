# Scheduled Jobs and Action Runtime

Audience: Developers and technical operators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-05.
Source of truth: Runtime code, configuration, migrations, and tests in this repository.

## Overview

The Symfony backend now owns the full action scheduling flow that used to live in legacy `UserInput` code.

Whenever user input is saved or deleted through `DataService`, we now:

1. commit the data change
2. build an action trigger context
3. resolve matching actions by `dataTable + trigger_type`
4. expand action config into concrete scheduled jobs
5. execute immediate jobs right away

This gives us one native pipeline for:

- frontend form submits
- frontend form updates
- frontend form deletes
- user validation form input saves

## What Replaced Legacy

Legacy behavior was previously handled in the old `sh-selfhelp` project by `UserInput::queue_job_from_actions()`.

That logic is now replaced by Symfony services:

- `ActionContextBuilderService`
- `ActionResolverService`
- `ActionConfigRuntimeService`
- `ActionRecipientResolverService`
- `ActionConditionEvaluatorService`
- `ActionCleanupService`
- `ActionScheduleCalculatorService`
- `ActionSchedulerService`
- `ActionImmediateExecutorService`
- `ActionOrchestratorService`

The validation endpoint no longer loads `legacy/UserInput.php`.

## Runtime Flow

### Save / update

`DataService::saveData()` now:

1. saves the record
2. commits the Doctrine transaction
3. calls `runActionOrchestration()`

Update saves default to trigger type `updated` when no explicit `trigger_type` is provided.

Create saves default to `finished`.

### Delete

`DataService::deleteData()` now:

1. marks the row as deleted
2. commits
3. runs delete-trigger actions
4. removes queued jobs linked to the deleted row

### User validation

`UserValidationController::saveUserFormInputs()` now persists into `user_validation_inputs` via `DataService`.

That means validation form inputs also use the same action pipeline as normal forms.

## Supported Legacy Behaviors

The action runtime preserves the legacy admin config contract:

- action conditions
- block conditions
- job conditions
- reminder conditions
- overwrite variables
- `impersonate_user_code`
- target groups
- action-level randomization
- even-presentation randomization counters
- `repeat`
- `repeat_until_date`
- reminders
- diary-style reminder validity windows
- `clear_existing_jobs_for_action`
- `clear_existing_jobs_for_record_and_action`
- delete cleanup for queued jobs linked to a deleted record
- immediate execution after scheduling

## Core Services

### `ActionConfigRuntimeService`

Responsibilities:

- decode stored JSON config
- apply overwrite variables into job schedule blocks
- interpolate `{{variables}}` with submitted form values
- persist randomization counters back onto the `actions.config`

Action email/notification templates use Mustache `{{...}}` placeholders only
(the legacy at-style action syntax is gone). `ActionTemplateContextBuilder`
exposes per-recipient scopes that are rendered during fan-out:
`{{recipient.email}}`, `{{recipient.name}}`, `{{recipient.user_name}}`,
`{{recipient.code}}`, `{{recipient.timezone}}`, plus `{{record.field_name}}`,
`{{system.project_name}}` and `{{system.platform_url}}`.

### `ActionCleanupService`

Responsibilities:

- soft-delete queued jobs for one action and user
- soft-delete queued jobs for one action and one record
- soft-delete queued jobs for one deleted record
- soft-delete queued reminder jobs when the reminder target form is completed

### `ActionSchedulerService`

Responsibilities:

- expand blocks/jobs/reminders into concrete `ScheduledJob` rows
- map action job types to scheduled job types:
  - `add_group` / `remove_group` -> `task`
  - notification email -> `email`
  - notification push -> `notification`
- attach action lineage:
  - `action`
  - `dataTable`
  - `dataRow`
  - `parentJob`
  - `reminderDataTable`
  - reminder validity window

### `JobSchedulerService`

Responsibilities:

- low-level `scheduleJob()` persistence
- job config storage
- manual execution
- due-job execution
- actual execution for:
  - email jobs
  - push notification jobs
  - task jobs

### `TaskJobExecutorService`

Responsibilities:

- add groups to users
- remove groups from users
- log task execution transactions

## Scheduled Job Persistence

Core job state remains on `ScheduledJob`.

Reminder-only metadata now lives in the dedicated `scheduled_job_reminders` table and `ScheduledJobReminder` entity.

That reminder metadata stores:

- owning scheduled job
- parent scheduled job
- reminder target data table
- reminder session start
- reminder session end

This keeps reminder-specific state out of the main `scheduled_jobs` table while still supporting cleanup and lineage queries.

## Database Migration

The reminder metadata refactor is applied through Symfony/Doctrine migrations.

### Upgrade the database

Run the pending migrations:

```bash
php bin/console doctrine:migrations:migrate
```

This will:

- create `scheduled_job_reminders`
- add the reminder foreign keys and indexes
- remove obsolete reminder columns from `scheduled_jobs` when they exist

### Preview the upgrade SQL

```bash
php bin/console doctrine:migrations:migrate --dry-run
```

If you want to compare Doctrine mapping vs current database state:

```bash
php bin/console doctrine:schema:update --dump-sql
```

### Downgrade the database

To roll back the last migration:

```bash
php bin/console doctrine:migrations:migrate prev
```

Or migrate to a specific version:

```bash
php bin/console doctrine:migrations:migrate 20260413130000
```

To roll back this specific migration after it has been applied, migrate to the previous version before `20260413130000`.

### Migration file

The reminder metadata table (`scheduled_job_reminders`) ships in the
canonical baseline:

- `migrations/Version20260601000000.php`

## Execution Surfaces

### Admin API

Existing endpoint is still available:

- `POST /cms-api/v1/admin/scheduled-jobs/{jobId}/execute`

### Console

Execute all due queued jobs:

```bash
php bin/console app:scheduled-jobs:execute-due
```

Optional limit:

```bash
php bin/console app:scheduled-jobs:execute-due --limit=50
```

Execute one job manually:

```bash
php bin/console app:scheduled-jobs:execute-one 123
```

### Docker scheduler (v1) and cron fallback

In Docker production the tick source is a dedicated `scheduler` container running
the command in a loop every `SCHEDULED_JOBS_TICK_SECONDS` (default 60); the
DB-backed runner settings decide whether work actually runs. See the "Docker
Runner" section above. For non-Docker/manual deployments a host cron works too:

```cron
* * * * * cd /path/to/sh-selfhelp_backend && php bin/console app:scheduled-jobs:execute-due --env=prod --no-interaction >> var/log/scheduled-jobs.log 2>&1
```

For local backend development in this repository, the root `docker-compose.yml`
also includes a `scheduler` service that runs the same command every
`SCHEDULED_JOBS_TICK_SECONDS` seconds (default `60`) so the due-runner can be
tested without a host cron.

## Email Execution

Email jobs are executed directly from `JobSchedulerService`.

Stored config includes:

- sender
- reply-to
- recipients
- subject
- body
- attachments

Attachments are included when local files exist.

## Push Notification Execution

Push notifications are sent through Firebase HTTP v1.

Requirements:

- CMS preference `firebase_config` must contain a valid Firebase service account JSON string or path
- target user must have `device_token`

If either is missing, the job fails cleanly and is logged.

## Communication Preferences and Delivery Policy

Issue #29: users carry two persisted booleans, `users.receives_notifications`
and `users.receives_emails` (default `1`), editable from the profile page
(`PUT /auth/user/communication-preferences`) and admin user management.

Every scheduled `email` / `notification` job carries a `delivery_policy`
(lookup group `scheduledJobDeliveryPolicies`):

- `respect_user_preferences` (default) — if the delivery target maps to a known
  `User` who disabled that channel, the job is **skipped** at execution time, not
  failed.
- `required_system` — account/security mail (validation, password reset, 2FA,
  account-deletion, admin activation/resend). It ignores `receives_emails` and is
  always delivered. Set only by trusted backend paths
  (`UserValidationService`, etc.), never exposed as a casual admin toggle.

Enforcement lives in `JobSchedulerService::executeEmailJob()` /
`executeNotificationJob()` and returns a typed `ScheduledJobExecutionResult`
(`done` / `failed` / `skipped`). Skipped deliveries:

- end in status `skipped_user_disabled_emails` or
  `skipped_user_disabled_notifications` (never `failed`);
- log a `send_mail_skipped` / `send_notification_skipped` transaction;
- set `date_executed`;
- do not make the due-runner command exit non-zero.

### Recipient snapshots and fan-out

`scheduled_job_recipients` is the authoritative recipient source. When a job is
created with an explicit `recipients` list (or a single email config),
`JobSchedulerService::scheduleJob()` persists one snapshot per recipient
(`channel`, `recipient_type`, `recipient_email`, `id_users`, `delivery_policy`,
`resolved_from`). The executor prefers the snapshot and falls back to
`config.email.recipient_emails` + `ScheduledJob::getUser()` for legacy rows.

`ActionSchedulerService` **fans out**: a multi-recipient action email schedules
one job per resolved recipient user, so each job has exactly one primary
recipient and one clear terminal status (`done` / `failed` / skipped). External
addresses with no matching `User` are sent when valid (no stored preference to
apply).

## User-Timezone-Aware Scheduling

Wall-clock action times (e.g. "send at 07:00") are calculated in the recipient's
timezone and persisted in UTC on `scheduled_jobs.date_to_be_executed`.
`ActionScheduleCalculatorService::calculateDates()` takes an
`ActionScheduleContext` (recipient timezone + clock), and
`ActionSchedulerService` calculates dates **per recipient** so two users in
different timezones get different UTC instants for the same local rule.

`config.schedule` stores the intent: `wall_clock`, `local_datetime`, `timezone`,
`timezone_source`, and the original `rule`. Purely relative schedules
("after 2 hours") are not wall-clock and are never recalculated.

When a user changes timezone, `ProfileService::updateTimezone()` calls
`QueuedJobTimezoneAdjustmentService::adjustForUser()`, which recalculates only
that user's **queued, future, wall-clock** jobs (preserving the local time),
logs a transaction, and invalidates scheduled-job caches.

## Docker Runner

The Docker scheduler container ticks `app:scheduled-jobs:execute-due` every
minute; the command delegates to `ScheduledJobRunnerService::runDueJobs()`, which
owns the operational concerns (settings, interval gate, lock, run history). All
per-job execution still flows through the single
`JobSchedulerService::executeJob()` entrypoint.

- `scheduled_job_runner_settings` — singleton row: `enabled`, `interval_seconds`
  (min 60), `max_jobs_per_run`, `lock_ttl_seconds`, `stale_running_after_seconds`.
- `scheduled_job_runner_runs` — one row per tick with trigger, status, counts
  (due/attempted/done/failed/skipped), timing, and `lock_acquired`.
- **Atomic claim:** `ScheduledJobRepository::claimQueuedJobForExecution()` runs
  `UPDATE ... WHERE id = :id AND id_job_status = queued`, so overlapping ticks
  cannot execute the same job twice. `scheduled_jobs.date_started` records the
  claim time and powers stale-running detection.
- **Lock:** a non-blocking Symfony Lock named `scheduled_jobs_runner`; lock
  contention records a `skipped_locked` run and exits success. Clustered/Docker
  deployments must set `LOCK_DSN=redis://redis:6379`.

### Console options

```bash
php bin/console app:scheduled-jobs:execute-due [--limit=N] [--force] [--dry-run] [--json]
```

- `--limit` caps jobs this tick (DB-level `setMaxResults`).
- `--force` bypasses the enabled flag + interval gate (used by "Run now").
- `--dry-run` reports due counts / policy state without executing.
- `--json` emits a machine-readable summary for health tooling.

Exit `0` for normal completion (including policy/lock skips and cleanly
failed/skipped jobs); exit `1` only for infrastructure failure.

### Admin runner API

Under `/cms-api/v1/admin/scheduled-jobs/runner` (see
`AdminScheduledJobRunnerController`):

- `GET status` — settings, last run, queue counts, stale/health flags
  (`admin.scheduled_job.read`).
- `PUT settings`, `POST enable`, `POST disable` (`admin.scheduled_job.manage`).
- `POST run-now` — `runDueJobs(trigger: manual, force: true)`
  (`admin.scheduled_job.execute`).

## End-to-End Example

### Example action config concept

Form submit on `sleep_diary` with trigger `finished`:

- action condition: none
- block 1:
  - job 1: email immediately
  - job 2: add user to group after 1 day
  - reminder 1: push reminder after 2 hours

### Runtime sequence

1. frontend posts form data
2. `DataService::saveData()` inserts row in `sleep_diary`
3. transaction commits
4. `ActionOrchestratorService` resolves `sleep_diary + finished`
5. runtime config is interpolated with submitted values
6. recipients are resolved from source user, groups, or impersonation
7. `ActionSchedulerService` creates:
   - email scheduled job
   - task scheduled job
   - child reminder scheduled job
8. immediate email job executes immediately
9. delayed jobs stay queued until admin/manual execution or cron execution

## Extending Safely

To add a new action-driven job type:

1. extend `ActionSchedulerService::resolveScheduledJobType()`
2. persist its config in `JobSchedulerService::storeJobConfig()`
3. execute it in `JobSchedulerService::executeByType()`
4. document it in admin docs
5. add tests

## Troubleshooting

### Job not scheduled

Check:

- was the form saved through `DataService`?
- does the action trigger type match the save event?
- did action condition or block/job condition evaluate to false?
- did recipient resolution return no users?
- did `clear_existing_jobs_*` remove an older queued entry and no new one get created because of conditions?

### Job queued but not executed

Check:

- is `date_to_be_executed` in the past?
- was it an immediate job that failed during execution?
- did the on-execute condition fail?
- does the email job have valid recipients?
- does the notification job have `device_token` and valid Firebase config?
- is cron running `app:scheduled-jobs:execute-due`?

## Files to Know

- `src/Service/CMS/DataService.php`
- `src/Controller/Api/V1/Auth/UserValidationController.php`
- `src/Service/Action/ActionOrchestratorService.php`
- `src/Service/Action/ActionSchedulerService.php`
- `src/Service/Action/ActionScheduleCalculatorService.php`
- `src/Service/Action/ActionTemplateContextBuilder.php`
- `src/Service/Core/JobSchedulerService.php`
- `src/Service/Core/ScheduledJobRunnerService.php`
- `src/Service/Core/QueuedJobTimezoneAdjustmentService.php`
- `src/Service/Core/ScheduledJobExecutionResult.php`
- `src/Service/Core/TaskJobExecutorService.php`
- `src/Command/ScheduledJobsExecuteDueCommand.php`
- `src/Controller/Api/V1/Admin/AdminScheduledJobRunnerController.php`
- `src/Entity/ScheduledJob.php`, `ScheduledJobRecipient.php`, `ScheduledJobRunnerSetting.php`, `ScheduledJobRunnerRun.php`
- `migrations/Version20260601000000.php` (canonical baseline; defines `scheduled_jobs` + `scheduled_job_reminders`)
- `migrations/Version20260605081254.php` (communication preferences + Docker runner schema/lookups/permission/routes)
