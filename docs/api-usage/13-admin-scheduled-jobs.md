# Admin Scheduled Jobs APIs

## Overview

Scheduled jobs are concrete queue entries created by actions or by direct system scheduling.

Admins can:

- inspect queued jobs
- inspect execution history
- execute a job manually
- cancel a queued job
- mark a job as deleted

## Common Concepts

### Status values

- `Queued`
- `Running`
- `Done`
- `Failed`
- `Cancelled`
- `Deleted`

### Job types

- `Email`
- `Notification`
- `Task`

### Action-linked jobs

Jobs created from action execution keep links to:

- action
- source data table
- source data row
- parent scheduled job for reminders

## Get Scheduled Jobs

**Endpoint:** `GET /cms-api/v1/admin/scheduled-jobs`

### Query parameters

- `page`
- `pageSize`
- `search`
- `status`
- `jobType`
- `dateFrom`
- `dateTo`
- `dateType`
- `sort`
- `sortDirection`

### Example

```http
GET /cms-api/v1/admin/scheduled-jobs?page=1&pageSize=20&status=queued&jobType=email
Authorization: Bearer <token>
```

### Response shape

Returns:

- `scheduledJobs`
- `totalCount`
- `page`
- `pageSize`
- `totalPages`

Each job includes:

- `id`
- `id_users`
- `user_email`
- `action_name`
- `data_table_name`
- `data_row`
- `job_types`
- `status`
- `description`
- `date_scheduled`
- `date_created`
- `date_to_be_executed`
- `date_executed`
- `config`

## Get One Scheduled Job

**Endpoint:** `GET /cms-api/v1/admin/scheduled-jobs/{jobId}`

Use this to inspect the full job payload and transaction history.

## Execute a Scheduled Job

**Endpoint:** `POST /cms-api/v1/admin/scheduled-jobs/{jobId}/execute`

This runs the selected job immediately through `JobSchedulerService`.

Supported execution types:

- email jobs
- push notification jobs
- task jobs

### Example

```http
POST /cms-api/v1/admin/scheduled-jobs/123/execute
Authorization: Bearer <token>
```

## Cancel a Scheduled Job

**Endpoint:** `POST /cms-api/v1/admin/scheduled-jobs/{jobId}/cancel`

Use this for queued jobs that should no longer run.

Jobs that are already `running`, `done`, or `failed` cannot be cancelled.

## Delete a Scheduled Job

**Endpoint:** `DELETE /cms-api/v1/admin/scheduled-jobs/{jobId}`

This is a soft delete. The row is kept for audit purposes and the status changes to `deleted`.

## Get Job Transactions

**Endpoint:** `GET /cms-api/v1/admin/scheduled-jobs/{jobId}/transactions`

This shows the audit log for:

- creation
- cancellation
- deletion
- execution result
- mail send result
- notification send result
- task execution result

## Manual Execution Outside the API

The same scheduler can also be driven from console commands:

```bash
php bin/console app:scheduled-jobs:execute-due
php bin/console app:scheduled-jobs:execute-one 123
```

## How to Read Job Config

The `config` payload usually contains some combination of:

- `email`
- `notification`
- `task`
- `condition`
- `schedule`
- `action_job_type`

### Examples

Email job:

```json
{
  "email": {
    "recipient_emails": "user@example.com",
    "subject": "Welcome",
    "body": "<p>Hello</p>"
  },
  "schedule": {
    "job_schedule_types": "immediately"
  }
}
```

Task job:

```json
{
  "task": {
    "task_type": "add_group",
    "groups": ["subject"]
  }
}
```

## Troubleshooting

### Job executes and fails

Check:

- email recipients exist
- notification target user has `device_token`
- Firebase config exists in CMS preferences
- task groups exist
- on-execute condition still evaluates to true

### Job never executes automatically

Check:

- `date_to_be_executed`
- cron for `app:scheduled-jobs:execute-due`
- job status is still `queued`

---

**Next:** [Admin Actions](./14-admin-actions.md) | **Previous:** [Admin Audit](./12-admin-audit.md) | **Back to:** [API Overview](../README.md)
