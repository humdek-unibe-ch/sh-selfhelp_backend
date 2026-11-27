# Admin Actions APIs

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-11-26.
Source of truth: Controllers, JSON schemas, route definitions, and exported types in this repository.

## Overview

Actions define what should happen after user input changes.

They are attached to:

- one data table
- one trigger type
- one JSON config payload

When matching input is saved or deleted, the action runtime expands that config into scheduled jobs.

## Trigger Types

Supported trigger types:

- `started`
- `finished`
- `updated`
- `deleted`

### When they fire

- `finished`: default create/submit save
- `updated`: update save when `saveData()` updates an existing row
- `deleted`: record deletion through `DataService::deleteData()`

## Create Action

**Endpoint:** `POST /cms-api/v1/admin/actions`

## Update Action

**Endpoint:** `PUT /cms-api/v1/admin/actions/{actionId}`

## Delete Action

**Endpoint:** `DELETE /cms-api/v1/admin/actions/{actionId}`

## Action Config Concepts

The action config schema is backward compatible with the legacy admin editor.

Important top-level flags:

- `randomize`
- `repeat`
- `repeat_until_date`
- `target_groups`
- `overwrite_variables`
- `clear_existing_jobs_for_action`
- `clear_existing_jobs_for_record_and_action`

## Blocks and Jobs

An action contains blocks.

Each block contains jobs.

Conditions can exist on:

- action
- block
- job
- reminder

If a condition fails, that level is skipped.

## Supported Job Types

### `add_group`

Schedules a task job that adds the target user to one or more groups.

### `remove_group`

Schedules a task job that removes the target user from one or more groups.

### `notification`

Schedules either:

- an email job when `notification.notification_types = email`
- a push notification job when `notification.notification_types = push_notification`

### `notification_with_reminder`

Creates the main notification plus reminder child jobs.

### `notification_with_reminder_for_diary`

Same as above, plus reminder validity window metadata used to clean reminders once the target form is completed.

### Any other `job_type`

Any other `job_type` is a **plugin job**: a job type that an *enabled* plugin offers to actions (a `scheduledJobs[]`
entry with `actionPlannable: true` in its manifest). See [Plugin Job Types](#plugin-job-types).

## Plugin Job Types

A plugin that does work after a record is saved (call a service, build a report, send a message of its own kind)
declares a job type in its manifest. The editor then offers the type as a job, builds the job's form from the type's
schema, and the runtime plans the job like any other. The job is a normal `scheduled_jobs` row; the plugin's job
handler runs it when it is due.

### List the types

**Endpoint:** `GET /cms-api/v1/admin/actions/plugin-job-types`

Permission `admin.action.read` (route `admin_actions_plugin_job_types_get`). Response schema:
`config/schemas/api/v1/responses/admin/actions/plugin_job_types.json`. Only types of **enabled** plugins that declare
`actionPlannable: true` are listed.

```json
{
  "status": 200,
  "data": [
    {
      "type": "example_note_job",
      "plugin_id": "example-plugin",
      "label": "Add a note to an item",
      "config_schema": {
        "type": "object",
        "properties": {
          "item_id": { "type": "integer", "title": "Item" },
          "note": { "type": "string", "title": "Note", "maxLength": 200, "format": "variable-text" }
        },
        "required": ["item_id"]
      },
      "resource_fields": { "item_id": "example_item" }
    }
  ]
}
```

- `type` is the value to put in the job's `job_type`.
- `config_schema` is a flat JSON Schema (`type: object`, `properties`, `required`) of the job's `plugin_config`. A
  string property with `format: variable-text` may hold `{{variables}}`.
- `resource_fields` maps a property of `plugin_config` to the resource type of the plugin item it holds. The editor
  may bind only an item they are allowed to view.

### Use a type in an action

Put the type in `job_type` and the values in `plugin_config`. The other fields (`job_name`, `schedule_time`,
conditions, repetition) are the same as for a core job.

```json
{
  "job_name": "Note for the item owner",
  "job_type": "example_note_job",
  "plugin_config": { "item_id": 12, "note": "Hello {{recipient.name}}" },
  "schedule_time": { "job_schedule_types": "immediately" }
}
```

### What is checked when the action is saved

`POST /admin/actions` and `PUT /admin/actions/{actionId}` check every job whose type is not a core type. Nothing is
stored when a check fails.

| Status | `error` | Meaning |
| --- | --- | --- |
| 400 | `unknown_job_type` | No enabled plugin offers the type. `data.job_type` and `data.path` name the job. |
| 400 | `invalid_plugin_job_config` | `plugin_config` breaks the schema of the type (wrong type, missing required property, a property the type does not declare). `data.path` is the first failing path, for example `blocks.0.jobs.0.plugin_config.priority`; `data.errors` lists all failures as `{ path, message }`. |
| 403 | `resource_not_bindable` | A property listed in `resource_fields` names a plugin item the person saving may not view. The superuser may bind any item. |

Core job types are not checked against plugins.

### What is planned when a record is saved

For each recipient the runtime plans one scheduled job whose `type` is the plugin's job type:

- `plugin_config` is copied from the action. A property with `format: variable-text` has its `{{variables}}` resolved
  (`{{recipient.*}}`, `{{record.<field>}}`, `{{system.*}}`); every other property is passed on as saved.
- The job's config also holds `record_id`, `data_table_id` and `trigger` (the trigger type that fired).
- Scheduling, repetition, target groups, the action, block and job conditions, and the cleanup flags work as for core
  jobs. A reminder is always a core job.
- If the plugin was disabled or removed after the action was saved, nothing is planned for the job; it never falls
  back to a core job.

The scheduler hands the job to the plugin's `PluginScheduledJobHandlerInterface` when it is due. A job that is due at
once wakes the scheduler, see [Scheduled Jobs and Action Runtime](../../developer/11-scheduled-jobs.md#wake-signal-immediate-jobs-start-at-once).

## Scheduling Options

Each job supports:

- `immediately`
- `on_fixed_datetime`
- `after_period`
- `after_period_on_day_at_time`

## Repetition

### Repeat

Use:

- `repeat: true`
- `repeater.occurrences`
- `repeater.frequency`
- optional `daysOfWeek`
- optional `daysOfMonth`

### Repeat Until Date

Use:

- `repeat_until_date: true`
- `repeater_until_date.deadline`
- `repeater_until_date.repeat_every`
- `repeater_until_date.frequency`
- optional `schedule_at`
- optional weekday/monthday restrictions

## Target Groups

If `target_groups` is enabled, the runtime resolves all users in `selected_target_groups` and schedules jobs for each user.

If it is disabled, the runtime targets the user who triggered the input save.

## Overwrite Variables

If `overwrite_variables` is enabled, selected form values can override scheduling inputs at runtime.

Supported overwrite variables:

- `send_after`
- `send_after_type`
- `send_on_day_at`
- `custom_time`
- `impersonate_user_code`

### `impersonate_user_code`

If present and valid, the runtime replaces the target recipient with the user owning that active validation code.

## Cleanup Flags

### `clear_existing_jobs_for_action`

Before new jobs are created, queued jobs for the same action and user are soft-deleted.

### `clear_existing_jobs_for_record_and_action`

Before new jobs are created, queued jobs for the same action and same data row are soft-deleted.

Useful for resubmits and edits where older queued jobs should not still fire.

## Example Config

```json
{
  "target_groups": false,
  "overwrite_variables": true,
  "selected_overwrite_variables": ["impersonate_user_code"],
  "clear_existing_jobs_for_record_and_action": true,
  "blocks": [
    {
      "block_name": "Welcome",
      "jobs": [
        {
          "job_name": "Send welcome mail",
          "job_type": "notification",
          "schedule_time": {
            "job_schedule_types": "immediately"
          },
          "notification": {
            "notification_types": "email",
            "from_email": "noreply@example.com",
            "from_name": "SelfHelp",
            "reply_to": "support@example.com",
            "recipient": "{{recipient.email}}",
            "subject": "Welcome",
            "body": "<p>Hello {{recipient.name}}</p>",
            "attachments": []
          }
        }
      ]
    }
  ]
}
```

### Email attachments

`notification.attachments` (also in the notification of a reminder) is a list
of **assets**, each given as the asset's delivery URL
(`/cms-api/v1/assets/<uuid>`; the frontend form `/api/assets/<uuid>` and an
absolute URL naming the same asset are accepted). Blank entries are ignored.
The check happens when the action is saved (`POST` and `PUT`), against the
person saving it, because the action later runs with system rights:

- a server file path, an external URL or anything else that is not an
  uploaded asset is refused with `400`;
- an asset that does not exist is refused with `400`;
- an asset the person saving may not view (the asset's groups, as on the
  website) is refused with `403`. The admin role may attach any asset.

Nothing is stored when a save is refused. When the mail is sent, only assets
resolve to a file: a server path in a stored config is skipped (with a warning
in the log) and the mail still goes out. Each attachment is named after its
asset uuid plus the extension of its mime type. The view right is checked at
save time only; restricting the asset's groups later does not change actions
that already name it.

### Template placeholders

Notification subject/body/recipient fields use Mustache `{{...}}` placeholders
only — the legacy at-style action placeholders are no longer supported.
Per-recipient scopes are rendered during fan-out:

- `{{recipient.email}}`, `{{recipient.name}}`, `{{recipient.user_name}}`,
  `{{recipient.code}}`, `{{recipient.timezone}}`
- `{{record.field_name}}` for submitted/source-row values
- `{{system.project_name}}`, `{{system.platform_url}}`

## End-User / Admin Usage Notes

When configuring actions:

1. choose the right trigger first
2. use conditions to narrow execution
3. use target groups carefully, because one save can schedule many jobs
4. use cleanup flags when an update should replace old queued jobs
5. use reminders only when the target form really exists and will be completed later

## How to Verify Your Action Worked

After saving input:

1. open scheduled jobs admin list
2. filter by action, user, or source row
3. inspect the created job config
4. manually execute a queued job if needed
5. inspect job transactions

## Troubleshooting

### Nothing happens after save

Check:

- action is attached to the correct data table
- trigger type matches the save path
- conditions are not failing
- recipients resolve to real users
- overwrite variables are present when required

### Jobs are created for the wrong user

Check:

- target groups
- `impersonate_user_code`
- the `id_users` stored in the saved form row

### Reminder jobs stay queued even after form completion

Check:

- reminder target form id matches the completed form data table
- reminder job is still in the valid window

---

**Next:** [Frontend Pages](./15-frontend-pages.md) | **Previous:** [Admin Scheduled Jobs](./13-admin-scheduled-jobs.md) | **Back to:** [API Overview](../../README.md)
