# Admin Users, Groups & Roles APIs

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-11-09.
Source of truth: Controllers, JSON schemas, route definitions, and exported types in this repository.

## Overview

The User Management APIs provide comprehensive functionality for managing users, groups, and roles within the SelfHelp CMS. This includes user CRUD operations, group membership management, role assignment, and fine-grained access control through permissions and data access rules.

## Core Concepts

### Users
- **User Accounts**: System users with authentication credentials
- **User Types**: Different categories of users (admin, regular, etc.)
- **User Status**: Active, blocked, or validation pending
- **Profile Information**: Name, email, language preferences

### Groups
- **User Groups**: Collections of users with shared permissions
- **Groups only decide who sees**: a page or an asset is visible to the groups
  it is shared with; everything a person may *do* comes from roles and
  permissions. There is no group type.
- **Default groups**: a group flagged `add_to_new_pages` gets `select` on every
  new public page. A fresh install has one group, `subject`, with the flag set;
  any other group is custom.
- **Group Membership**: Users can belong to multiple groups
- **Access Control**: Group-based permission inheritance

### Roles
- **System Roles**: Named sets of permissions (admin, editor, etc.)
- **Role Permissions**: Specific capabilities granted by roles
- **Role Assignment**: Users can have multiple roles
- **Permission Inheritance**: Users inherit permissions from all their roles

### Data Access Control
- **Custom Permissions**: Fine-grained access rules per resource
- **Resource Types**: Different types of system resources
- **CRUD Permissions**: Create, Read, Update, Delete access levels

## User Management

### Get Users

Retrieve a paginated list of users with filtering and sorting options.

**Endpoint:** `GET /cms-api/v1/admin/users`

**Authentication:** Required (JWT Bearer token)

**Query Parameters:**
- `page`: Page number (default: 1)
- `pageSize`: Items per page (default: 20)
- `search`: Search term for name/email filtering
- `sort`: Sort field ('id', 'email', 'name', 'created_at', etc.)
- `sortDirection`: Sort direction ('asc' or 'desc')
- `status`: `active`, `invited`, `imported` or `blocked` (anything else is a 400)
- `id_groups`: Only members of this group
- `id_roles`: Only users holding this role (Users page role filter and the Groups/Roles "View members" dialogs)

The CSV export (`GET /admin/users/export`) takes the same `search`, `status`, `id_groups` and `id_roles` filters.

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
    "users": [
      {
        "id": 1,
        "email": "admin@selfhelp.test",
        "name": "System Administrator",
        "user_name": "admin",
        "user_type_id": 1,
        "blocked": false,
        "id_languages": 1,
        "language_locale": "en",
        "last_login": "2025-01-23T09:15:00Z",
        "created_at": "2024-01-15T10:30:00Z",
        "updated_at": "2025-01-20T14:45:00Z",
        "groups": [
          {
            "id": 1,
            "name": "admin",
            "description": "Administrator group"
          }
        ],
        "roles": [
          {
            "id": 1,
            "name": "admin",
            "description": "Administrator role"
          }
        ]
      }
    ],
    "total": 150,
    "page": 1,
    "pageSize": 20,
    "totalPages": 8
  }
}
```

**Permissions:** `admin.user.read`

### Get Single User

Retrieve detailed information about a specific user.

**Endpoint:** `GET /cms-api/v1/admin/users/{userId}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Response:** Same format as user list but single user object

**Permissions:** `admin.user.read` (with group-based access control)

### Create User

Create a new user account.

**Endpoint:** `POST /cms-api/v1/admin/users`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
[View JSON Schema](../../../config/schemas/api/v1/requests/admin/create_user.json)
```json
{
  "email": "newuser@example.com",
  "name": "New User",
  "user_name": "newuser",
  "password": "SecurePassword123!",
  "user_type_id": 2,
  "blocked": false,
  "id_languages": 1,
  "validation_code": "abc123def456",
  "group_ids": [2, 3],
  "role_ids": [2]
}
```

**Field Descriptions:**
- `email`: User's email address (required, unique)
- `name`: Display name (optional)
- `user_name`: Username (optional, unique)
- `password`: Plain text password (optional, will be hashed)
- `user_type_id`: User type identifier (optional)
- `blocked`: Account blocked status (default: false)
- `id_languages`: Preferred language ID (optional)
- `validation_code`: Email validation code (required)
- `group_ids`: Array of group IDs to assign (optional)
- `role_ids`: Array of role IDs to assign (optional)

**Response:**
```json
{
  "status": 201,
  "message": "Created",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "user": {
      "id": 3,
      "email": "newuser@example.com",
      "name": "New User",
      "user_name": "newuser",
      "user_type_id": 2,
      "blocked": false,
      "id_languages": 1,
      "language_locale": "en",
      "created_at": "2025-01-23T10:30:00Z",
      "updated_at": "2025-01-23T10:30:00Z",
      "groups": [...],
      "roles": [...]
    }
  }
}
```

**Permissions:** `admin.user.create`

### Update User

Modify an existing user's information.

**Endpoint:** `PUT /cms-api/v1/admin/users/{userId}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID to update

**Request Body:** Same as create, but all fields optional
[View JSON Schema](../../../config/schemas/api/v1/requests/admin/update_user.json)

**Response:** Updated user data

**Permissions:** `admin.user.update`

### Delete User

Remove a user account from the system.

**Endpoint:** `DELETE /cms-api/v1/admin/users/{userId}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID to delete

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
    "deleted": true
  }
}
```

**Permissions:** `admin.user.delete` (route) and DELETE access to the user.

Deleting a user removes what they produced first, in the same transaction, with
the same function as [Clean User Data](#clean-user-data): their data rows and
cells, scheduled jobs and recipient rows, and request-log rows; uploaded form
files go after the commit. The `transactions` and `data_access_audits` rows are
**kept** and lose only the link to the user (`id_users` becomes NULL), so
deleting a user who has audit rows works and the history survives. System users
cannot be deleted (`403`).

### Block/Unblock User

Toggle a user's blocked status.

**Endpoint:** `PATCH /cms-api/v1/admin/users/{userId}/block`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Request Body:**
```json
{
  "blocked": true
}
```

**Response:** Updated user data with new blocked status

**Permissions:** `admin.user.block` or `admin.user.unblock`

### Stats, bulk actions and CSV

| Endpoint | Permission | Notes |
| --- | --- | --- |
| `GET /admin/users/stats` | `admin.user.read` | `{ total, active, invited, imported, blocked }` over the caller's visible users. `blocked` wins over status; the counts do not have to sum to `total`. |
| `POST /admin/users/bulk-delete` | `admin.user.delete` | Body `{ user_ids }` (1-500). Refuses the caller's own account. |
| `POST /admin/users/bulk-add-to-group` | `admin.user.update` | Body `{ user_ids, group_ids }` (each 1-500). Unknown groups fail the request. The caller needs UPDATE on **every listed group** (otherwise `403` for the whole request) and UPDATE on each user (reported per user). |
| `POST /admin/users/bulk-remove-from-group` | `admin.user.update` | Same body and rights as add. |
| `POST /admin/users/bulk-send-activation` | `admin.user.update` | Body `{ user_ids }`. Already active users are reported as failures. |
| `GET /admin/users/export` | `admin.user.read` | CSV of the filtered list. Cells starting with `=`, `+`, `-`, `@`, tab or CR are prefixed with `'` so spreadsheets do not run them. |
| `POST /admin/users/import` | `admin.user.create` | Multipart `file` (`.csv`, header `email,name,user_name,groups`; groups `;`-separated by name). New users get the `imported` status and no activation mail; existing emails are skipped. Every named group needs CREATE access for the caller, as for a single create, otherwise that row fails. |

Bulk actions return `{ succeeded: [ids], failed: [{ id, reason }] }`; import returns `{ imported, skipped, errors: [{ row, message }] }`.

## User Group Management

### Get User Groups

Retrieve all groups a user belongs to.

**Endpoint:** `GET /cms-api/v1/admin/users/{userId}/groups`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

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
    "user_id": 1,
    "groups": [
      {
        "id": 1,
        "name": "subject",
        "description": "Study participants",
        "add_to_new_pages": true,
        "requires_2fa": false,
        "created_at": "2024-01-15T10:30:00Z",
        "updated_at": "2024-01-20T14:45:00Z"
      }
    ]
  }
}
```

**Permissions:** `admin.user.read`

### Add Groups to User

Assign additional groups to a user.

**Endpoint:** `POST /cms-api/v1/admin/users/{userId}/groups`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Request Body:**
```json
{
  "group_ids": [2, 3, 4]
}
```

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
    "user_id": 1,
    "added_groups": [2, 3, 4],
    "groups": [...]
  }
}
```

**Permissions:** `admin.user.update` (route), plus UPDATE access to the user
**and** UPDATE access to each group in `group_ids`: a grant on one group never
lets a caller move people in or out of another (`403`, audited). The admin role
passes. The same rule applies to removing groups, to `group_ids` on create and
update (for update the rights are needed for the groups that are added or
dropped, not for the ones that stay; creating a user in a group needs CREATE on
it) and to the CSV import.

### Remove Groups from User

Remove group assignments from a user.

**Endpoint:** `DELETE /cms-api/v1/admin/users/{userId}/groups`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Request Body:**
```json
{
  "group_ids": [2, 3]
}
```

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
    "user_id": 1,
    "removed_groups": [2, 3],
    "groups": [...]
  }
}
```

**Permissions:** `admin.user.update`

## User Role Management

### Get User Roles

Retrieve all roles assigned to a user.

**Endpoint:** `GET /cms-api/v1/admin/users/{userId}/roles`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

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
    "user_id": 1,
    "roles": [
      {
        "id": 1,
        "name": "admin",
        "description": "Administrator role",
        "created_at": "2024-01-15T10:30:00Z",
        "updated_at": "2024-01-20T14:45:00Z"
      }
    ]
  }
}
```

**Permissions:** `admin.user.read`

### Add Roles to User

Assign additional roles to a user.

**Endpoint:** `POST /cms-api/v1/admin/users/{userId}/roles`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Request Body:**
```json
{
  "role_ids": [2, 3]
}
```

**Response:** Similar to add groups response

**Permissions:** `admin.user.update` (route), plus UPDATE access to the user.
Roles edit, so handing out a role hands out its rights: a caller that is not
admin may add or remove **only roles it holds itself** (`403` otherwise, naming
the role), which stops a scoped admin from granting more than it has, or the
`admin` role. The admin role may assign any role. The same rule applies to
removing roles and to `role_ids` on create and update (for update it is checked
on the roles that are added or dropped, not on the ones that stay).

### Remove Roles from User

Remove role assignments from a user.

**Endpoint:** `DELETE /cms-api/v1/admin/users/{userId}/roles`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**Request Body:**
```json
{
  "role_ids": [2]
}
```

**Response:** Similar to remove groups response

**Permissions:** `admin.user.update`

## Additional User Operations

### Send Activation Email

Resend account activation email to a user.

**Endpoint:** `POST /cms-api/v1/admin/users/{userId}/send-activation-mail`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

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
    "message": "Activation email sent successfully"
  }
}
```

**Permissions:** `admin.user.update`

### Clean User Data

Erase the content a user produced while **keeping the account itself**. This is
the GDPR "erase what I produced, keep the (inactive) account" path — it is
deliberately not [Delete User](#delete-user).

**Endpoint:** `POST /cms-api/v1/admin/users/{userId}/clean-data`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `userId`: User ID

**What is erased, and what stays**

The erasure is one function, `UserDataCleanerService`; [Delete User](#delete-user),
the bulk delete and the user's own account deletion call the same one, so no row
is left pointing at a user that no longer exists.

| Entity | Result | Note |
| --- | --- | --- |
| `DataRow` + `DataCell` | Erased | All form/entry input the user submitted. |
| `ScheduledJob` | Erased | Jobs owned by the user; their recipients and reminder metadata cascade. |
| `ScheduledJobRecipient` | Erased | Also rows on *other users'* jobs that name this user as a recipient — a record of what this user was sent. |
| `ApiRequestLog` | Erased | The request log stores URLs and parameters that can carry personal data. It has no foreign key to users. |
| Form files | Erased | Every file the user uploaded through forms (`var/form-files/user_<id>/`). Deleted after the database transaction commits, because files cannot roll back. |
| `Transaction` | **Kept, person removed** | The audit trail of what changed: the row stays and `id_users` is set to NULL. The admin Users list "Activity" column drops to 0. |
| `DataAccessAudit` | **Kept, person removed** | The audit trail of who accessed or was refused what: same, `id_users` is set to NULL. |
| Session/auth state | Not touched | `RefreshToken`, `Users2faCode`, `ValidationCode`, `UserNavigationState` are not user-produced content; they die with the account or expire. |
| `PageView` / `PageViewReferrer` | n/a | Pseudonymous daily aggregates with no `id_users`; nothing per-user to erase. |
| Tables a plugin owns | Erased by the plugin | The plugin listens to `UserDataCleanupEvent`, dispatched inside the transaction, and deletes its own rows; its files go after the commit. See the [plugin developer guide](../../plugins/developer-guide.md#9-cms-extension-points). The `removed` counts below list core rows only. |

The database part runs in a single transaction — a failure at any point rolls
it back (a plugin that fails stops the whole erasure), and no file is touched.
It writes its own audit entry **after** the wipe (attributed to the acting
admin, not to the user), so the erasure itself stays recorded.

**Two implementation notes** (both have previously caused false worries):

- `transactions.id_users` is the **acting** user, not the subject —
  `TransactionService::logTransaction()` fills it from `getCurrentUser()`. So
  what the user *did* loses its author; an admin action performed *on* them
  (block, role change, impersonation) is attributed to that admin and keeps its
  author. Both audit tables now have `ON DELETE SET NULL` on `id_users`, and the
  cleaner clears the link itself because this action keeps the account, so the
  foreign key never fires.
- **There is no soft-delete to worry about here.** `data_rows` / `data_cells`
  carry no `id_users_deleted` column and no soft-delete flag — that column does
  not exist anywhere in the backend. All deletes on this path are hard deletes.
  `DataRow.id_users` is a plain nullable integer column (not a Doctrine
  association), which is why row ownership is queried as `dr.idUsers`.

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
    "cleaned": true,
    "removed": {
      "scheduled_job_recipients": 2,
      "scheduled_jobs": 3,
      "data_cells": 18,
      "data_rows": 7,
      "transactions": 42,
      "data_access_audits": 9,
      "api_request_logs": 120,
      "form_files": 5
    }
  }
}
```

`removed` reports a count per kind, every key always present
([schema](../../../config/schemas/api/v1/responses/admin/users/clean_user_data.json)).
`form_files` counts files; for `transactions` and `data_access_audits` the count
is the rows **detached from the user** (they are kept), not deleted.

**Permissions:** `admin.user.update` (route). The service additionally requires
`PERMISSION_DELETE` on the target user, and refuses system users (`admin`,
`tpf`) with `403`. The route is blocked under an impersonation token.

### Impersonate User

Issue a short-lived JWT that lets an admin act as another user for
debugging or QA. The token follows RFC 8693 *OAuth 2.0 Token Exchange*:
the target user is the effective principal (`id_users`) while the
original admin is preserved as the *actor* in the `act` claim.

**Endpoint:** `POST /cms-api/v1/admin/users/{userId}/impersonate`

**Authentication:** Required (JWT Bearer token, real admin session — must NOT
already be impersonating).

**Permissions:** `admin.user.impersonate`

**Path parameters:**

- `userId`: id of the user to impersonate.

**Response:**

```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": { "version": "v1", "timestamp": "2026-05-08T12:00:00Z" },
  "data": {
    "impersonation_token": "eyJ0eXAi...",
    "expires_in": 900,
    "target_email": "alice@example.com"
  }
}
```

**Token shape (decoded payload):**

```json
{
  "id_users": 42,
  "act": { "sub": "1", "id_users": 1 },
  "impersonation": true,
  "exp": 1715170800
}
```

**Validation rules (deny-by-default):**

- The target must exist and not be a system user (`admin`, `tpf`).
- The target must not be blocked.
- An admin cannot impersonate themselves.

**Authorisation rules under an impersonation token:**

- *Read* requests run as the target — the admin sees what the user sees.
- *Mutations* are allowed and are recorded in the `transactions` table
  with a verbose entry attributing the action to the **original admin**
  (`act.sub`). This matches industry practice (Salesforce "Login as",
  Auth0 "View as", GitHub Enterprise impersonation, Symfony's own
  `switch_user`).
- A small high-risk allow list is **never** permitted under an
  impersonation token: starting another impersonation, deleting users,
  granting/revoking roles or groups, blocking/unblocking, and cleaning
  user data. The listener returns 403 with a clear message and the
  admin must call `stop-impersonate` first.

**Storage on the BFF / browser side:**

The Next.js BFF route `/api/admin/users/{id}/impersonate` strips the
JWT from this response and parks it in an httpOnly cookie
(`sh_impersonate`). It also sets a non-httpOnly hint cookie
(`sh_impersonate_target_email`) carrying just the email so the React
banner can render. The browser **never** sees the JWT.

### Stop impersonating

Blacklist the impersonation JWT so it cannot be replayed even if it
leaked, and write an audit entry against the original admin.

**Endpoint:** `POST /cms-api/v1/admin/users/stop-impersonate`

**Authentication:** Required (JWT Bearer token — the impersonation token
itself is sent as `Authorization`).

**Permissions:** None — the route is gated by JWT authentication only.
This is intentional: the request executes as the target user, who
typically does NOT have `admin.user.impersonate`. The controller checks
for the `impersonation: true` flag and returns `{stopped: false}` if
the caller is on a regular access token.

**Response (active session):**

```json
{
  "status": 200, "message": "OK", "error": null, "logged_in": true,
  "meta": { "version": "v1", "timestamp": "2026-05-08T12:00:30Z" },
  "data": { "stopped": true }
}
```

**Response (no active impersonation):**

```json
{ "status": 200, "data": { "stopped": false } }
```

The BFF route `/api/admin/users/stop-impersonate` clears both
impersonation cookies regardless of the upstream outcome so the admin
is never trapped in a stale state.

## Group Management

### Get Groups

Retrieve a list of all user groups.

**Endpoint:** `GET /cms-api/v1/admin/groups`

**Authentication:** Required (JWT Bearer token)

**Query Parameters:** Same as users (page, pageSize, search, sort, sortDirection)

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
    "groups": [
      {
        "id": 1,
        "name": "subject",
        "description": "Study participants",
        "add_to_new_pages": true,
        "requires_2fa": false,
        "acls": [...],
        "created_at": "2024-01-15T10:30:00Z",
        "updated_at": "2024-01-20T14:45:00Z",
        "user_count": 5
      }
    ],
    "total": 10,
    "page": 1,
    "pageSize": 20,
    "totalPages": 1
  }
}
```

**Permissions:** `admin.group.read`

### Create Group

Create a new user group.

**Endpoint:** `POST /cms-api/v1/admin/groups`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "name": "editors",
  "description": "Content editors",
  "add_to_new_pages": false,
  "requires_2fa": false,
  "acls": [
    {
      "resource_type": "page",
      "resource_id": null,
      "permissions": 6  // Read + Update
    }
  ]
}
```

**Permissions:** `admin.group.create`

### Update Group

Modify group properties and ACLs.

**Endpoint:** `PUT /cms-api/v1/admin/groups/{groupId}`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.group.update`

### Delete Group

Remove a group (users must be reassigned).

**Endpoint:** `DELETE /cms-api/v1/admin/groups/{groupId}`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.group.delete`

### Get Group ACLs

Retrieve access control lists for a group.

**Endpoint:** `GET /cms-api/v1/admin/groups/{groupId}/acls`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.group.read`

### Update Group ACLs

Modify group access control permissions.

**Endpoint:** `PUT /cms-api/v1/admin/groups/{groupId}/acls`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "acls": [
    {
      "resource_type": "page",
      "resource_id": 123,
      "permissions": 15  // All permissions
    }
  ]
}
```

**Permissions:** `admin.group.acl`

## Role Management

### Get Roles

Retrieve all available roles.

**Endpoint:** `GET /cms-api/v1/admin/roles`

**Authentication:** Required (JWT Bearer token)

**Query Parameters:** Same pagination parameters as users

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
    "roles": [
      {
        "id": 1,
        "name": "admin",
        "description": "Full system access",
        "permissions": [
          {
            "id": 1,
            "name": "admin.access",
            "description": "Can access admin area"
          }
        ],
        "created_at": "2024-01-15T10:30:00Z",
        "updated_at": "2024-01-20T14:45:00Z"
      }
    ],
    "total": 5,
    "page": 1,
    "pageSize": 20,
    "totalPages": 1
  }
}
```

**Permissions:** `admin.role.read`

### Create Role

Create a new role with assigned permissions.

**Endpoint:** `POST /cms-api/v1/admin/roles`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "name": "editor",
  "description": "Content editor",
  "permission_ids": [2, 3, 4, 5]
}
```

**Permissions:** `admin.role.create`

### Update Role

Modify role properties and permissions.

**Endpoint:** `PUT /cms-api/v1/admin/roles/{roleId}`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.role.update`

### Delete Role

Remove a role from the system.

**Endpoint:** `DELETE /cms-api/v1/admin/roles/{roleId}`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.role.delete`

### Get Role Permissions

Retrieve permissions assigned to a specific role.

**Endpoint:** `GET /cms-api/v1/admin/roles/{roleId}/permissions`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.role.read`

### Add Permissions to Role

Assign additional permissions to a role.

**Endpoint:** `POST /cms-api/v1/admin/roles/{roleId}/permissions`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "permission_ids": [6, 7, 8]
}
```

**Permissions:** `admin.role.permissions`

### Remove Permissions from Role

Remove permissions from a role.

**Endpoint:** `DELETE /cms-api/v1/admin/roles/{roleId}/permissions`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "permission_ids": [6, 7]
}
```

**Permissions:** `admin.role.permissions`

### Update Role Permissions (Bulk)

Replace all permissions for a role.

**Endpoint:** `PUT /cms-api/v1/admin/roles/{roleId}/permissions`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "permission_ids": [2, 3, 4, 5, 9, 10]
}
```

**Permissions:** `admin.role.permissions`

## Permission Management

### Get All Permissions

Retrieve the complete list of available permissions.

**Endpoint:** `GET /cms-api/v1/admin/permissions`

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
    "permissions": [
      {
        "id": 1,
        "name": "admin.access",
        "description": "Can view and enter the admin/backend area"
      },
      {
        "id": 2,
        "name": "admin.page.read",
        "description": "Can read existing pages"
      }
    ]
  }
}
```

**Permissions:** `admin.permission.read`

## Data Access Management

### Get Roles with Permissions

Retrieve roles and their custom data access permissions.

**Endpoint:** `GET /cms-api/v1/admin/data-access/roles`

**Authentication:** Required (JWT Bearer token)

**Response:** Roles with their effective permissions

**Permissions:** `admin.role.read`

### Set Role Permissions

Set custom data access permissions for a role.

**Endpoint:** `POST /cms-api/v1/admin/data-access/roles/{roleId}/permissions`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "permissions": [
    {
      "resource_type": "page",
      "resource_id": 123,
      "crud_permissions": 15
    }
  ]
}
```

**Permissions:** `admin.role.update`

### Get Role Effective Permissions

Retrieve effective permissions for a role including inherited permissions.

**Endpoint:** `GET /cms-api/v1/admin/data-access/roles/{roleId}/effective-permissions`

**Authentication:** Required (JWT Bearer token)

**Permissions:** `admin.role.read`

## Frontend Integration Examples

### User Management Dashboard

```javascript
const UserManagement = () => {
  const [users, setUsers] = useState([]);
  const [selectedUser, setSelectedUser] = useState(null);
  const [isEditing, setIsEditing] = useState(false);

  useEffect(() => {
    loadUsers();
  }, []);

  const loadUsers = async (page = 1, search = '') => {
    try {
      const params = new URLSearchParams({ page, pageSize: 20, search });
      const response = await apiRequest(`/admin/users?${params}`);
      setUsers(response.data.users);
    } catch (error) {
      console.error('Failed to load users:', error);
    }
  };

  const createUser = async (userData) => {
    try {
      const response = await apiRequest('/admin/users', {
        method: 'POST',
        body: JSON.stringify(userData)
      });
      setUsers([...users, response.data.user]);
      showSuccess('User created successfully');
    } catch (error) {
      showError('Failed to create user');
    }
  };

  const updateUser = async (userId, userData) => {
    try {
      const response = await apiRequest(`/admin/users/${userId}`, {
        method: 'PUT',
        body: JSON.stringify(userData)
      });
      setUsers(users.map(u => u.id === userId ? response.data.user : u));
      setIsEditing(false);
      showSuccess('User updated successfully');
    } catch (error) {
      showError('Failed to update user');
    }
  };

  const deleteUser = async (userId) => {
    if (!confirm('Are you sure you want to delete this user?')) return;

    try {
      await apiRequest(`/admin/users/${userId}`, {
        method: 'DELETE'
      });
      setUsers(users.filter(u => u.id !== userId));
      showSuccess('User deleted successfully');
    } catch (error) {
      showError('Failed to delete user');
    }
  };

  const toggleUserBlock = async (userId, blocked) => {
    try {
      await apiRequest(`/admin/users/${userId}/block`, {
        method: 'PATCH',
        body: JSON.stringify({ blocked })
      });
      loadUsers(); // Refresh list
      showSuccess(`User ${blocked ? 'blocked' : 'unblocked'}`);
    } catch (error) {
      showError('Failed to update user status');
    }
  };

  return (
    <div className="user-management">
      <div className="user-controls">
        <button onClick={() => setIsEditing(true)}>
          Add New User
        </button>
        <input
          type="search"
          placeholder="Search users..."
          onChange={(e) => loadUsers(1, e.target.value)}
        />
      </div>

      <div className="users-table">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Status</th>
              <th>Groups</th>
              <th>Roles</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {users.map(user => (
              <tr key={user.id}>
                <td>{user.name}</td>
                <td>{user.email}</td>
                <td>
                  <span className={user.blocked ? 'blocked' : 'active'}>
                    {user.blocked ? 'Blocked' : 'Active'}
                  </span>
                </td>
                <td>{user.groups.map(g => g.name).join(', ')}</td>
                <td>{user.roles.map(r => r.name).join(', ')}</td>
                <td>
                  <button onClick={() => setSelectedUser(user)}>
                    Edit
                  </button>
                  <button onClick={() => toggleUserBlock(user.id, !user.blocked)}>
                    {user.blocked ? 'Unblock' : 'Block'}
                  </button>
                  <button onClick={() => deleteUser(user.id)}>
                    Delete
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {(isEditing || selectedUser) && (
        <UserForm
          user={selectedUser}
          onSubmit={selectedUser ? updateUser : createUser}
          onCancel={() => {
            setSelectedUser(null);
            setIsEditing(false);
          }}
        />
      )}
    </div>
  );
};
```

### Permission Matrix Component

```javascript
const PermissionMatrix = ({ roles, permissions }) => {
  const [rolePermissions, setRolePermissions] = useState({});

  useEffect(() => {
    loadRolePermissions();
  }, [roles]);

  const loadRolePermissions = async () => {
    const permissions = {};
    for (const role of roles) {
      try {
        const response = await apiRequest(`/admin/roles/${role.id}/permissions`);
        permissions[role.id] = response.data.permissions.map(p => p.id);
      } catch (error) {
        console.error(`Failed to load permissions for role ${role.id}:`, error);
      }
    }
    setRolePermissions(permissions);
  };

  const togglePermission = async (roleId, permissionId, hasPermission) => {
    try {
      const endpoint = `/admin/roles/${roleId}/permissions`;
      const method = hasPermission ? 'DELETE' : 'POST';

      await apiRequest(endpoint, {
        method,
        body: JSON.stringify({ permission_ids: [permissionId] })
      });

      // Update local state
      setRolePermissions(prev => ({
        ...prev,
        [roleId]: hasPermission
          ? prev[roleId].filter(id => id !== permissionId)
          : [...prev[roleId], permissionId]
      }));
    } catch (error) {
      console.error('Failed to update permission:', error);
    }
  };

  return (
    <div className="permission-matrix">
      <table>
        <thead>
          <tr>
            <th>Permission</th>
            {roles.map(role => (
              <th key={role.id}>{role.name}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {permissions.map(permission => (
            <tr key={permission.id}>
              <td>
                <div className="permission-info">
                  <strong>{permission.name}</strong>
                  <small>{permission.description}</small>
                </div>
              </td>
              {roles.map(role => {
                const hasPermission = rolePermissions[role.id]?.includes(permission.id);
                return (
                  <td key={role.id}>
                    <input
                      type="checkbox"
                      checked={hasPermission || false}
                      onChange={() => togglePermission(role.id, permission.id, hasPermission)}
                    />
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};
```

## Security Considerations

1. **Password Policies**: Enforce strong password requirements
2. **Account Locking**: Implement account lockout after failed attempts
3. **Session Management**: Proper session timeout and invalidation
4. **Audit Logging**: Log all user management operations
5. **Permission Checks**: Always verify permissions before operations
6. **Data Validation**: Validate all user input thoroughly
7. **CSRF Protection**: Protect against cross-site request forgery
8. **Rate Limiting**: Implement rate limiting on authentication endpoints

## Best Practices

1. **Role-Based Access**: Use roles for broad permissions, groups for fine-grained access
2. **Principle of Least Privilege**: Grant minimum required permissions
3. **Regular Audits**: Regularly review user permissions and access
4. **Secure Passwords**: Enforce password complexity requirements
5. **Account Lifecycle**: Proper onboarding and offboarding procedures
6. **Documentation**: Keep permission schemes well-documented
7. **Testing**: Thoroughly test permission changes before deployment
8. **Monitoring**: Monitor for suspicious account activity

---

**Next:** [Admin Cache Management](./11-admin-cache.md) | **Previous:** [Admin Assets](./06-admin-assets.md) | **Back to:** [API Overview](../../README.md)
