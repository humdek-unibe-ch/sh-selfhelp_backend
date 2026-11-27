# Admin resource usage

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-11-26.
Source of truth: `AdminResourceUsageController`, `ResourceUsageService`, schema `responses/admin/resources/usage.json`, route `admin_resources_usage_get`.

## Overview

Where a plugin resource is used (contract C7): the pages and actions that hold
an item of a registered `resourceTypes` code, limited to what the caller may
see in the admin.

## Get usage

**Endpoint:** `GET /cms-api/v1/admin/resources/{type}/{id}/usage`

**Route name:** `admin_resources_usage_get`

**Permission:** `admin.access`

**Path parameters:**

| Name | Pattern | Meaning |
| --- | --- | --- |
| `type` | `[a-z][a-z0-9_]{1,63}` | Resource type code of an enabled plugin |
| `id` | positive integer | Item id |

**Responses:**

| Status | When |
| --- | --- |
| 200 | Caller holds the view grant on the item (the superuser passes) |
| 401 | Guest |
| 403 | Signed in, no view grant on the item |
| 404 | Type not registered by an enabled plugin |

**Success data** (`responses/admin/resources/usage`):

```json
{
  "pages": [
    {
      "id": 7,
      "keyword": "intake",
      "title": "Intake",
      "draft_uses": true,
      "published_uses": true
    }
  ],
  "actions": [
    { "id": 3, "name": "After intake" }
  ],
  "hidden_count": 1
}
```

- `pages`: only pages the caller may read in the admin (`admin.page.read` plus
  the view grant on the page; the superuser all), sorted by keyword. `title`
  may be null.
- `actions`: only for callers with `admin.action.read`.
- `hidden_count`: usages the caller may not see (other pages, or every action
  when the caller lacks `admin.action.read`).

References come from `ResourceReadIndex` (section `resourceFields` and action
job `resourceFields`).
