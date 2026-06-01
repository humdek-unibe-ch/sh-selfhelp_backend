# Admin Registration Codes APIs

## Overview

Registration codes are admin-issued, single-use strings that gate self-registration on register pages where `open_registration = 0`. Each code carries a target group: when a user registers with the code, the new account is assigned to that group and the code is marked consumed and cannot be reused.

The codes are stored in `validation_codes`. The admin endpoints expose CRUD operations over the registration-code subset of that table.

## Core Concepts

- **Single-use:** A code can be used by exactly one user. On successful registration the row's `consumed` timestamp is set; subsequent attempts return `400 "This registration code has already been used."`
- **Group binding:** Each code is created against a group (`id_groups`). When the code is consumed, the registering user is assigned to that group.
- **Reusable storage:** Consumed codes are kept (not deleted) so admins can audit who-used-what. The list endpoint exposes `consumed_at` and `is_consumed` for the UI.
- **Code format:** Up to 16 characters, unique across `validation_codes.code`. Created by an admin in advance and distributed to invitees out-of-band.

## List Registration Codes

Retrieve a paginated list of registration codes with optional filtering.

**Endpoint:** `GET /cms-api/v1/admin/registration-codes`

**Authentication:** Required (JWT Bearer token)

**Query Parameters:**
- `page` (int, default `1`): Page number
- `pageSize` (int, default `20`, max `100`): Items per page
- `search` (string, optional): Partial match on `code`
- `id_groups` (int, optional): Filter by group ID
- `status` (string, optional): `available` (not yet consumed) or `used` (consumed)
- `sort` (string, optional): `created_at` (default) or `consumed_at`
- `sortDirection` (string, optional): `asc` or `desc` (default `desc`)

**Success Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2026-06-01T10:30:00Z"
  },
  "data": {
    "codes": [
      {
        "id": "INVITE123",
        "code": "INVITE123",
        "id_groups": 3,
        "group_name": "Participants",
        "created_at": "2026-05-01T10:00:00+00:00",
        "consumed_at": "2026-05-10T14:23:00+00:00",
        "is_consumed": true
      }
    ],
    "pagination": {
      "page": 1,
      "pageSize": 20,
      "totalCount": 87,
      "totalPages": 5,
      "hasNext": true,
      "hasPrevious": false
    }
  }
}
```

**Permissions:** `admin.registration_code.read`

## Create Registration Code

Create a new registration code bound to a group.

**Endpoint:** `POST /cms-api/v1/admin/registration-codes`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
[View JSON Schema](../../config/schemas/api/v1/requests/admin/registration_code_create.json)
```json
{
  "code": "INVITE123",
  "id_groups": 3
}
```

- `code` (string, required, 1-16 chars): Code value. Must be unique.
- `id_groups` (int, required): Group the registering user will be assigned to.

**Success Response (`201 Created`):**
```json
{
  "status": 201,
  "message": "Created",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2026-06-01T10:30:00Z"
  },
  "data": {
    "id": "INVITE123",
    "code": "INVITE123",
    "id_groups": 3,
    "group_name": "Participants",
    "created_at": "2026-06-01T10:30:00+00:00"
  }
}
```

**Error Responses:**
- `400 Bad Request`: `"Code cannot be empty."`
- `400 Bad Request`: `"A registration code with this value already exists."`
- `400 Bad Request`: `"Group not found."`

**Permissions:** `admin.registration_code.create`

## Delete Registration Code

Delete a registration code by its value. Consumed codes can also be deleted — this only removes the audit row.

**Endpoint:** `DELETE /cms-api/v1/admin/registration-codes/{code}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `code` (string): The registration code value.

**Success Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2026-06-01T10:30:00Z"
  },
  "data": []
}
```

**Error Responses:**
- `404 Not Found`: `"Registration code not found."`

**Permissions:** `admin.registration_code.delete`

## Permissions

| Permission                          | Endpoint                                       |
|-------------------------------------|------------------------------------------------|
| `admin.registration_code.read`      | `GET /admin/registration-codes`                |
| `admin.registration_code.create`    | `POST /admin/registration-codes`               |
| `admin.registration_code.delete`    | `DELETE /admin/registration-codes/{code}`      |

All three permissions are granted to the `admin` role by default (see migration `Version20260529074436`).

## Related

- [Self-Registration endpoint](./01-authentication.md#self-registration) — where end-users present the code.
- [User Validation APIs](./03-user-validation.md) — email-validation flow that runs after self-registration.
