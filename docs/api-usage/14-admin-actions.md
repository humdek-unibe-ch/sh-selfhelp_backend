# Admin Actions APIs

## Overview

The Admin Actions APIs provide functionality for managing automated actions and triggers within the SelfHelp CMS. Actions allow administrators to set up automated responses to various events and conditions.

## Core Concepts

### Actions
- **Automated Tasks**: Event-driven or scheduled automated operations
- **Triggers**: Conditions that initiate action execution
- **Configurations**: Customizable action parameters and settings
- **Action Types**: Different categories of automated actions

## Action Management

### Get Actions

Retrieve a list of all configured actions.

**Endpoint:** `GET /cms-api/v1/admin/actions`

**Authentication:** Required (JWT Bearer token)

**Query Parameters:**
- `page`: Page number
- `pageSize`: Items per page
- `search`: Search term
- `sort`: Sort field
- `sortDirection`: Sort direction

**Response:** Paginated list of actions

**Permissions:** `admin.action.read`

### Create Action

Create a new automated action.

**Endpoint:** `POST /cms-api/v1/admin/actions`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "name": "user_welcome_email",
  "id_actionTriggerTypes": 1,
  "config": {
    "template_id": 5,
    "delay_hours": 24
  }
}
```

**Response:** Created action details

**Permissions:** `admin.action.update`

### Update Action

Modify an existing action's configuration.

**Endpoint:** `PUT /cms-api/v1/admin/actions/{actionId}`

**Authentication:** Required (JWT Bearer token)

**Request Body:** Updated action configuration

**Response:** Updated action details

**Permissions:** `admin.action.update`

### Delete Action

Remove an action from the system.

**Endpoint:** `DELETE /cms-api/v1/admin/actions/{actionId}`

**Authentication:** Required (JWT Bearer token)

**Response:** Deletion confirmation

**Permissions:** `admin.action.delete`

### Get Action Translations

Retrieve translations for action-related content.

**Endpoint:** `GET /cms-api/v1/admin/actions/{actionId}/translations`

**Authentication:** Required (JWT Bearer token)

**Query Parameters:**
- `language_id`: Specific language filter

**Response:** Action translations by language

**Permissions:** `admin.action_translation.read`

---

**Next:** [Frontend Pages](./15-frontend-pages.md) | **Previous:** [Admin Scheduled Jobs](./13-admin-scheduled-jobs.md) | **Back to:** [API Overview](../README.md)
