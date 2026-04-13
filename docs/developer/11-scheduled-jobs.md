# Scheduled Jobs and Action Runtime

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

Reminder-only metadata now lives in the dedicated `scheduledJobs_reminders` table and `ScheduledJobReminder` entity.

That reminder metadata stores:

- owning scheduled job
- parent scheduled job
- reminder target data table
- reminder session start
- reminder session end

This keeps reminder-specific state out of the main `scheduledJobs` table while still supporting cleanup and lineage queries.

## Database Migration

The reminder metadata refactor is applied through Symfony/Doctrine migrations.

### Upgrade the database

Run the pending migrations:

```bash
php bin/console doctrine:migrations:migrate
```

This will:

- create `scheduledJobs_reminders`
- add the reminder foreign keys and indexes
- remove obsolete reminder columns from `scheduledJobs` when they exist

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

The reminder metadata migration is:

- `migrations/Version20260413130000.php`

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

### Cron example

Run every minute:

```cron
* * * * * cd /path/to/sh-selfhelp_backend && php bin/console app:scheduled-jobs:execute-due --env=prod >> var/log/scheduled-jobs.log 2>&1
```

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
- `src/Service/Core/JobSchedulerService.php`
- `src/Service/Core/TaskJobExecutorService.php`
- `src/Entity/ScheduledJob.php`
- `migrations/Version20260413130000.php`
