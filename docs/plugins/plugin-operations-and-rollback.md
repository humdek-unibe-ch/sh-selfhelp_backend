# Plugin Operations & Rollback

Every state change to an installed plugin is **always** recorded as a
single row in `plugin_operations`. The row has a deterministic
lifecycle (`requested → running → succeeded | failed | rolled_back |
cancelled`) and a `logs_json` array of structured log entries.

This document covers the lifecycle, the rollback model, and the
guarantees the orchestrators ([`PluginInstaller`](../../src/Plugin/Lifecycle/PluginInstaller.php),
[`PluginUpdater`](../../src/Plugin/Lifecycle/PluginUpdater.php),
[`PluginUninstaller`](../../src/Plugin/Lifecycle/PluginUninstaller.php))
provide.

## The operation row

| Column                   | Type    | Notes                                                                 |
|--------------------------|---------|-----------------------------------------------------------------------|
| `id`                     | bigint  | Surrogate key.                                                        |
| `id_plugins`             | bigint  | Owning plugin (nullable for `install` of an unknown plugin id).       |
| `type`                   | string  | `install` / `update` / `disable` / `enable` / `uninstall` / `purge` / `rollback` / `repair`. |
| `status`                 | string  | `requested` / `running` / `succeeded` / `failed` / `cancelled` / `rolled_back`. |
| `id_requested_by_users`  | bigint  | Who clicked the button.                                               |
| `snapshots_json`         | json    | Pre + post snapshots of plugin-owned rows.                            |
| `rollback_plan_json`     | json    | Ordered list of inverse actions executed on rollback.                 |
| `logs_json`              | json    | Array of `{ level, message, ts, ctx }` log entries.                   |

## Lifecycle

```
requested → running → succeeded
                  ↘ failed → (rollback) → rolled_back
                  ↘ cancelled
```

1. **`requested`** — created by the admin API. Captures the manifest
   the admin is about to install/update. No side effects yet.
2. **`running`** — flipped by the orchestrator just before any side
   effect (migration, package-manager install, lock-file write). All
   side effects from this point on are tracked in `snapshots_json`.
3. **`succeeded`** — orchestrator finished. Lock file rewritten,
   `plugins.status` set to `enabled`, realtime topic published.
4. **`failed`** — orchestrator caught an exception. If
   `rollback_plan_json` is non-empty, the orchestrator immediately
   executes the rollback plan and flips the status to `rolled_back`.

## The snapshot model

Before any destructive change (DROP TABLE, DELETE, ALTER), the
orchestrator captures a JSON snapshot of every row in every
plugin-owned table:

```jsonc
{
  "before": {
    "styles": [ { "id": 42, "name": "surveyjs", "id_plugins": 7 } ],
    "fields": [ ... ],
    "permissions": [ ... ]
  },
  "after": {
    "styles": [],
    "fields": [],
    "permissions": []
  }
}
```

This snapshot is what makes `rollback_plan_json` deterministic.

## The rollback plan

On `failed`, the orchestrator walks `rollback_plan_json` **in
reverse order** and executes each step:

```jsonc
[
  { "kind": "lockfile-restore", "previousContent": "..." },
  { "kind": "db-rows-restore", "table": "styles", "rows": [ ... ] },
  { "kind": "db-migration-down", "version": "Version20260522063620" },
  { "kind": "composer-remove", "package": "humdek/sh2-shp-survey-js" },
  { "kind": "npm-remove", "package": "@humdek/sh2-shp-survey-js" }
]
```

Each step is idempotent — if the step has nothing to do (the file is
already gone, the migration is already down), it returns `ok`
silently. This is what allows partial rollbacks to converge.

## Cancellation

A `requested` operation may be cancelled by the admin. A `running`
operation **cannot** be cancelled — it must run to completion or fail.
The reasoning: a half-cancelled migration is the worst possible
state, and we cannot guarantee we can roll back partial DDL safely
across all MySQL versions.

## Concurrency

Two operations on the same plugin id are serialized through
[`PluginOperationLock`](../../src/Plugin/Lifecycle/PluginOperationLock.php)
which wraps the Symfony Lock factory bound to
`framework.lock.plugin_operation`. The lock is held for the entire
`running` window. The second caller receives a `409 Conflict`.

## Log entries

Every orchestrator and every check writes structured log entries:

```jsonc
[
  { "ts": "2026-05-22T08:46:57+00:00", "level": "info",  "message": "composer require humdek/sh2-shp-survey-js@1.0.0", "ctx": { "mode": "development" } },
  { "ts": "2026-05-22T08:46:59+00:00", "level": "info",  "message": "migration Version20260522063620 applied" },
  { "ts": "2026-05-22T08:47:00+00:00", "level": "error", "message": "lookup row failed FK check", "ctx": { "table": "lookups", "row_id": 99 } }
]
```

The admin UI's "Logs" tab renders this array verbatim.

## Watching operations

- **Backend**: `GET /cms-api/v1/admin/plugin-operations/{id}` returns
  the current row.
- **Realtime**: `plugin/operation/{id}` Mercure topic fires on every
  state change.
- **CLI**: `php bin/console selfhelp:plugin:operations --tail <id>`
  follows the log stream.

## Related docs

- [Install modes](./install-modes.md)
- [Lock file](./lock-file.md)
- [Realtime & no polling](./realtime-and-no-polling.md)
