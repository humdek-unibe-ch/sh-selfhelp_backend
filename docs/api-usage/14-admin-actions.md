# Admin Actions APIs

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
            "recipient": "@user",
            "subject": "Welcome",
            "body": "<p>Hello @user_name</p>",
            "attachments": []
          }
        }
      ]
    }
  ]
}
```

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

**Next:** [Frontend Pages](./15-frontend-pages.md) | **Previous:** [Admin Scheduled Jobs](./13-admin-scheduled-jobs.md) | **Back to:** [API Overview](../README.md)
