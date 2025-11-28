# Admin Scheduled Jobs APIs

## Overview

The Admin Scheduled Jobs APIs provide functionality for managing and monitoring background jobs and automated tasks in the SelfHelp CMS. These endpoints allow administrators to view job status, execute jobs manually, and monitor job execution history.

## Core Concepts

### Scheduled Jobs
- **Background Tasks**: Automated maintenance and processing tasks
- **Job Execution**: Manual or scheduled job triggering
- **Job Monitoring**: Real-time status tracking and logging
- **Job History**: Complete execution history and transaction logs

## Job Management

### Get Scheduled Jobs

Retrieve a list of all scheduled jobs with their current status.

**Endpoint:** `GET /cms-api/v1/admin/scheduled-jobs`

**Authentication:** Required (JWT Bearer token)

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "jobs": [
      {
        "id": 1,
        "name": "cache_cleanup",
        "description": "Clean up expired cache entries",
        "status": "idle",
        "last_execution": "2025-01-22T02:00:00Z",
        "next_execution": "2025-01-23T02:00:00Z",
        "execution_count": 45,
        "success_count": 44,
        "failure_count": 1,
        "average_runtime": 125000
      }
    ]
  }
}
```

**Permissions:** `admin.scheduled_job.read`

### Get Job Details

Retrieve detailed information about a specific scheduled job.

**Endpoint:** `GET /cms-api/v1/admin/scheduled-jobs/{jobId}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `jobId`: Job ID

**Response:** Detailed job information including configuration and history

**Permissions:** `admin.scheduled_job.read`

### Execute Job Manually

Trigger immediate execution of a scheduled job.

**Endpoint:** `POST /cms-api/v1/admin/scheduled-jobs/{jobId}/execute`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `jobId`: Job ID

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "job_id": 1,
    "execution_id": "exec_12345",
    "status": "running",
    "started_at": "2025-01-23T10:30:00Z"
  }
}
```

**Permissions:** `admin.scheduled_job.execute`

### Cancel Running Job

Cancel execution of a currently running job.

**Endpoint:** `POST /cms-api/v1/admin/scheduled-jobs/{jobId}/cancel`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `jobId`: Job ID

**Response:** Job cancellation confirmation

**Permissions:** `admin.scheduled_job.cancel`

### Delete Job

Remove a scheduled job from the system.

**Endpoint:** `DELETE /cms-api/v1/admin/scheduled-jobs/{jobId}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `jobId`: Job ID

**Response:** Job deletion confirmation

**Permissions:** `admin.scheduled_job.delete`

### Get Job Transactions

Retrieve execution history and transaction logs for a job.

**Endpoint:** `GET /cms-api/v1/admin/scheduled-jobs/{jobId}/transactions`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `jobId`: Job ID

**Response:** Array of job execution records with details

**Permissions:** `admin.scheduled_job.read`

---

**Next:** [Admin Actions](./14-admin-actions.md) | **Previous:** [Admin Audit](./12-admin-audit.md) | **Back to:** [API Overview](../README.md)
