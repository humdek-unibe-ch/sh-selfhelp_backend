# Admin Registration Codes APIs

## Overview

Registration codes are admin-issued, single-use strings that gate self-registration on register pages where `open_registration = 0`. Each code carries a target group: when a user registers with the code, the new account is assigned to that group and the code is marked consumed and cannot be reused.

The codes are stored in `validation_codes`. The admin endpoints expose read, generate, and export operations over the registration-code subset of that table.

## Core Concepts

- **Single-use:** A code can be used by exactly one user. On successful registration the row's `consumed` timestamp is set; subsequent attempts return `400 "This registration code has already been used."`
- **Group binding:** Each code is created against a group (`id_groups`). When the code is consumed, the registering user is assigned to that group.
- **Audit trail:** Consumed codes are kept (not deleted) so admins can audit who-used-what. The list endpoint exposes `consumed_at` and `is_consumed` for the UI.
- **Code format:** 8 uppercase alphanumeric characters (A–Z, 0–9), generated randomly. Unique across `validation_codes.code`.

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
        "id": "A3BZ9K2W",
        "code": "A3BZ9K2W",
        "id_groups": 3,
        "group_name": "Participants",
        "created_at": "2026-06-02 10:30:00",
        "consumed_at": null,
        "is_consumed": false
      }
    ],
    "pagination": {
      "page": 1,
      "pageSize": 20,
      "totalCount": 87,
      "totalPages": 5,
      "hasNext": true,
      "hasPrevious": false
    },
    "config": {
      "generate_min": 1,
      "generate_max": 10000
    }
  }
}
```

**Permissions:** `admin.registration_code.read`

## Generate Registration Codes

Generate one or more random 8-character alphanumeric codes, all bound to the same group, in a single transaction.

**Endpoint:** `POST /cms-api/v1/admin/registration-codes/generate`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
[View JSON Schema](../../config/schemas/api/v1/requests/admin/registration_code_generate.json)
```json
{
  "count": 50,
  "id_groups": 3
}
```

- `count` (int, required, 1–`config.generate_max`): Number of codes to generate. The upper bound is server-configured — read it from `config.generate_max` in the list response.
- `id_groups` (int, required): Group the registering users will be assigned to.

**Success Response (`201 Created`):**
```json
{
  "status": 201,
  "message": "Created",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2026-06-02T10:30:00Z"
  },
  "data": {
    "codes": [
      {
        "id": "A3BZ9K2W",
        "code": "A3BZ9K2W",
        "id_groups": 3,
        "group_name": "Participants",
        "created_at": "2026-06-02 10:30:00",
        "consumed_at": null,
        "is_consumed": false
      }
    ]
  }
}
```

**Error Responses:**
- `422 Unprocessable Entity`: `count` missing, not an integer, or outside `1–config.generate_max`.
- `422 Unprocessable Entity`: `id_groups` missing or group not found.
- `422 Unprocessable Entity`: Table capacity would be exceeded — message includes current count and remaining slots:
  ```
  Cannot generate 500 codes: the table limit of 10000 would be exceeded. Currently 9800 codes exist; 200 more can be created.
  ```

**Permissions:** `admin.registration_code.create`

**Notes:**
- `config.generate_max` (from the list endpoint) is the per-request ceiling. A separate server-side total-table cap (`REGISTRATION_CODE_TOTAL_MAX`, default 10000) is checked before inserting — the table cannot exceed this limit regardless of how many requests are made.
- Codes are generated using `INSERT IGNORE` in 500-row chunks. Rare PK collisions are silently retried until the full `count` is reached.
- All codes in a single request share the same `created_at` timestamp (UTC).

## Export Registration Codes

Download all registration codes (with optional filters) as a CSV file.

**Endpoint:** `GET /cms-api/v1/admin/registration-codes/export`

**Authentication:** Required (JWT Bearer token)

**Query Parameters** (same filters as the list endpoint, no pagination):
- `search` (string, optional): Partial match on `code`
- `id_groups` (int, optional): Filter by group ID
- `status` (string, optional): `available` or `used`

**Response:** `text/csv; charset=UTF-8` — not the standard JSON envelope.

```
Content-Type: text/csv; charset=UTF-8
Content-Disposition: attachment; filename="registration_codes_20260602_103000.csv"
```

CSV columns (in order): `code`, `group_name`, `status`, `created_at`, `consumed_at`

```csv
code,group_name,status,created_at,consumed_at
A3BZ9K2W,Participants,Available,2026-06-02 10:30:00,
X7QP1NR4,Participants,Used,2026-06-01 08:00:00,2026-06-01 09:15:22
```

- `status` is `Available` or `Used`.
- `consumed_at` is empty when the code has not been used.
- Results are ordered by `created_at DESC`.

**Permissions:** `admin.registration_code.read`

## Permissions

| Permission                          | Endpoint                                           |
|-------------------------------------|----------------------------------------------------|
| `admin.registration_code.read`      | `GET /admin/registration-codes`                    |
| `admin.registration_code.read`      | `GET /admin/registration-codes/export`             |
| `admin.registration_code.create`    | `POST /admin/registration-codes/generate`          |

Both permissions are granted to the `admin` role by default (see migrations `Version20260529074436` and `Version20260601120000`).

## Related

- [Self-Registration endpoint](./01-authentication.md#self-registration) — where end-users present the code.
- [User Validation APIs](./03-user-validation.md) — email-validation flow that runs after self-registration.
