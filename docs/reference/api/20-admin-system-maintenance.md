# Admin System Maintenance / Update APIs

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend (SelfHelp Manager / Docker Distribution MVP).
Last verified: 2026-06-15.
Source of truth: `src/Controller/Api/V1/Admin/SystemController.php`, `src/Service/System/*`, the JSON schemas under `config/schemas/api/v1/{requests,responses}/admin/`, and the `api_routes` rows seeded by `migrations/Version20260608160348.php` (plus `migrations/Version20260610124237.php` for `update/releases` and `migrations/Version20260615130915.php` for the `update/frontend/*` routes; the `kind` + `target_frontend_version` columns come from `migrations/Version20260615130842.php`).

## Overview

These endpoints drive the CMS side of the connected, Docker-only update flow.
They let an admin read the current instance's version facts, run a compatibility
**preflight** for a target version, **request** an update, and **monitor** its
status.

Hard rules (enforced server-side):

- **The CMS never controls Docker.** These endpoints only read version facts,
  compute a compatibility verdict, and record an update request. The
  **SelfHelp Manager** (which owns Docker access) performs the actual pull,
  migration, and restart, then writes progress back.
- **Instance-scoped only.** Every call applies to the *current* instance. The
  backend derives and verifies the instance identity from server configuration
  (`SystemInstanceService`); it is never taken from the request.
- **No client-supplied `instance_id`.** The update-request body intentionally
  has no `instance_id` field (`additionalProperties: false`). A request body
  that carries `instance_id` is **rejected with `403` and logged** before schema
  validation — this blocks cross-instance update attempts.

## Permissions

| Permission            | Granted to | Used by                                                                 |
|-----------------------|------------|-------------------------------------------------------------------------|
| `admin.system.read`   | `admin`    | `version`, `update/preflight`, `update/status`, `update/releases`, `update/frontend/releases`, `update/frontend/preflight` |
| `admin.system.update` | `admin`    | `update/request`, `update/frontend/request`                             |

Both are seeded and granted to the `admin` role by
`migrations/Version20260608160348.php`. Non-admins receive `403`; unauthenticated
callers receive `401`.

## Endpoints

| Method | Path                                  | Permission            | Purpose                                  |
|--------|---------------------------------------|-----------------------|------------------------------------------|
| GET    | `/admin/system/version`               | `admin.system.read`   | Current instance version summary         |
| GET    | `/admin/system/update/releases`       | `admin.system.read`   | Core versions published in the registry  |
| GET    | `/admin/system/update/preflight`      | `admin.system.read`   | Compatibility verdict for a target       |
| POST   | `/admin/system/update/request`        | `admin.system.update` | Record an update request for THIS instance |
| GET    | `/admin/system/update/status`         | `admin.system.read`   | Status/progress of the latest operation  |
| GET    | `/admin/system/update/frontend/releases`  | `admin.system.read`   | Frontend versions published in the registry |
| GET    | `/admin/system/update/frontend/preflight` | `admin.system.read`   | Frontend-only compatibility verdict      |
| POST   | `/admin/system/update/frontend/request`   | `admin.system.update` | Record a frontend-only update request    |

### GET /admin/system/version

Returns the version facts the manager and the admin UI compare against the
registry: backend, frontend, plugin API, database migration head, the safe-mode
/ maintenance-mode flags, and per-plugin compatibility.

Response `data` (schema: `responses/admin/system_version.json`):

```json
{
  "instance_id": "selfhelp-prod-01",
  "selfhelp_version": "0.1.0",
  "backend_version": "0.1.0",
  "frontend_version": "0.1.0",
  "plugin_api_version": "0.1.0",
  "database_migration_version": "Version20260608160348",
  "deployment": "docker",
  "safe_mode": false,
  "maintenance_mode": false,
  "installed_plugins": [
    { "id": "sh2-shp-survey-js", "version": "0.1.0", "compatible": true }
  ]
}
```

Version sources (why dev setups show `unknown`/`source`):

- `frontend_version` comes from the `SELFHELP_FRONTEND_VERSION` env var. The
  SelfHelp Manager injects it into managed installs; a source/dev backend has
  no way to know which frontend build is running, so it reports `unknown` and
  the admin UI falls back to the frontend's own build-time package version
  (labelled "self-reported").
- `deployment` comes from `SELFHELP_DEPLOYMENT`: the production Docker images
  bake `docker` (see `docker/Dockerfile`); anything else (composer dev,
  bare-metal checkout) defaults to `source`.

### GET /admin/system/update/releases

Lists the core versions published in the official registry index (newest
first) so the admin "Request an update" picker offers real versions. Reads
only the lightweight registry index — the SIGNED release document is still
fetched and verified per version by the preflight.

Fail-soft: when the registry is unreachable the endpoint returns
`available: false` with an empty list and the UI falls back to manual version
entry. The instance never blocks on the registry.

Response `data` (schema: `responses/admin/update_releases.json`):

```json
{
  "available": true,
  "current_version": "0.1.0",
  "releases": [
    { "version": "0.2.0", "channel": "stable", "blocked": false },
    { "version": "0.1.0", "channel": "stable", "blocked": false }
  ]
}
```

### GET /admin/system/update/preflight

Query parameter: `target` (required) — the target SelfHelp version. A missing
`target` returns `400`.

Computes a verdict without touching Docker. `status` is:

- `ok` — safe to proceed;
- `warning` — proceed with care (e.g. the registry was unreachable, or a plugin
  declares incompatibility);
- `blocked` — do not proceed (e.g. the target is a downgrade or an invalid
  version). A blocked preflight makes `update/request` fail with `422`.

The verdict always includes a `resource_checks` informational check declaring
that disk/CPU/RAM and Docker checks run **in the manager**, not the CMS.

Response `data` (schema: `responses/admin/update_preflight.json`):

```json
{
  "preflight_id": "pf_0a1b2c3d",
  "status": "warning",
  "instance_id": "selfhelp-prod-01",
  "current_version": "0.1.0",
  "target_version": "0.1.1",
  "checks": [
    { "code": "resource_checks", "severity": "info", "message": "Disk, CPU, memory and Docker checks are performed by the SelfHelp Manager before execution." },
    { "code": "registry_unreachable", "severity": "warning", "message": "The registry could not be reached; compatibility was computed from local facts only." }
  ],
  "options": [],
  "database": { "destructive": false, "requires_backup": true, "manual_confirmation_required": false },
  "rollback": { "automatic_before_migrations": true, "automatic_after_destructive_migrations": false }
}
```

### POST /admin/system/update/request

Records an instance-scoped update request and returns `202 Accepted` with the
operation id. The manager picks the operation up and performs it.

Request body (schema: `requests/admin/update_request.json`,
`additionalProperties: false`):

```json
{
  "target_version": "0.1.1",
  "preflight_id": "pf_0a1b2c3d",
  "accepted_migration_risk": false,
  "typed_confirmation": "0.1.1"
}
```

- `instance_id` is **not** accepted — sending it returns `403` (cross-instance
  attempts are denied and logged).
- A destructive migration (`database.destructive: true` in the preflight)
  requires `accepted_migration_risk: true`, otherwise the request returns `422`.
- A `blocked` preflight returns `422`.

Response `data` (schema: `responses/admin/update_request.json`):

```json
{ "operation_id": "op_9f8e7d6c", "instance_id": "selfhelp-prod-01", "status": "requested" }
```

### GET /admin/system/update/status

Returns the latest operation for this instance. When no operation has ever been
requested, `operation_id` is an empty string and `status` is `succeeded` (a
benign "nothing in flight" default).

Response `data` (schema: `responses/admin/update_status.json`):

```json
{
  "instance_id": "selfhelp-prod-01",
  "operation_id": "op_9f8e7d6c",
  "status": "running",
  "kind": "core",
  "target_version": "0.1.1",
  "target_frontend_version": null,
  "progress_percent": 40,
  "steps": [
    { "name": "pull", "status": "succeeded" },
    { "name": "migrate", "status": "running", "detail": "Applying Version20260610…" }
  ],
  "requested_at": "2026-06-08T16:00:00+00:00",
  "updated_at": "2026-06-08T16:02:00+00:00",
  "message": "Applying database migrations"
}
```

`kind` is `core` (default, full-stack update) or `frontend` (a frontend-only
swap requested via `update/frontend/request`); `target_frontend_version` is the
targeted frontend version for a `frontend`-kind operation and `null` otherwise
(including the synthetic `idle` status). The same two fields are emitted on the
manager-claim payload so the SelfHelp Manager runs the right kind of update.

## Frontend-only updates

The frontend ships independently of the core, so an instance already on the
newest core can still move to a newer **compatible** frontend without a
full-stack update. These three endpoints mirror the core update flow but are
deliberately lightweight: the frontend is **stateless** (no database migration,
no backup), so the CMS only validates the version + downgrade locally and defers
the authoritative frontend ⇄ core compatibility + signature checks to the
SelfHelp Manager, which re-resolves the signed frontend release and swaps only
the frontend container (rolling it back on a failed health check).

### GET /admin/system/update/frontend/releases

Lists the frontend versions published in the official registry index (newest
first) for the frontend-only picker. Same shape and fail-soft behaviour as
`update/releases` (`available: false` + empty list when the registry is
unreachable); `current_version` is the instance's installed frontend version.
Response schema: `responses/admin/update_releases.json` (reused).

### GET /admin/system/update/frontend/preflight

Query parameter: `target` (required) — the target frontend version. A missing
`target` returns `400`.

Returns the same verdict shape as `update/preflight`, but because a frontend
swap is stateless the verdict is always non-destructive: `database.destructive`
and `database.requires_backup` are always `false`. The only blocking checks are
an **invalid version** and a **downgrade** (and a downgrade is only evaluated
when the installed frontend version is a real, parseable version — an `unknown`
stamp from a pre-versioning build never falsely blocks). A target that is not
listed in the registry is a `warning`, not a block — the manager re-validates
availability authoritatively. Response schema:
`responses/admin/update_preflight.json` (reused).

### POST /admin/system/update/frontend/request

Records a frontend-only update request and returns `202 Accepted`. Like the core
request it carries **no `instance_id`** (sending it returns `403`), but its body
also omits `accepted_migration_risk`/`typed_confirmation` — a frontend swap has
no destructive migration to confirm.

Request body (schema: `requests/admin/frontend_update_request.json`,
`additionalProperties: false`):

```json
{ "target_version": "0.1.7", "preflight_id": "fe_pf_0a1b2c3d" }
```

- A `blocked` preflight returns `422`.

Response `data` (schema: `responses/admin/frontend_update_request.json`):

```json
{ "operation_id": "op_5c6d7e8f", "instance_id": "selfhelp-prod-01", "status": "requested", "kind": "frontend", "target_frontend_version": "0.1.7" }
```

## Audit trail

Every request is persisted to `system_update_operations` (entity
`App\Entity\System\SystemUpdateOperation`) with the requesting user, target
version, preflight id, status, progress, and step log. A frontend-only request
additionally sets `kind = frontend` and `target_frontend_version`. The manager
updates the row as it executes; the `status` endpoint reads it back.

## Related

- Cross-repo version alignment: [../../developer/cross-repo-compatibility-matrix.md](../../developer/cross-repo-compatibility-matrix.md).
- Shared contracts: `@selfhelp/shared` `src/types/api/system.ts` (`ISystemVersion`, `IUpdatePreflight`, `IUpdateStatus`, `IUpdateRequest`, `IUpdateReleases`, and the frontend-only `TUpdateKind`, `IFrontendUpdateRequest`, `IFrontendUpdateRequestResponse`, `IFrontendUpdateReleases`, `IFrontendUpdatePreflight`).
- SelfHelp Manager (owns Docker, performs the operation): the `sh-manager` repository.
