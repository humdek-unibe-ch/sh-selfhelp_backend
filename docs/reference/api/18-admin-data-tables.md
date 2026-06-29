# Admin Data Tables APIs

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-26.
Source of truth: `App\Controller\Api\V1\Admin\AdminDataController`, the request schemas under `config/schemas/api/v1/requests/admin/`, and the `api_routes` rows seeded by `migrations/Version20260603092955.php` and `migrations/Version20260626121351.php`.

## Overview

Data tables hold the records produced by CMS forms. Each form section stores its
submissions in a data table **named after the section id** (e.g. section `213`
→ table `213`). Tables are created lazily — see
[Form Submissions](./17-form-submissions.md) for how rows are written.

These admin endpoints (`AdminDataController`) let an admin browse table
metadata, read rows, delete records/tables/columns, and export table contents.

## Core Concepts

### Data table structure
- **`data_tables`** — one row per table (`id`, `name`, `display_name`, `timestamp`).
- **`data_rows`** — one row per record, attributed to a user.
- **`data_cols`** — column definitions. Each column has an **immutable
  `field_key`** (the storage key, unique per table, derived from the data source —
  `section_<input section id>` for core forms, `question.name` for SurveyJS)
  and a **mutable `display_name`** (the human label). Renaming the label never
  changes the storage key, so stored cells, exports and interpolation tokens stay
  stable. The label provenance is the nullable FK `id_display_name_source` →
  `lookups` (group `dataColDisplayNameSource`, codes `auto` | `manual`; a NULL FK
  means the default `auto`): it records whether the label was auto-derived from a
  submission or curated by an admin (a manual label is never overwritten by a
  later submission). See issue #56 and
  [Database Design](../../developer/04-database-design.md).
- **`data_cells`** — cell values, language-scoped (`id_languages`).

### Permissions
All endpoints require an `admin.data.*` permission **and** an additional
per-table data-access check resolved against `role_data_access` (see
[Data Access Management](../developer/19-data-access-management.md)). Two layers
apply:

1. **Route permission** — the bit checked by `ApiSecurityListener` from the
   route metadata (`admin.data.read`, `admin.data.delete`, etc.).
2. **Per-table access** — `DataTableService::canAccessDataTable()` checks the
   caller's CRUD bits for that specific table.

Read endpoints additionally branch on table access level:
- Callers with full (DELETE) access to the table get **all** rows.
- Callers without it get **group-filtered** rows (only data from users in their
  accessible groups), computed server-side.

### Row time handling
Datetimes are stored in UTC and converted to the CMS preference timezone on
output, consistent with the rest of the API.

## Endpoints

| Method | Path | Route permission |
|--------|------|------------------|
| GET | `/cms-api/v1/admin/data/tables` | `admin.data.read` |
| GET | `/cms-api/v1/admin/data` | `admin.data.read` |
| GET | `/cms-api/v1/admin/data/tables/{tableName}/columns` | `admin.data.read` |
| GET | `/cms-api/v1/admin/data/tables/{tableName}/column-names` | `admin.data.read` |
| GET | `/cms-api/v1/admin/data/tables/{tableName}/export` | `admin.data.read` |
| POST | `/cms-api/v1/admin/data/tables/bulk-export` | `admin.data.read` |
| DELETE | `/cms-api/v1/admin/data/records/{recordId}` | `admin.data.delete` |
| DELETE | `/cms-api/v1/admin/data/tables/{tableName}` | `admin.data.delete` |
| DELETE | `/cms-api/v1/admin/data/tables/{tableName}/columns` | `admin.data.delete_columns` |
| PATCH | `/cms-api/v1/admin/data/tables/{tableName}/columns/display-name` | `admin.data.update_columns` |

### List data tables

**Endpoint:** `GET /cms-api/v1/admin/data/tables`

Returns every table the caller can access (cached, permission-filtered).

```json
{
  "data": {
    "dataTables": [
      {
        "id": 12,
        "name": "213",
        "displayName": "Contact form",
        "created": "2026-06-01T09:30:00+00:00",
        "crud": "1111"
      }
    ]
  }
}
```

`crud` is the caller's CRUD bit string for that table.

### Get rows

**Endpoint:** `GET /cms-api/v1/admin/data`

**Query parameters:**

| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `table_name` | string | _required_ | Table name (= section id) |
| `user_id` | int | _all users_ | Restrict to one user |
| `exclude_deleted` | bool | `true` | Skip soft-deleted rows |
| `language_id` | int | `1` | Translation language |

Returns `{ "data": { "rows": [ ... ] } }`. Row shape depends on the table's
columns; each row also carries record metadata (e.g. `record_id`).

### Get columns / column names

- `GET /cms-api/v1/admin/data/tables/{tableName}/columns`
  → `{ "data": { "columns": [ { "id": 1, "fieldKey": "email", "displayName": "Email address" }, ... ] } }`.
  `fieldKey` is the immutable storage key (always present for a real column);
  `displayName` is the curated label and may be `null` (clients fall back to
  `fieldKey`). Treat `fieldKey` as an **opaque literal** — never split it on `.`
  (a dotted survey key like `household.member_name` is one key, not a path).
- `GET /cms-api/v1/admin/data/tables/{tableName}/column-names`
  → `{ "data": { "columnNames": ["email", "name", ...] } }`. These are
  **field keys** (the stable storage identities), not display labels.

### Update a column display name

**Endpoint:** `PATCH /cms-api/v1/admin/data/tables/{tableName}/columns/display-name`

**Request body:** `{ "fieldKey": "email", "displayName": "Email address" }`
([schema](../../config/schemas/api/v1/requests/admin/update_data_column_display_name.json))

Renames the **human label only** — the immutable `field_key` is unchanged, so
stored cells, exports and interpolation tokens keep working. Sending
`"displayName": null` (or an empty string) clears the curated label. Setting a
label points the column's `id_display_name_source` FK at the `manual` lookup, so
later submissions no longer auto-overwrite it. Returns `{ "data": { "updated": true } }` (or
`updated: false` when no matching column exists).

### Delete a record

**Endpoint:** `DELETE /cms-api/v1/admin/data/records/{recordId}`

**Query parameters:**

| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `table_name` | string | _required_ | Table the record belongs to (for the access check) |
| `own_entries_only` | bool | `true` | If true, only the caller's own records can be deleted |

Soft-deletes the record via an action trigger type. Returns
`{ "data": { "deleted": true } }`.

### Delete a table

**Endpoint:** `DELETE /cms-api/v1/admin/data/tables/{tableName}`

Cascade-deletes the table and all its rows, columns, and cells.

### Delete columns

**Endpoint:** `DELETE /cms-api/v1/admin/data/tables/{tableName}/columns`

**Request body:** `{ "columns": ["colA", "colB"] }` — the values are column
**field keys** (storage identities), not display labels.
([schema](../../config/schemas/api/v1/requests/admin/delete_data_columns.json))

Returns `{ "data": { "deleted_column_count": 2 } }`.

## Data Export

> **Envelope exception (important):** Unlike every other API endpoint, the two
> export endpoints below do **not** wrap their payload in the standard
> `{status, message, error, logged_in, meta, data}` envelope and do **not** go
> through `ApiResponseFormatter` on success. They return the raw file body
> (CSV / JSON / ZIP) with a `Content-Disposition: attachment` header so the
> frontend can read the response as a `blob`. **Error** responses *do* still use
> the standard envelope. Do not "normalise" these to the envelope — the raw body
> is intentional. (This is the same pattern as the registration-codes CSV
> export.)

Both export endpoints apply the same read permission and per-table access /
group-filter logic as `GET /admin/data`.

### Export a single table

**Endpoint:** `GET /cms-api/v1/admin/data/tables/{tableName}/export`

**Query parameters:**

| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `format` | `csv` \| `json` | `csv` | Output format |
| `user_id` | int | _all users_ | Restrict to one user |
| `language_id` | int | `1` | Translation language |
| `exclude_deleted` | bool | `true` | Skip soft-deleted rows |

- `format=csv` → `Content-Type: text/csv`, attachment. The header row is the
  **union of all column names** across the table; missing cells are empty.
- `format=json` → `Content-Type: application/json`, a raw array of row objects
  (not the envelope).

The download filename is derived from the table's display name (or name).

### Bulk export (ZIP)

**Endpoint:** `POST /cms-api/v1/admin/data/tables/bulk-export`

> The path is `bulk-export`, not `export`, on purpose: `POST .../tables/export`
> would be shadowed by the `DELETE /admin/data/tables/{tableName}` wildcard and
> return `405 Method Not Allowed`.

**Request body:**
([schema](../../config/schemas/api/v1/requests/admin/data_export_bulk.json))
```json
{
  "table_names": ["218", "219"],
  "format": "csv",
  "user_id": null,
  "language_id": 1,
  "exclude_deleted": true
}
```
`user_id`, `language_id`, and `exclude_deleted` are optional.

Returns `Content-Type: application/zip`, attachment, containing one file per
table named `<displayName or name>.<format>`. If the caller cannot read **any**
requested table, the whole request fails with `403` (no silent skipping).

## Notes

- Data tables are created automatically by form submissions and (optionally) by
  plugins. There is intentionally no `POST` create-table endpoint.
- When the admin role gains access to a newly created table it is granted
  automatically by `DataTableAdminAccessListener` on flush.

---

**Back to:** [API Overview](../README.md)
