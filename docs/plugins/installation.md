# Plugin Installation Guide

This document describes how operators install, update, disable, and remove SelfHelp plugins across all three host runtimes (backend, frontend, mobile).

Audience: operators / DevOps. Plugin authors should read `developer-guide.md` first.

> **Looking for a step-by-step guide for a specific plugin?**
> Each plugin ships its own `docs/install.md` with three concrete recipes (UI registry, UI local paste, terminal one-liner) and explains exactly what the host does on each click. Example: [`sh2-shp-survey-js/docs/install.md`](https://github.com/humdek-unibe-ch/sh2-shp-survey-js/blob/main/docs/install.md).

## 1. Installation modes

Pick a mode per environment.

| Mode          | When                                          | Composer / npm                          |
| ------------- | --------------------------------------------- | ---------------------------------------- |
| `development` | local laptop / preview branches               | the admin UI / CLI runs them in-process  |
| `managed`     | production / CI                               | external; the admin UI just records intent |
| `trusted`     | on-prem / air-gapped with vetted artifacts    | in-process, signed manifests required    |

The default is `managed`. Override via the `SELFHELP_PLUGIN_INSTALL_MODE` env var or the `selfhelp_plugins.install_mode` config key.

## 2. Prerequisites

- Backend: PHP 8.4, Composer 2, Symfony 7.4. Doctrine migrations runnable.
- Frontend: Node 22, npm 10, host frontend repo cloned.
- Mobile: optional. Node 22 + Expo CLI + EAS CLI when bundling per profile.
- Backend env vars:
  - `LOCK_DSN` (default `flock`) — the default lock backend, used by the scheduled-job runner and any unnamed `LockFactory`. `flock` only coordinates processes on a single host, so any multi-process / multi-container / multi-host deployment must point this at a shared backend such as `redis://…`. The bundled Docker stack sets `LOCK_DSN=redis://redis:6379`.
  - `PLUGIN_LOCK_DSN` (default `flock`) — dedicated lock for the plugin lifecycle; same single-host caveat as `LOCK_DSN`, so distributed setups should set it to `redis://…`.
  - `MESSENGER_PLUGIN_OPS_DSN` (default `doctrine://default?queue_name=plugin_ops&auto_setup=true`) — transport for the `plugin_ops` queue. Production may swap to `redis://…?stream=plugin_ops`.
  - `SELFHELP_PLUGIN_INSTALL_MODE` (`development` / `managed` / `trusted`) — defaults to `managed`.
  - `SELFHELP_ALLOW_WEB_PLUGIN_INSTALL=true` + `APP_ENV=dev` — required to expose direct-install via the web UI (otherwise only the CLI / Messenger worker can install).
  - `SELFHELP_PLUGIN_TRUSTED_KEYS` — `keyId=base64pubkey;keyId2=…` Ed25519 public keys for signature verification. See `docs/plugins/trusted-keys.md`.
  - `SELFHELP_PLUGIN_REQUIRE_SIGNATURE` (default `true`) — set to `false` only for first-boot dev when installing untrusted plugins.
  - `SELFHELP_PLUGIN_ARCHIVE_MAX_BYTES` (default 20 MB) — max `.shplugin` upload size.
  - `SELFHELP_PLUGIN_ARCHIVE_RETENTION_DAYS` (default 7) — staging-dir retention used by `selfhelp:plugin:cleanup-archives`.
  - `SELFHELP_PLUGIN_ALLOW_COMPOSER_SCRIPTS` (default `false`) — accept standalone archives whose `backend/package/composer.json` declares a `scripts` block. The Messenger worker always passes `--no-scripts` to `composer require`, so toggling this only relaxes the manifest-time rejection; useful for local developer ergonomics where the plugin keeps dev-only `phpstan`/`phpunit` scripts. Production hosts should leave this off.
  - `SELFHELP_PLUGIN_PRIVATE_REGISTRY_TOKEN` — only if you add a private registry source.

## 2.1 Install paths visible in the admin UI

The admin "Plugins" page exposes three tabs:

| Tab            | What it does                                                                                                                                                                                                                                                                                  |
| -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Installed**  | Lists currently installed plugins, their status, compatibility, and per-row actions (enable / disable / uninstall / purge). Rows whose installed version is older than any registry-advertised version render an inline **Update available** badge plus a one-click **Update** button — there is no separate "Updates" tab, the listing endpoint embeds `availableUpdate` directly. |
| **Available**  | Walks every enabled **Source** and lists registry-advertised plugins. Each row has a one-click **Install** button (calls `POST /admin/plugins/install` with `source=registry`).                                                                                                                |
| **Sources**    | CRUD over `PluginSource` rows. The seeded `humdek-public` source (system, read-only) points at the official Humdek registry; admins can add private/staging sources alongside it.                                                                                                              |

The active tab is **persisted to the URL** (`?tab=available`,
`?tab=sources`) so a page refresh or shared link lands on the same
tab.

Plus an **Install plugin** button at the top-right that opens a modal
with four source tabs (priority order):

1. **From registry** — pointer to the Available tab.
2. **From URL** — paste a published `plugin.json` URL.
3. **Upload .shplugin** — drag-and-drop / file picker, the **main
   manual install path**. The host pre-validates via
   `POST /admin/plugins/inspect-archive` before showing the Install
   button, so the admin sees compatibility + capability + signature
   status before dispatching. The preview also surfaces the
   `.shplugin`'s `archive.mode` (`connected` vs `standalone`),
   whether the backend Composer package is bundled, and the
   resulting Composer install mode (`composer-packagist` for
   connected, `composer-path-repository` for standalone). Both modes
   still require Composer to reach a package source for the plugin's
   third-party PHP dependencies at install time — `.shplugin` is not
   a fully offline install bundle. See
   [`shplugin-archive.md`](./shplugin-archive.md#archive-modes)
   for the mode semantics.

   When the upload is signed by a publisher key that is not yet in
   `SELFHELP_PLUGIN_TRUSTED_KEYS`, the inspect response surfaces an
   `Unknown publisher key` panel inside the preview. Pasting the
   matching base64 Ed25519 public key and clicking **Re-test** runs
   verification with that key for the current request only — neither
   env nor lock files are mutated. See
   [`trusted-keys.md`](./trusted-keys.md#per-request-trust-helper-admin-ui)
   for the full operator playbook.
4. **Paste JSON / Developer mode** — Monaco editor, explicitly
   labelled "Developer / debugging only". Skips signature verification
   for hand-crafted manifests.

All four source tabs POST to `/admin/plugins/install` and dispatch a
single `InstallPluginMessage` on the `plugin_ops` Symfony Messenger
transport. There is no chained finalize request; the worker streams
progress over Mercure.

### Default source: `humdek-public`

Every install ships with a system-managed plugin source named
`humdek-public` pointing at
<https://humdek-unibe-ch.github.io/sh2-plugin-registry/>. It is
seeded by Doctrine migration `Version20260522110723` with
`trust_level = official`, `enabled = 1`, and `is_system = 1`. The
admin API rejects edits/deletes against system rows; only the
`enabled` flag can be toggled. Disable the row to hide the official
catalogue without deleting it.

Current runtime detail: the canonical list of sources still lives in
the `plugin_sources` table and is exposed via
`/cms-api/v1/admin/plugins/sources`. If you need the seeded official
source to point somewhere else, set
`SELFHELP_PLUGIN_DEFAULT_REGISTRY_URL` in the backend env. That env var
overrides the effective URL of the system-managed `humdek-public`
source without replacing the DB-backed source model for custom/private
registries.

API surface:

| Endpoint                                                   | Verb       | Purpose                                                                                                                                |
| ---------------------------------------------------------- | ---------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| `/cms-api/v1/admin/plugins`                                | GET        | List installed plugins with install mode + safe-mode flags. Each row embeds `availableUpdate` when a strictly-newer version exists in an enabled source. |
| `/cms-api/v1/admin/plugins/available`                      | GET        | List registry-advertised plugins not yet installed.                                                                                    |
| `/cms-api/v1/admin/plugins/install`                        | POST       | Unified install. JSON body for `source ∈ {registry,url,paste}`; multipart `archive=<file>` for `source=archive` (.shplugin upload).    |
| `/cms-api/v1/admin/plugins/inspect-archive`                | POST       | Validate an uploaded `.shplugin` without installing. Returns `{manifest, compatibility, capabilities, signature, archive}` (with `signature.status ∈ {verified,unsigned,untrusted-key,invalid}`). |
| `/cms-api/v1/admin/plugins/{id}/update`                    | POST       | Unified update. Same source shapes as `install`.                                                                                       |
| `/cms-api/v1/admin/plugins/sources`                        | GET / POST | CRUD over registries the host trusts.                                                                                                  |

All install / update endpoints respond `202 Accepted` with the
`plugin_operations` row id and immediately dispatch an
`InstallPluginMessage` / `UpdatePluginMessage` on the `plugin_ops`
Symfony Messenger transport. Progress is published to Mercure on the
`selfhelp/plugins/state` topic; there is no chained finalize step
exposed to the browser. The internal `selfhelp:plugin:run-operation`
CLI command is the documented escape hatch for managed-mode workers
that need to finalise an operation outside the worker process.

## 3. Install a plugin (development mode)

The Messenger worker runs everything in-process — no operator handoff.

### 3.0 Local backend env wiring

`config/services.yaml` reads the install flags through Symfony's env
processors, so they must live in a real env file (Dotenv) instead of
being exported in your shell — `getenv()` returns `false` for shell
exports under `usePutenv(false)`. The repository ships sane dev
defaults in `.env.dev` and recognises overrides in `.env.local`:

```ini
# .env.dev (committed)
SELFHELP_PLUGIN_ALLOW_COMPOSER_SCRIPTS=1
SELFHELP_PLUGIN_INSTALL_MODE=development
SELFHELP_ALLOW_WEB_PLUGIN_INSTALL=true

# .env.local (gitignored, per-developer overrides)
SELFHELP_PLUGIN_TRUSTED_KEYS="dev=JJwrsNLigXbDOyE0Ifj6W8dOFuiGTZ/BP0TiVmrjyLY="
```

`SELFHELP_PLUGIN_ALLOW_COMPOSER_SCRIPTS=1` makes the validator accept
`.shplugin` archives whose bundled `backend/package/composer.json`
carries dev-only `scripts` (`phpstan`, `phpunit`). The Messenger worker
still calls `composer require --no-scripts`, so the flag only relaxes
the manifest-time rejection — it does not allow scripts to execute.
Leave it at the default `0` in any non-dev environment.

`SELFHELP_PLUGIN_INSTALL_MODE=development` flips the install pipeline
to in-process composer + finalize; `managed` is the production default
where the admin API persists the operation but a human runs composer.
`SELFHELP_ALLOW_WEB_PLUGIN_INSTALL=true` is the explicit opt-in for
the web-triggered install path; combined with `APP_ENV=dev` it lets the
admin UI's **Install** button drive the full pipeline without a CLI.

After editing env files, restart both the PHP dev server and the
Messenger worker — Symfony only loads Dotenv on process start.

### 3.1 Install via the admin UI

1. Place / clone the plugin repo somewhere reachable by Composer (path
   repository, packagist, or private packagist mirror). For `.shplugin`
   archives this step is skipped — the uploaded archive carries its own
   composer path repository pointer.
2. From the backend repo run a Messenger worker so it can consume the
   `plugin_ops` transport:

```bash
APP_ENV=dev php bin/console messenger:consume plugin_ops -vv
```

3. Open `http://localhost:3000/admin/plugins → Install plugin →
   Upload .shplugin`, drop the archive, and click **Install**. The host
   pre-validates via `POST /admin/plugins/inspect-archive` so you see
   the manifest + capability + signature preview before dispatch.
4. Once the worker reports success, click **Enable** on the plugin row.
   The host regenerates `config/selfhelp_plugin_bundles.php` and the
   bundle becomes part of the kernel on the next request.

### 3.2 Install via the CLI

Equivalent to the UI path but useful for scripted setups:

```bash
php bin/console selfhelp:plugin:install /abs/path/to/plugin.json
```

The single command dispatches `InstallPluginMessage` and returns
immediately. The Messenger worker:

- validates the manifest against `docs/plugins/plugin-manifest.schema.json`,
- checks `compatibility.*` ranges and capabilities,
- recomputes + verifies the canonical signed payload + Ed25519 signature,
- runs `composer require <backend.composer.package>:<version>` (with an
  optional one-off `composer config repositories.<slug>` entry if the
  manifest declares a custom repo),
- streams every line of composer output into
  `plugin_operations.logs_json` so Mercure subscribers see live progress,
- for `.shplugin` uploads: copies the validated archive into
  `var/plugins/<id>-<ver>/installed/` and atomically replaces
  `public/plugin-artifacts/<id>-<ver>/` with the runtime bundles,
- for `registry` / `url` installs: downloads `runtime.entrypointUrl`
  (and `runtime.stylesheetUrl` if present) via
  `PluginRuntimeArtifactFetcher`, verifies each file's SHA-256
  against the signed `checksums.frontendEsm` / `checksums.frontendCss`,
  and writes them into `public/plugin-artifacts/<id>-<ver>/` so the
  host self-serves the bundle (mandatory because plugin bundles import
  host-only paths like `/api/plugins/runtime-shim/*` that only resolve
  same-origin),
- runs the plugin's Doctrine migrations (still gated by
  `PluginMigrationGuard`),
- regenerates `config/selfhelp_plugin_bundles.php`,
- reconciles `plugin.json#apiRoutes` with `api_routes` /
  `rel_api_routes_permissions` via `PluginApiRouteSynchronizer` so
  the plugin's HTTP endpoints become part of the DB-backed route
  collection (tagged with `id_plugins`),
- updates `selfhelp.plugins.lock.json` (with signing.keyId + signature),
- dispatches `PluginInstalledEvent`.

No frontend rebuild is needed: the host loads the plugin's ESM runtime
bundle at request time from the same `/plugin-artifacts/...` path that
the promoter wrote. The mobile app still uses `plugins:sync` when
building per EAS profile.

### 3.3 Local sibling checkout (`plugins/<plugin-id>`)

When the plugin lives in the same workspace as the host repos, for
example:

```text
<workspace>/
├── sh-selfhelp_backend
├── sh-selfhelp_frontend
└── plugins/
    └── sh2-shp-survey-js/
```

the recommended development workflow is:

1. Run the plugin's one-time attach command:

```bash
cd plugins/sh2-shp-survey-js
node scripts/install-local.mjs --symlink
```

The script writes a temporary development manifest whose
`backend.composer.repository` points at the local plugin backend, then
installs/finalizes the plugin through the normal Messenger pipeline,
enables it, and prints the frontend runtime command. The host root
`composer.json`, `composer.lock`, and `config/bundles.php` are not
modified by this dev attach path; the worker uses the isolated plugin
Composer root under `var/plugin-composer/`. Because this local attach
path resolves the plugin through a pasted local manifest while the host
install mode is `development`, the persisted plugin row uses
`frontend.runtime.devEntrypointUrl` as the active runtime URL.

2. Keep the plugin runtime dev server running:

```bash
npm --prefix frontend run dev:runtime
```

This serves `frontend/dist` from the Vite watch build at the manifest
dev URL and emits reload events. The Next.js host runtime listens to
those events and re-imports the plugin bundle with cache busting, so
normal plugin UI edits do not require rebuilding an archive,
reinstalling the plugin, or restarting the host frontend.

Mobile builds still consume `selfhelp.plugins.mobile.lock.json` per
EAS profile.

### 3.4 Private plugin distributed as a `.shplugin`

When a plugin's backend bundle is intentionally not on Packagist
(internal / private orgs, deterministic publishing snapshots) the
publisher ships a standalone `.shplugin` plus the matching publisher
public key out of band. The end-to-end recipe:

1. **Plugin author builds a standalone archive.** From the plugin
   checkout:

```bash
node scripts/build-shplugin.mjs --mode standalone
# → dist/<id>-<version>.shplugin
```

   The author shares both the `.shplugin` file AND the matching
   base64 Ed25519 public key + `keyId` out of band — email, SFTP, an
   internal package portal, whatever the org already uses for vetted
   secrets. The keypair was generated with the registry's
   `npm run keygen` helper and the publisher signed the archive in
   their CI.

2. **Operator drops the archive in the admin UI.** From a host
   browser:

   **Admin → Plugins → Install plugin → Upload .shplugin.** Drag
   the file in. The host POSTs it to
   `/cms-api/v1/admin/plugins/inspect-archive` and shows a preview
   card — manifest, compatibility, capabilities, signature status,
   archive mode (`standalone`), backend package + version.

3. **If the keyId is unknown, paste the publisher key.** The first
   inspect response carries `signature.unknownKey.keyId=<id>`
   because the new key is not in `SELFHELP_PLUGIN_TRUSTED_KEYS` yet.
   A yellow **Unknown publisher key** panel appears inside the
   preview. Paste the publisher's base64 public key into the
   textarea and click **Re-test with this key**. The host runs
   verification with that key merged on top of the env-resolved
   trusted-keys set for the current request only — neither env nor
   lock files are mutated. The preview flips to
   `signature.status=verified` and the **Install** button enables.

4. **Click Install.** The host queues `InstallPluginMessage` on the
   `plugin_ops` Messenger transport. The worker runs
   `composer require <package>:<version>` against the staged
   `backend/package/` Composer path repository; Composer pulls the
   plugin's third-party PHP dependencies (`symfony/*`, `doctrine/*`,
   …) from Packagist or the host's configured private mirror. The
   plugin lands once `finalize()` completes (lock-file written,
   bundles file regenerated, migrations executed).

5. **(Optional) Make the trust persistent.** Click **Copy env line**
   in the trust-helper panel. The host puts
   `SELFHELP_PLUGIN_TRUSTED_KEYS=<keyId>=<base64>` on the clipboard.
   Paste it into `.env.local` (merge with existing keys using the
   `;`-separator format) and restart the host process. Subsequent
   inspect / install calls trust the publisher's key without the
   per-request override.

The trust helper is intentionally minimal — it never persists a key
on its own. See [`trusted-keys.md`](./trusted-keys.md#per-request-trust-helper-admin-ui)
for the full operator playbook.

## 4. Install a plugin (managed mode)

The admin UI never invokes Composer / npm in this mode. The Messenger
worker emits a runbook into `plugin_operations.logs_json` and stops at
that point. A CLI/CD operator then runs the composer step + finalize.

1. The admin clicks **Install** (registry / URL / paste / archive). The
   backend resolves the source, validates compatibility + capabilities
   + signature, persists a `plugin_operations` row with status
   `requested`, and dispatches `InstallPluginMessage`. Mercure
   publishes the request immediately.
2. The Messenger worker picks up the message, marks the operation
   `running`, and writes a runbook entry into `logs_json` like:

```bash
# 1. Backend: pin the package version (the runbook prints the exact line)
composer require humdek/sh2-shp-survey-js:0.1.0 --no-interaction --no-scripts
# 2. Commit + deploy
# 3. Tell the backend the install is complete:
php bin/console selfhelp:plugin:run-operation <operationId>
```

3. After deployment, the operator runs
   `php bin/console selfhelp:plugin:run-operation <id>` which calls
   `PluginInstaller::finalize()`. The host:
   - asserts the bundle class is autoloadable,
   - regenerates `config/selfhelp_plugin_bundles.php`,
   - runs plugin Doctrine migrations within the operation transaction,
   - updates the lock file (with `signing.keyId` + `signature` +
     `migrations[].sha256`),
   - finalizes the operation (`status=succeeded`),
   - dispatches `PluginInstalledEvent` so subscribers can refresh
     caches.

If any step fails, the operation is recorded as `failed` and the
previous lock-file snapshot in `plugin_operations.snapshots_json` is
available for `selfhelp:plugin:rollback <opId>` to restore.

### 4.1 Bundle-class autoload gate

Before regenerating `config/selfhelp_plugin_bundles.php`,
[`PluginInstaller::finalize()`](../../src/Plugin/Lifecycle/PluginInstaller.php)
and [`PluginUpdater::finalize()`](../../src/Plugin/Lifecycle/PluginUpdater.php)
verify that the manifest's `backend.bundleClass` is autoloadable via
`class_exists()`. If it is not, the operation is marked `failed` with a
`PRECONDITION_FAILED` error and the bundles file is left untouched. The
admin UI surfaces the message:

> Backend bundle class "…\HumdekSurveyJsBundle" is not autoloadable
> after composer require. The Messenger worker reported success but the
> bundle did not register; check composer.json + autoload-dump.

This guard exists because a previous shape of the installer would
unconditionally write the missing class into the generated bundles
file, after which `kernel->registerBundles()` died on every
subsequent request. The recovery procedure is in §10 and §11.

## 5. Update a plugin

```bash
php bin/console selfhelp:plugin:update /abs/path/to/plugin.json
```

Or via the admin UI: `Admin → Plugins → Updates → Update`.

The update flow:

1. The service compares the resolved manifest's `id` against the
   URL-pinned plugin id (`expectedPluginId`). A mismatch is refused
   with a 422 — this prevents accidentally updating plugin A with the
   manifest of plugin B.
2. SemVer diff is computed; major updates require `--force-major`.
3. Snapshots the current lock file + manifest into
   `plugin_operations.snapshots_json` for rollback.
4. Dispatches `UpdatePluginMessage`. The worker runs
   `composer require <package>:<newVersion>`, promotes any new
   `.shplugin` archive, then calls `PluginUpdater::finalize()` which
   runs **incremental Doctrine migrations** (using the same plan
   calculator as `doctrine:migrations:migrate` — already-applied
   versions in `doctrine_migration_versions` are skipped, only
   pending versions execute) and rewrites the lock file.
5. Dispatches `PluginUpdatedEvent`.

### 5.1 Install endpoint auto-routes to update

`POST /admin/plugins/install` is the single entry point for the
"install or update" UX. The admin doesn't have to know up front
whether the plugin is fresh or already on disk — the backend
inspects the existing `plugins` row and short-circuits via the
`installAction` discriminator:

| Existing plugin row | Requested version | `installAction`        | HTTP   | What runs                                                                                                    |
| ------------------- | ----------------- | ---------------------- | ------ | ------------------------------------------------------------------------------------------------------------ |
| Absent              | any               | `install_dispatched`   | `202`  | `PluginInstaller::request()` → `InstallPluginMessage`. Standard fresh install.                                |
| Same version        | `=`               | `already_installed`    | `200`  | **No-op.** Returns the existing row's version + a `message` for the UI. Nothing is queued, no migrations.    |
| Older installed     | newer (semver)    | `update_dispatched`    | `202`  | Auto-routes to `PluginUpdater::request()` → `UpdatePluginMessage`. UI gets `existingVersion`, `requestedVersion`, `diffKind` so the toast reads `<id>: x.y.z → a.b.c`. Major bumps still require the `forceMajor` flag — the updater itself rejects with 422 + a force-major hint when it's missing. |
| Newer installed     | older             | (refused)              | `422`  | `RequestValidationException` "refusing to install older version — use rollback".                              |

The frontend's install dialog reads `installAction` and branches the
notification copy (`Already installed` / `Update queued` /
`Install queued`). The `redirectedToUpdate` boolean is kept for
backwards compatibility; new code should switch on `installAction`.

## 6. Disable / re-enable

```bash
php bin/console selfhelp:plugin:disable sh2-shp-survey-js
php bin/console selfhelp:plugin:enable sh2-shp-survey-js
```

Disabling unregisters the Symfony bundle, hides admin routes, and removes the plugin contributions from the frontend runtime — without deleting plugin data.

### 6.1 Why install does not auto-enable (by default)

The host treats install and enable as two distinct lifecycle steps.

| Step    | What changes on disk / in the DB                                                                                                                                                                                                                                       | What the user sees                              |
| ------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------- |
| Install | New row in `plugins` (`enabled = 0`), plugin migrations run, `permissions`/`styles`/`lookups`/`api_routes` rows tagged with `id_plugins`, `config/selfhelp_plugin_bundles.php` regenerated, lock file written. The Symfony bundle is registered but the route loader, style schema, frontend manifest endpoint, and admin menu all gate on `enabled = 1`. | Row appears under **Installed** with status `disabled`. |
| Enable  | `plugins.enabled = 1`, `enabled_at` set, every plugin-surface Redis category invalidated (`CATEGORY_{PLUGINS,API_ROUTES,STYLES,PERMISSIONS,ROLES,USERS,LOOKUPS,PAGES}`), `config/selfhelp_plugin_bundles.php` regenerated, lock file refreshed, `PluginEnabledEvent` published over Mercure on `selfhelp/plugins/state` so the admin UI refreshes without a page reload. | Plugin appears in the side menu, admin pages mount, frontend manifest endpoint includes the plugin's `frontendRuntimeUrl`. |

Why the split exists:

- **Trust review** — the operator who clicked **Install** is not necessarily the security-aware admin who decides whether to expose the plugin's surface. Keeping enable separate gives that admin a chance to inspect trust level, capabilities, signing key id, requested external hosts, and the declared `dataAccess.read`/`dataAccess.write` lists on the **Installed** tab before turning the plugin on.
- **Symmetry with the lifecycle** — disable already exists and is reversible. Making install also leave the plugin disabled means the two terminal states of the install/disable axis are identical (`enabled=0`), which simplifies recovery scripts.

The **Install plugin** modal exposes an **Enable plugin after install** switch that defaults to ON for the upload (`.shplugin` / paste / URL) flows. Tick it OFF if you want to land the plugin in disabled state. The CLI `selfhelp:plugin:install` command never auto-enables — operators run `selfhelp:plugin:enable <id>` explicitly. The plugin development fast-path (e.g. SurveyJS's `node scripts/install-local.mjs --symlink`) calls `selfhelp:plugin:enable` itself so a fresh dev checkout is usable immediately.

The frontend / admin menu is filtered by `enabled = 1` at two layers:

- `App\Controller\Api\V1\Plugin\PluginManifestController::manifest()` walks `PluginRegistryService::getEnabled()` only. Disabled plugins are absent from the response.
- The host Next.js `PluginRuntime` then iterates `manifest.plugins.filter((p) => p.enabled)`, so even if a disabled plugin slipped through the API filter, the runtime would not call its `register()` function.

That is why the SurveyJS menu items appear only after **Enable** is clicked.

### 6.2 Troubleshooting "Plugin could not be mounted"

The host shows a red mount-failure banner when a plugin is enabled (so the host runtime tries to load it) but the JS runtime bundle cannot be fetched / parsed / registered. The error message text is generated in [`PluginRuntime.ts`](../../../sh-selfhelp_frontend/src/app/components/frontend/plugin-runtime/PluginRuntime.ts):

```text
Plugin "<name>" v<version>: import of "<runtimeUrl>" failed (<reason>).
Verify the URL is reachable and serves a valid ESM module.
```

Where each token comes from:

| Token            | Source                                                                                                                                                                                                                                                                                                                                                                                                          |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `<name>`         | `plugins.name` column in the host DB — captured by `PluginInstaller::finalize()` from `plugin.json#name` at install time.                                                                                                                                                                                                                                                                                       |
| `v<version>`     | The infamous "Expected v0.2.2". Comes from `plugins.version` in the host DB, captured by `PluginInstaller::finalize()` from `plugin.json#version` at install time. The same value is returned to the browser by `GET /cms-api/v1/plugins/manifest` as the `version` field. Bumping `plugin.json#version` without re-installing leaves the host's expected version on the previous number — that is the only way to make the two diverge intentionally. |
| `<runtimeUrl>`   | `plugins.frontend_runtime_url` column, resolved during install/update finalization. Local development installs that come from a pasted local manifest (the sibling-checkout / `--symlink` path) use `frontend.runtime.devEntrypointUrl` (e.g. `http://localhost:5174/<pluginId>/plugin.esm.js`). **Every other install source** — `.shplugin` archive, registry, direct URL — resolves to the promoted `/plugin-artifacts/<id>-<ver>/...` path on the host: archives are promoted by `PluginArchivePromoter`, registry / URL installs by `PluginRuntimeArtifactFetcher` (downloads `runtime.entrypointUrl`, verifies `checksums.frontendEsm`, writes into `public/plugin-artifacts/<id>-<ver>/`). The browser never imports plugin bundles cross-origin because their internal imports (`/api/plugins/runtime-shim/*`) resolve against the importer's origin and would 404 on any CDN. |
| `<reason>`       | The browser's `import()` rejection. The common failures are listed below.                                                                                                                                                                                                                                                                                                                                       |

| Reason                                                  | What it means                                                                                                                                                                                                                                                                                                                                                              | Fix                                                                                                                                                              |
| ------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Failed to fetch dynamically imported module`           | The browser could not connect to or load `<runtimeUrl>` at all. For a `localhost:<port>` URL this is almost always the plugin's dev runtime server being down. For a `/plugin-artifacts/...` URL it is the file missing under `public/plugin-artifacts/<id>-<ver>/` — i.e. the install never promoted the archive.                                                          | Dev: start the plugin's runtime dev server (e.g. `npm --prefix frontend run dev:runtime` for SurveyJS). Prod: re-run install so the archive is promoted, or run `selfhelp:plugin:doctor` to confirm the bundle is on disk. |
| `Plugin "<x>": host expected v<n> but the bundle reports v<m>` | The JS bundle DID load but `definePlugin({ version: '<m>' })` does not match the host's expected `<n>`. Out-of-sync publisher (build artifact made from a different `plugin.json` version) or a stale browser cache.                                                                                                                                                       | Republish / rebuild the bundle from the same `plugin.json`, or hard-refresh (`Ctrl+Shift+R`) to clear cached ESM modules.                                        |
| `runtime bundle … does not export a register() function` | The bundle loaded, exported something, but no `register` symbol. Typical when a plugin author renames their entry point or forgets to re-export `default.register`.                                                                                                                                                                                                       | Check the plugin's `frontend/src/index.ts` and rebuild.                                                                                                          |
| `register() threw`                                       | The plugin's own `register()` callback raised. The plugin code is at fault.                                                                                                                                                                                                                                                                                                | The mount-failure banner expands to show the inner error message — pass it to the plugin author.                                                                 |

The host's caches and Mercure topics are fine on the enable path — the visible failure is always one of the four rows above. When SurveyJS reports "Expected v0.2.2", you are virtually always looking at the first row: the dev runtime server is not running, the host's database row is fine, the version string is just being read from the host DB and rendered alongside the failed-import diagnostic.

## 7. Uninstall (non-destructive)

```bash
php bin/console selfhelp:plugin:uninstall sh2-shp-survey-js
```

Removes the bundle, deletes the plugin row, and clears feature flags. Does NOT drop plugin-owned tables.

## 8. Purge (destructive)

```bash
php bin/console selfhelp:plugin:purge sh2-shp-survey-js --confirm
```

Drops plugin-owned tables, removes the plugin backend package from `var/plugin-composer/vendor/`, deletes the plugin's lookup contributions, and removes the lock entry. The `--confirm` flag is mandatory; when run interactively the operator must additionally re-type the plugin id when prompted.

### 8.1 What purge cleans in `doctrine_migration_versions`

Plugin migrations are tracked in the shared `doctrine_migration_versions` table — every plugin migration row's `version` column stores the **FQCN** of the migration class, e.g. `Humdek\SurveyJsBundle\Migrations\Version20260522063620`. `PluginPurger::deletePluginMigrationVersions()` walks the manifest's `backend.migrationsNamespace` (e.g. `Humdek\SurveyJsBundle\Migrations`) and deletes every row whose `version` starts with that namespace prefix:

```sql
DELETE FROM doctrine_migration_versions WHERE LOCATE('Humdek\\SurveyJsBundle\\Migrations\\', version) = 1;
```

This is necessary because if those rows stayed behind, the next install of the same plugin id would call `PluginMigrationsRunner::migrate()` and Doctrine would return `NoMigrationsToExecute` for migrations whose version strings are already recorded — so the plugin's tables would never be re-created and the plugin would end up half-installed (`plugins` row present, schema missing). Uninstall does **not** delete migration version rows on purpose: uninstall is reversible and the data is preserved; purge is the destructive path that wipes them.

We deliberately do **not** add an `id_plugins` column to `doctrine_migration_versions`. The Doctrine schema for that table is fixed and stable across versions, the migration FQCN already encodes the owner (each plugin is required to declare a unique `backend.migrationsNamespace`), and the namespace-prefix join is exact — adding a sidecar column would deviate from standard Doctrine practice without any new capability.

## 9. Doctor

```bash
php bin/console selfhelp:plugin:doctor
php bin/console selfhelp:plugin:doctor --ci --json   # for CI
```

Runs every plugin's `health.serviceId` checks plus the host's compatibility checks. Exits non-zero when any check returns `error`.

## 10. Safe mode

```bash
php bin/console selfhelp:plugin:safe-mode --enable
# fix something
php bin/console selfhelp:plugin:safe-mode --disable
```

Boots the backend with `config/selfhelp_plugin_bundles.php` ignored. Useful when a plugin's bundle crashes the kernel.

There are also two emergency switches when the CLI itself cannot boot:

| Mechanism | How to set | When |
|-----------|------------|------|
| `SELFHELP_DISABLE_PLUGINS=true` env var | Add to `.env.local` | Use when even the safe-mode command cannot run because the kernel is dead. Skips the include of `selfhelp_plugin_bundles.php` regardless of the lock file. |
| `var/plugin_safe_mode.lock` file       | Created by the `--enable` command above (or `touch var/plugin_safe_mode.lock` by hand) | Persistent across restarts without editing `.env`. Removed by `--disable`. |

The boot-time short circuit is documented in `config/bundles.php`.

## 11. Lock file recovery

If the lock file goes out of sync with the database:

```bash
php bin/console selfhelp:plugin:repair
```

The command rebuilds `selfhelp.plugins.lock.json` from the `plugins`
table and **also drops stale entries** whose plugin id is no longer in
the table — so a plugin row deleted by hand, by purge, or by the recovery
procedure below disappears from the lock file too. The bundles file is
regenerated from the same DB snapshot.

### 11.1 Recovery from a half-written install (kernel won't boot)

If a finalize predating the §4.1 autoload gate left the bundles file
referencing a class the autoloader cannot resolve, `kernel->registerBundles()`
raises `Class "…HumdekSurveyJsBundle" not found` on every request. The
recovery is:

```bash
# 1. Take the kernel back. Either env-disable plugins:
echo 'SELFHELP_DISABLE_PLUGINS=true' >> .env.local
# or write the lock file directly:
touch var/plugin_safe_mode.lock
php bin/console cache:clear --env=dev --no-warmup

# 2. Inspect the failed operation + plugin row:
php bin/console selfhelp:plugin:status

# 3. Cancel any stale `requested`/`running` operations. Either purge:
php bin/console selfhelp:plugin:purge <pluginId> --confirm --i-understand-this-is-irreversible
# or delete by hand if `purge` itself fails (shouldn't on the new code path):
# UPDATE plugin_operations SET status='cancelled', finished_at=NOW()
# WHERE plugin_id='<pluginId>' AND status IN ('requested','running');
# DELETE FROM plugins WHERE plugin_id='<pluginId>';

# 4. Resync the artefacts (bundles file + lock file):
php bin/console selfhelp:plugin:repair

# 5. Disable safe mode and re-clear cache:
php bin/console selfhelp:plugin:safe-mode --disable
php bin/console cache:clear --env=dev --no-warmup
```

Once the operator has fixed the underlying composer/npm gap (typically
by running `node scripts/install-local.mjs` from the plugin checkout)
the install can be re-attempted from the admin UI or
`selfhelp:plugin:install`.

## 12. Operator runbook for a fresh install

```bash
# Backend (one-time)
composer install
php bin/console doctrine:migrations:migrate -n
# Start the plugin operations Messenger worker (one per host)
php bin/console messenger:consume plugin_ops --time-limit=3600

# Backend (per plugin)
php bin/console selfhelp:plugin:install /abs/path/to/plugin.json
# or, for a packaged release:
# upload the .shplugin archive in Admin → Plugins → Install plugin

# Frontend
cd ../sh-selfhelp_frontend
npm ci
npm run build
npm start
# No per-plugin step: the host streams /plugin-artifacts/<id>-<ver>/plugin.esm.js
# directly from the backend at request time.

# Mobile (optional, per EAS profile)
cd ../sh-selfhelp_mobile
npm ci
SELFHELP_API_TOKEN=… npm run plugins:sync -- production-default --backend https://cms.example.com
npm install
eas build --profile production-default
```

## 13. What gets created where during install

Quick reference for operators. The unified pipeline writes the same
files/folders/DB rows regardless of source. The full breakdown lives in
[`publishing-workflow.md` §7](./publishing-workflow.md#7-what-happens-on-the-host-when-admin-clicks-install).

| Location                                            | What lands there                                                                                         |
|-----------------------------------------------------|----------------------------------------------------------------------------------------------------------|
| `var/plugin-composer/composer.json` + `composer.lock` | Plugin-only Composer root. Auto-managed by `PluginComposerRoot::ensure()` on first install. **Never edit by hand.** |
| `var/plugin-composer/vendor/<package>/`             | `composer require` result for plugin packages. Resolved by a SECONDARY autoloader registered at boot.    |
| `var/plugins/<id>-<ver>/staging/<random>/`          | `.shplugin` uploads land here first. Deleted on success; TTL-purged on failure.                          |
| `var/plugins/<id>-<ver>/installed/`                 | Promoted from staging; canonical artefact location for `.shplugin` installs.                             |
| `public/plugin-artifacts/<id>-<ver>/`               | Web-served runtime ESM + CSS. The frontend loads `/plugin-artifacts/...` at request time (no rebuild).   |
| `config/selfhelp_plugin_bundles.php`                | GENERATED. Registers the plugin's Symfony bundle. Atomically rewritten on every install/update/uninstall.|
| `selfhelp.plugins.lock.json`                        | GENERATED. Authoritative lock with `keyId`, `signature`, migration hashes, capabilities, runtime URLs.   |
| `plugins` table                                     | One row per installed plugin.                                                                            |
| `plugin_operations` table                           | One row per lifecycle operation (audit + rollback descriptor).                                           |
| `plugin_feature_flags` table                        | One row per declared feature flag.                                                                       |
| `api_routes` + `rel_api_routes_permissions`         | Plugin-declared routes. Cleared on uninstall.                                                            |
| `lookups` + `styles` + `styles_fields`              | Plugin contributions tagged with `id_plugins`. Cleared on uninstall (styles) / purge (lookups).          |
| Plugin-owned tables (`survey_runs`, …)              | Created by plugin migrations. Survive uninstall; cleared by purge only.                                  |

> **Important:** The host's own `composer.json`, `composer.lock`, and
> `vendor/` are NEVER touched by plugin install/update/uninstall.
> Plugin packages live in `var/plugin-composer/vendor/<package>/` and
> are resolved through a secondary `Composer\Autoload\ClassLoader`
> registered immediately after the host autoloader at boot. See §15
> below.

The matching `.gitignore` entries on the host (already in place) are:

```gitignore
/vendor/
/var/
/public/plugin-artifacts/
/selfhelp.plugins.lock.json*
/config/selfhelp_plugin_bundles.php
```

`/var/` already covers `var/plugin-composer/` — nothing leaks into host VCS.

## 15. Plugin Composer root (`var/plugin-composer/`)

Plugin packages live in an isolated Composer root under
`var/plugin-composer/`. The host's `composer.json` / `composer.lock` /
`vendor/` are read-only with respect to plugins.

### 15.1 Layout

```
var/plugin-composer/
├── composer.json     # generated on first install; do NOT edit
├── composer.lock     # written by Composer
└── vendor/
    ├── autoload.php  # secondary ClassLoader, registered at boot
    └── <package>/    # one dir per plugin package
```

### 15.2 Boot wiring

`public/index.php`, `bin/console`, and `tests/bootstrap.php` each call

```php
\App\Plugin\PackageManager\PluginAutoloaderBootstrap::register(dirname(__DIR__));
```

immediately after the host's `vendor/autoload_runtime.php` (or
`vendor/autoload.php`). The helper:

1. checks for `var/plugin-composer/vendor/autoload.php`;
2. requires it (Composer's autoload returns the `ClassLoader`);
3. **unregisters and re-registers the loader with `prepend=false`**, so
   the host's autoloader resolves first on namespace collision;
4. stashes the loader in `PluginAutoloaderRegistry` so
   `PackageManagerRunner` can refresh it after `composer require`.

A missing `var/plugin-composer/vendor/autoload.php` is a no-op — fresh
hosts boot cleanly with no plugins installed.

### 15.3 Dependency policy: host-provided packages stay host-provided

The seeded `var/plugin-composer/composer.json` materialises:

- `provide` — every package under `symfony/*`, `doctrine/*`, `psr/*`,
  and `humdek/sh-selfhelp-*` from the host's
  `vendor/composer/installed.json` at the host's resolved version;
- `config.platform` — the host's PHP version + every loaded `ext-*`.

Plugin packages may declare these in their `require` block normally;
Composer's solver checks the constraint against the `provide` block
and never downloads a duplicate vendor tree. Because the secondary
`ClassLoader` is appended (not prepended), if a plugin somehow ships
its own copy of a host-provided class the host's class still wins on
collision.

`PluginDependencyPolicy` runs a soft check during install for
standalone archive sources: it inspects the package's
`composer.json#require`, compares each host-provided entry to the
host's resolved version, and writes a `dependency-policy:report`
entry to `plugin_operations.logs_json` so operators see drift before
Composer's solver does.

### 15.4 Migration from a pre-isolated host

Hosts that already have a plugin Composer package mixed into the host
`vendor/` (for example because they were installed before this
isolation refactor) need a one-shot cleanup. The plugin's `plugins`
table row, `selfhelp.plugins.lock.json` entry, and any plugin data
are preserved across the migration; only the Composer location moves.

Because the host's `config/selfhelp_plugin_bundles.php` still
references the bundle class after `composer remove`, you must enable
safe-mode first so the kernel can still boot. Recommended order:

```bash
# 1. Enable plugin safe-mode (writes var/plugin_safe_mode.lock; checked
# by config/bundles.php BEFORE the generated bundles file is loaded,
# so kernel boot is safe even if a plugin bundle class is missing).
touch var/plugin_safe_mode.lock
# (Equivalent to `php bin/console selfhelp:plugin:safe-mode --enable`
# once the kernel can boot again.)

# 2. Remove the plugin from the HOST Composer root.
composer remove humdek/<plugin-package> --no-plugins --no-scripts

# 3. Reinstall via the admin UI: drag-and-drop the `.shplugin` file.
# The install lands in var/plugin-composer/vendor/<package>/.
# selfhelp.plugins.lock.json + config/selfhelp_plugin_bundles.php
# are regenerated by the worker.

# 4. Disable safe-mode so the freshly registered plugin bundle is loaded.
rm var/plugin_safe_mode.lock
# (Or `php bin/console selfhelp:plugin:safe-mode --disable`.)
```

`--no-plugins` keeps Symfony Flex from rewriting `config/bundles.php`;
`--no-scripts` keeps any host composer scripts from running.

Verify the migration worked:

```bash
composer show humdek/<plugin-package>          # 'Package not found' against the host root
ls var/plugin-composer/vendor/humdek/          # plugin package is here
ls vendor/humdek/                              # plugin is gone from the host vendor
```

### 15.5 Recovery / reset

If `var/plugin-composer/` ends up in a broken state (e.g. half-written
`composer.lock` after a SIGKILL):

```bash
rm -rf var/plugin-composer/vendor var/plugin-composer/composer.lock
```

The next `composer require` (issued through a plugin install) will
re-create the directory + lock from the seeded `composer.json`.
`selfhelp.plugins.lock.json`, `config/selfhelp_plugin_bundles.php`,
the `plugins` table, and the rest of the install state are not
touched.

> Do not delete `var/plugin-composer/composer.json`. It carries the
> host-provided `provide` block + `config.platform` matrix that lets
> plugin dependencies resolve against the host's framework versions.

## 14. Archive maintenance CLI

Three CLI commands ship for managing `.shplugin` artefacts:

| Command | Purpose |
|---------|---------|
| `selfhelp:plugin:validate-archive <path> [--json]` | Run the same `inspect-archive` pipeline against a local file. Returns exit code 1 when validation reports any error. Use in plugin CI before publishing. |
| `selfhelp:plugin:cleanup-archives` | Reap orphan `var/plugins/<id>-<ver>/staging/` dirs older than `SELFHELP_PLUGIN_ARCHIVE_RETENTION_DAYS` (default 7). Wire into cron / scheduled jobs. |
| `selfhelp:plugin:purge-staging <pluginId> [--all] [--confirm]` | Force-delete staging dirs for one plugin (or all). Dry-run by default; pass `--confirm` to actually delete. Never touches `installed/` or `public/plugin-artifacts/...`. |

## 15. Testing notes — what is automated vs. deploy-time

How the install lifecycle is verified. Full split + status lives in
[`testing-matrix.md`](./testing-matrix.md); this is the operator-facing summary.

**Automated in host CI (safe, in-transaction or DB-free):**

- **Managed-mode install request** — `tests/Certification/InstallLifecycleCertificationTestCase.php`
  drives the REAL admin API: install → `202 Accepted` (manifest cleared
  signature + compatibility + capability/trust validation) → a `plugin_operations`
  row is recorded and visible via the operations API → the concurrency guard
  rejects a second operation and `cancel` clears it. Every plugin subclasses this.
- **Lock-file lifecycle** — `tests/Plugin/Lifecycle/PluginLockFileLifecycleTest.php`
  (Unit suite, no DB) certifies the `selfhelp.plugins.lock.json` primitives with
  `tests/Support/LockFileAssertion.php`: install records the entry, uninstall
  (`removePlugin`) reverses only that entry, and rollback (`restore`) brings the
  file back byte-identical (same SHA-256) — `restore(null)` removes it.
- **Purge guard** — `tests/Integration/Command/Plugin/PluginCliCommandsTest.php`
  asserts `selfhelp:plugin:purge` refuses without `--confirm` BEFORE touching any
  table.
- **Read/diagnostic CLI** — `selfhelp:plugin:status` / `:doctor` boot and degrade
  gracefully on a fresh host.

**Deploy-time only (documented exception, NOT in the in-transaction WebTestCase):**

- The CLI/CI worker (`selfhelp:plugin:run-operation`) that runs composer + npm +
  Doctrine migrations and calls `PluginInstaller::finalize()` — those writes are
  non-transactional disk writes (`selfhelp.plugins.lock.json` +
  `config/selfhelp_plugin_bundles.php`).
- The DB-orchestrated `PluginRollbacker` reversal of a `failed` `plugin_operations`
  row (it replays the lock-file snapshot through the SAME `restore()` primitive the
  test above certifies). Run it against a live stack during a deploy smoke.
