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
  - `LOCK_DSN` (default `flock`) — single-machine setups.
  - `PLUGIN_LOCK_DSN` (default `flock`) — distributed setups should set to `redis://…`.
  - `SELFHELP_PLUGIN_PRIVATE_REGISTRY_TOKEN` — only if using a private registry.
  - `SELFHELP_PLUGIN_SIGNATURE_KEYS` — comma-separated Ed25519 public keys for trust verification.

## 2.1 Install paths visible in the admin UI

The admin "Plugins" page exposes three install tabs:

| Tab            | What it does                                                                                                                                                                              |
| -------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Installed**  | Lists currently installed plugins, their status, compatibility, and per-row actions (enable / disable / uninstall / purge).                                                                |
| **Available**  | Walks every enabled **Source** and lists registry-advertised plugins. Each row has a one-click **Install** button (calls `POST /admin/plugins` then `POST /admin/plugins/{id}/finalize-install`). |
| **Sources**    | CRUD over `PluginSource` rows. The seeded `humdek-public` source (system, read-only) points at the official Humdek registry; admins can add private/staging sources alongside it.          |

The active tab is **persisted to the URL** (`?tab=available`,
`?tab=sources`) so a page refresh or shared link lands on the same
tab.

Plus an **Install plugin** button at the top-right that opens a
modal supporting three input methods:

- **Drag &amp; drop** a `plugin.json` file directly on the Dropzone.
- **Choose file…** button that opens the native file picker.
- **Paste JSON** into the embedded Monaco editor (with JSON syntax
  validation).

The first two methods auto-format the manifest into the Monaco
editor; the editor remains the source of truth for the
`POST /admin/plugins` request body.

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

| Endpoint                                  | Verb | Purpose                                                                |
| ----------------------------------------- | ---- | ---------------------------------------------------------------------- |
| `/cms-api/v1/admin/plugins`               | GET  | List installed plugins with install mode + safe-mode flags.            |
| `/cms-api/v1/admin/plugins/available`     | GET  | List registry-advertised plugins not yet installed.                    |
| `/cms-api/v1/admin/plugins`               | POST | Request a staged install (mostly used by the Available + paste flows). |
| `/cms-api/v1/admin/plugins/{id}/finalize-install` | POST | Finalize a staged install in-process (development/trusted mode).       |
| `/cms-api/v1/admin/plugins/sources`       | GET / POST | CRUD over registries the host trusts.                              |

## 3. Install a plugin (development mode)

1. Place / clone the plugin repo somewhere reachable by Composer + npm. For local work, symlink or use `path` Composer / npm `file:` references.
2. From the backend repo:

```bash
php bin/console selfhelp:plugin:install /abs/path/to/plugin.json
```

The installer:

- validates the manifest against `docs/plugins/plugin-manifest.schema.json`,
- checks `compatibility.*` ranges,
- runs `composer require` for the backend package,
- runs `npm install` (if relevant) for the frontend / mobile packages,
- enables the bundle by updating `config/selfhelp_plugin_bundles.php`,
- runs the plugin's Doctrine migrations,
- seeds permissions / lookups / feature flags,
- writes a `plugin_operations` row + updates `selfhelp.plugins.lock.json`,
- publishes a Mercure event so the admin UI updates without polling.

3. From the frontend repo:

```bash
npm run plugins:sync -- --backend http://localhost:8000
npm install
npm run dev
```

`plugins:sync` regenerates the frontend lock + extends `package.json` so the plugin's frontend npm package becomes a real dependency.

4. From the mobile repo (optional):

```bash
SELFHELP_API_TOKEN=… npm run plugins:sync -- production-default --backend https://cms.example.com
npm install
eas build --profile production-default
```

`plugins:sync` writes `selfhelp.plugins.mobile.lock.json` per EAS profile and regenerates `components/styles/registered.ts` so only the plugin packages opted into that profile are bundled.

### 3.1 Local sibling checkout (`plugins/<plugin-id>`)

When the plugin lives in the same workspace as the host repos, for
example:

```text
<workspace>/
├── sh-selfhelp_backend
├── sh-selfhelp_frontend
└── plugins/
    └── sh2-shp-survey-js/
```

there is one important current-code detail:

- The admin UI validates and stages the manifest, but it does not
  itself make a local checkout available to Composer or npm.
- The "Sources" tab is optional for this flow. It is for registries and
  remote package sources, not for pasting a local `plugin.json`.

Use this runbook:

1. Make the backend package resolvable by Composer. A typical local-dev
   setup is a path repository pointing at
   `../plugins/sh2-shp-survey-js/backend`, then `composer require
   humdek/sh2-shp-survey-js`.
2. Build the plugin frontend package once from the plugin repo:

   ```bash
   cd ../plugins/sh2-shp-survey-js/frontend
   npm install
   npm run build
   ```

3. In the backend admin UI open `Plugins -> Install plugin`, paste the
   plugin's `plugin.json`, click `Request install`, then click
   `Finalize`.
4. In the frontend repo run:

   ```bash
   npm run plugins:sync -- --backend http://localhost:8000
   npm install
   npm link ../plugins/sh2-shp-survey-js/frontend
   ```

   `plugins:sync` updates the host manifest/lock state, while
   `npm link` makes the host load the local checkout instead of waiting
   for a published npm package.
5. Start or restart the frontend dev server.

If you skip step 1 or step 4, the plugin may appear installed in the
host database but still fail to boot in the backend or frontend because
the local package code is not actually resolvable yet.

## 4. Install a plugin (managed mode)

The admin UI never invokes Composer / npm in this mode. Operators run the package install themselves.

Current UI note: the admin screen shows a paste-manifest flow
(`Request install` -> `Finalize`). That flow still assumes the package
work happened outside the UI. Finalizing records the plugin in the host
and regenerates lock/bundle metadata; it does not replace the external
Composer/npm step.

1. The admin presses **Request install** in `Admin → Plugins → Available`.
2. The host records a `plugin_operations` row with status `requested`. A Mercure event lands in the admin UI.
3. The host produces a runbook (visible on the operation detail page) similar to:

```bash
# 1. Backend: pin the package version in composer.json
composer require humdek/sh2-shp-survey-js:^1.0
# 2. Frontend: pin the package version in package.json
npm install @humdek/sh2-shp-survey-js@^1.0
# 3. Mobile (if applicable): pin the package version per profile
npm install --prefix mobile @humdek/sh2-shp-survey-js-mobile@^1.0
# 4. Commit + deploy
# 5. Tell the backend the install is complete:
php bin/console selfhelp:plugin:run-operation <operationId>
```

4. The operator runs the steps and then triggers `selfhelp:plugin:run-operation <id>` from the deployed backend. The host:
   - cross-checks the installed package versions against the manifest,
   - enables the bundle (writes `selfhelp_plugin_bundles.php`),
   - runs the plugin's Doctrine migrations,
   - finalizes the operation (`status=succeeded`).

If any step fails, the operation is marked `failed` and the previous lock-file checksum is restored.

### 4.1 Bundle-class autoload gate

Before regenerating `config/selfhelp_plugin_bundles.php`,
[`PluginInstaller::finalize()`](../../src/Plugin/Lifecycle/PluginInstaller.php)
and [`PluginUpdater::finalize()`](../../src/Plugin/Lifecycle/PluginUpdater.php)
verify that the manifest's `backend.bundleClass` is autoloadable via
`class_exists()`. If it is not, the operation is marked `failed` with a
`PRECONDITION_FAILED` error and the bundles file is left untouched. The
admin UI surfaces the message:

> Backend bundle class "…\HumdekSurveyJsBundle" is not autoloadable.
> The composer package "humdek/sh2-shp-survey-js" must be installed
> before finalizing. Run `composer require humdek/sh2-shp-survey-js:0.1.0`
> (or use the plugin's `scripts/install-local.{ps1,sh}` helper) and
> click Finalize again.

This guard exists because a previous shape of the installer would
unconditionally write the missing class into the generated bundles
file, after which `kernel->registerBundles()` died on every
subsequent request. The recovery procedure for installs that
predate the guard is in §10 and §11.

## 5. Update a plugin

```bash
php bin/console selfhelp:plugin:update /abs/path/to/plugin.json
```

The update runs:

1. Snapshot current manifest + lock checksum.
2. Run incremental Doctrine migrations.
3. Update the lock file.
4. Trigger the `PluginUpdatedEvent`.

Frontend / mobile updates work the same way as install — re-run `plugins:sync` and your package manager.

## 6. Disable / re-enable

```bash
php bin/console selfhelp:plugin:disable sh2-shp-survey-js
php bin/console selfhelp:plugin:enable sh2-shp-survey-js
```

Disabling unregisters the Symfony bundle, hides admin routes, and removes the plugin contributions from the frontend runtime — without deleting plugin data.

## 7. Uninstall (non-destructive)

```bash
php bin/console selfhelp:plugin:uninstall sh2-shp-survey-js
```

Removes the bundle, deletes the plugin row, and clears feature flags. Does NOT drop plugin-owned tables.

## 8. Purge (destructive)

```bash
php bin/console selfhelp:plugin:purge sh2-shp-survey-js --confirm-purge=sh2-shp-survey-js
```

Drops plugin-owned tables, deletes the plugin's lookup contributions, and removes the lock entry. The `--confirm-purge=<id>` guard prevents accidental purges.

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
php bin/console selfhelp:plugin:sync-lock
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
#   UPDATE plugin_operations SET status='cancelled', finished_at=NOW()
#     WHERE plugin_id='<pluginId>' AND status IN ('requested','running');
#   DELETE FROM plugins WHERE plugin_id='<pluginId>';

# 4. Resync the artefacts (bundles file + lock file):
php bin/console selfhelp:plugin:sync-lock

# 5. Disable safe mode and re-clear cache:
php bin/console selfhelp:plugin:safe-mode --disable
php bin/console cache:clear --env=dev --no-warmup
```

Once the operator has fixed the underlying composer/npm gap (typically
by running `scripts/install-local.{ps1,sh}` from the plugin checkout)
the install can be re-attempted from the admin UI or
`selfhelp:plugin:install`.

## 12. Operator runbook for a fresh install

```bash
# Backend
composer install
php bin/console doctrine:migrations:migrate -n
php bin/console selfhelp:plugin:install /abs/path/to/plugin.json

# Frontend
cd ../sh-selfhelp_frontend
npm ci
npm run plugins:sync -- --backend http://localhost:8000
npm install
npm run build
npm start

# Mobile (optional, per EAS profile)
cd ../sh-selfhelp_mobile
npm ci
SELFHELP_API_TOKEN=… npm run plugins:sync -- production-default --backend https://cms.example.com
npm install
eas build --profile production-default
```
