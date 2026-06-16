# Instance-Scoped System Layer (Maintenance & Updates)

Audience: Developers and technical operators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-16.
Source of truth: `src/Entity/System/`, `src/Service/System/`, `src/Controller/Api/V1/Admin/SystemController.php`, `src/Controller/Api/V1/Manager/SystemManagerController.php`, `src/EventListener/SystemUpdateMercurePublisher.php`, and `config/schemas/api/v1`.

The **system layer** lets a CMS admin view this instance's version and health,
run an update **compatibility preflight**, and **request** a connected, signed
update — and lets the SelfHelp Manager (`sh-manager`) drive that update and write
its progress back. It is the backend half of the connected install/update model
whose design lives in `docs/archive/core-installation-and-distribution-plan.md`;
this page documents the **shipped** implementation.

## Core principle: the CMS never controls Docker

The CMS **records intent and surfaces status**. It never runs Docker, backups,
migrations, or image pulls. The SelfHelp Manager owns Docker and performs the
work. Two hard rules follow and are enforced in code:

1. **The instance id is always server-derived, never client-supplied.** Every
   read/write is scoped to the current instance resolved server-side. A request
   body that carries an `instance_id` is denied (and logged) before schema
   validation — see `SystemController::requestUpdate()` calling
   `SystemUpdateService::denyCrossInstance()`.
2. **A manager bound to one instance can never read or affect another.** The
   manager loop is scoped to the server-derived instance id in the service layer.

## Components

| Type | Class | Responsibility |
| --- | --- | --- |
| Entity | `App\Entity\System\SystemUpdateOperation` | Instance-scoped audit trail of update operations. |
| Repository | `App\Repository\System\SystemUpdateOperationRepository` | Queries scoped by instance id / status. |
| Service | `SystemVersionService` | Current version facts (SelfHelp/backend/frontend/plugin-API/DB migration) + installed-plugin compatibility. |
| Service | `SystemHealthService` | Aggregated, instance-scoped health/status of components. |
| Service | `SystemUpdateService` | Preflight, request, status, cross-instance guard, manager claim + write-back. |
| Service | `MaintenanceModeService` | Maintenance-mode state + the `SELFHELP_MAINTENANCE_MODE` env hard switch. |
| Service | `SystemInstanceService` | Server-derived instance identity + read-only safe-mode flag. |
| Service | `SystemAdvisoryService` | Filters the registry advisory feed to advisories affecting installed components. |
| Reader | `SystemRegistryReader` | The ONE registry reader for the system layer: a fail-soft adapter over the **signed** `UnifiedRegistryClient` (the same client the plugin install/Available flow uses). Core release metadata for the preflight is Ed25519-verified before it is trusted; an unsigned/tampered release degrades to `null`. The earlier unsigned `HttpSystemRegistryGateway` has been removed. |

### The operation entity

`system_update_operations` is the audit trail. Key fields: server-derived
`instance_id`, unique `operation_id`, `target_version`, `status`,
`progress_percent`, `steps_json`, `message`, `accepted_migration_risk`,
`requested_by`, `requested_at`, `updated_at` (all UTC).

The status enum separates **who may write what**:

- The CMS only ever writes `requested`.
- `SystemUpdateOperation::MANAGER_WRITABLE_STATUSES` is the set the manager may
  write back (`accepted`, `preflight_running`, `preflight_failed`,
  `backup_running`, `update_running`, `migration_running`,
  `health_check_running`, `succeeded`, `failed`, `rollback_running`,
  `rolled_back`, `rollback_failed`). `requested` is intentionally **excluded** so
  the manager can never re-open an operation as a fresh request.
- `isTerminalStatus()` marks the end states (`succeeded`, `failed`,
  `rolled_back`, `rollback_failed`, `rejected`).

`GET /admin/system/update/status` returns the latest operation, or — when this
instance has **never** had an update operation — the synthetic
`SystemUpdateService::STATUS_IDLE` (`"idle"`, progress `0`, `target_version` =
the installed version). This is deliberately honest: the old code returned a
phantom `succeeded / 100%` for an update that never happened. `idle` is **not** a
manager-writable status, so the manager loop can never produce it.

### Plugin compatibility semantics

Every backend "can this plugin run on this core?" decision (version summary,
core-update preflight, registry resolver, manifest validator) goes through the
single helper `App\Plugin\Versioning\PluginCompatibility`. The exact rules for
`compatibility.selfhelp` / `compatibility.core`, `pluginApiVersion` /
`compatibility.pluginApi`, the `blocked` flag, and advisories are documented in
[26-plugin-compatibility-rules.md](26-plugin-compatibility-rules.md).

## Admin API (browser, permission-gated)

Thin controller `Api\V1\Admin\SystemController`; all responses use the standard
`ApiResponseFormatter` envelope and the listed JSON schemas.

| Method + path | Permission | Purpose | Schema |
| --- | --- | --- | --- |
| `GET /admin/system/version` | `admin.system.read` | Version summary + installed-plugin compatibility (incl. `deployment: docker\|source`). | `responses/admin/system_version` |
| `GET /admin/system/health` | `admin.system.read` | Aggregated component health. | `responses/admin/system_health` |
| `GET /admin/system/update/releases` | `admin.system.read` | Registry-published core versions for the update picker (fail-soft offline). | `responses/admin/update_releases` |
| `GET /admin/system/update/preflight?target=<v>` | `admin.system.read` | Compatibility preflight for a target version. | `responses/admin/update_preflight` |
| `POST /admin/system/update/request` | `admin.system.update` | Request an update for THIS instance. Returns **202 Accepted**. | `requests/admin/update_request` → `responses/admin/update_request` |
| `GET /admin/system/update/status` | `admin.system.read` | Latest operation status for THIS instance. | `responses/admin/update_status` |
| `GET /admin/system/maintenance` | `admin.system.read` | Maintenance state (+ read-only `safe_mode`). | `responses/admin/system_maintenance` |
| `PUT /admin/system/maintenance` | `admin.system.maintenance` | Enable/disable maintenance for THIS instance. | `requests/admin/maintenance_set` → `responses/admin/system_maintenance` |

Notes:

- `requestUpdate()` runs the **cross-instance guard first**: any `instance_id` in
  the body is denied before validation.
- `setMaintenance()` refuses to disable while the env hard switch
  `SELFHELP_MAINTENANCE_MODE` is set, returning **409 Conflict** with a clear
  message (clear it in the instance environment instead).
- Safe mode is **read-only** from the web. It is toggled by the operator via
  `SELFHELP_DISABLE_PLUGINS` or the canonical `selfhelp:safe-mode` CLI (a thin
  alias over `selfhelp:plugin:safe-mode`, which still works); the system layer
  only surfaces it.

## Manager loop API (manager-to-CMS, token-gated)

`Api\V1\Manager\SystemManagerController` is how the SelfHelp Manager drives an
operation. These routes are registered **without permission rows** (so the
JWT/ACL pipeline treats them as public) and are instead gated by a **per-instance
manager bearer token** verified in-controller with a constant-time comparison
(`hash_equals`). The browser never calls them.

| Method + path | Purpose | Schema |
| --- | --- | --- |
| `GET /manager/system/update/pending` | Claim the next pending operation for THIS instance. **404** when there is nothing claimable (so "no work" and "wrong instance" look identical). | `responses/manager/update_pending` |
| `POST /manager/system/update/{operationId}/status` | Write a lifecycle status/progress/steps update for an operation owned by THIS instance. | `requests/manager/update_status` → `responses/manager/update_status_ack` |

The token comes from `SELFHELP_MANAGER_TOKEN`. When it is empty the manager loop
is **disabled** and every call is denied — an unconfigured instance can never be
driven by an anonymous caller. The manager generates this token per instance
(`secrets.env`) and injects it into the backend container; instances installed
before the token existed get it backfilled on `instance update` / repair.

The default transport is **exec-based**: `sh-manager instance process-operations <id>`
runs a PHP one-liner inside the backend container via `docker compose exec`,
calling `http://localhost:8080/cms-api/v1/manager/system/update/*` with the
container's own `$SELFHELP_MANAGER_TOKEN`. No published ports, no host-side token
handling. `--backend-url ... --token ...` remains for remote/advanced setups.

`process-operations` drains the queue **once** and exits. The persistent-mode
manager web UI runs a background poller that drains all inventory instances on
an interval (default 15s), so on a managed host no extra scheduling is needed.
For headless setups, a supervised trigger (systemd timer + cron recipe + a
`--watch` long-running loop) is documented in the `sh-manager` repository's
`docs/operator/process-operations-scheduling.md`. Without either, a
CMS-requested update stays in `requested` forever. The backend deliberately does
not run it — the CMS never controls Docker.

### Manager-loop visibility (last seen, staleness)

So that "requested but nothing happens" is diagnosable from the CMS:

- `SystemUpdateService` records a **manager last-seen** timestamp (cache key
  `selfhelp_manager_last_seen_at`) on every authenticated manager call (claim
  or status write-back).
- `GET /admin/system/health` includes a `manager_loop` component:
  `not_configured` (token empty — informational, CLI-managed instances still
  work), `down` (configured but no manager has ever polled), `degraded` (last
  poll older than 10 minutes), `ok` otherwise.
- `GET /admin/system/update/status` includes a `manager` block:
  `{ configured, last_seen_at, requested_stale }`. `requested_stale` becomes
  `true` when the latest operation has sat in `requested` for over 2 minutes —
  the frontend uses it to warn "the manager has not picked this up".

## Update lifecycle (happy path)

```
CMS: admin runs preflight (admin.system.read)
CMS: admin requests update -> operation status = requested        (admin.system.update)
Manager: GET /manager/system/update/pending  -> claims it
Manager: POST .../status accepted -> preflight_running -> backup_running
         -> update_running -> migration_running -> health_check_running
         -> succeeded            (or failed / rolled_back on error)
CMS UI: tracks live over the `system-update` SSE event (see below); a short
        GET /admin/system/update/status poll runs only while SSE is disconnected
```

### Live progress (SSE, not polling)

Every insert/update of a `SystemUpdateOperation` row is published to the
**requester's** per-user `system-update` Mercure topic by the Doctrine listener
`App\EventListener\SystemUpdateMercurePublisher` (on `postFlush`, exactly like
`AclVersionMercurePublisher` does for ACL). This fires both when the CMS creates
the `requested` row and on every state / `steps` / `progress_percent` write-back
the manager makes while draining it — so the CMS System Maintenance page tracks
the operation live over the existing `/auth/events` SSE connection (the topic is
multiplexed onto that one stream) and only falls back to polling
`GET /admin/system/update/status` while the stream is disconnected. Publish
failures are logged and swallowed; the fallback poll is the safety net.

A destructive DB migration requires the admin to **accept the migration risk**
and type the target version to confirm before the request is allowed (enforced in
the UI and recorded in `accepted_migration_risk`). Automatic rollback exists only
**before** migrations run; after a destructive migration, recovery is a backup
restore performed by the manager.

## Configuration

| Env var | Purpose |
| --- | --- |
| `SELFHELP_MANAGER_TOKEN` | Per-instance bearer token for the manager loop. Empty = loop disabled. |
| `SELFHELP_MAINTENANCE_MODE` | Hard switch that forces maintenance mode on; cannot be cleared from the web. |
| `SELFHELP_DISABLE_PLUGINS` | Operator-only system safe-mode switch (also via the canonical `selfhelp:safe-mode` CLI, aliasing `selfhelp:plugin:safe-mode`). |

## Permissions

The system layer adds three permissions: `admin.system.read` (view version,
health, preflight, status, maintenance state), `admin.system.update` (request an
update), and `admin.system.maintenance` (toggle maintenance mode). Add/verify
their rows when changing these routes (see
[api-security-architecture.md](api-security-architecture.md) and
[permission-system-guide.md](permission-system-guide.md)).

## Testing

- Service logic with stubbed persistence:
  `tests/Unit/Service/System/SystemUpdateServiceManagerLoopTest.php` and
  `SystemUpdateServiceRollbackPolicyTest.php`.
- DB-backed manager-loop round-trip (`requested` → claim → status → `succeeded` /
  `failed`, instance-scoped, terminal guard), tagged `#[Group('security')]`:
  `tests/Integration/Service/System/SystemUpdateManagerLoopRoundTripTest.php`.
- Manager routes are public-but-token-gated (not ACL): the in-controller guard is
  unit-tested in `tests/Unit/Controller/Api/V1/Manager/SystemManagerControllerTest.php`
  and proven end-to-end in
  `tests/Controller/Api/V1/Manager/SystemManagerControllerSecurityTest.php`
  (`#[Group('security')]`). Both run in the `--group=security` CI gate.
- Admin routes + permission matrix: `tests/Controller/Api/V1/Admin/SystemControllerTest.php`.
- The full cross-repo scenario → test map (covered / new / nightly) lives in the
  manager's `docs/distribution-architecture-audit-and-coverage.md`.

## Related

- The SelfHelp Manager performs the Docker work — see the `sh-manager`
  repository's `docs/operator/update.md` and `docs/architecture.md`.
- Rehearse the whole CMS-driven update safely (disposable instance, `test`
  channel): `sh-manager` →
  `docs/operator/rehearsal-publish-install-update.md`.
- Frontend admin screen: `sh-selfhelp_frontend` →
  `docs/developer/system-maintenance-admin.md`.
- Design background: `docs/archive/core-installation-and-distribution-plan.md`.
