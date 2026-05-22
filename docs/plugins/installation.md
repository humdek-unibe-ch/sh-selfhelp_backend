# Plugin Installation Guide

This document describes how operators install, update, disable, and remove SelfHelp plugins across all three host runtimes (backend, frontend, mobile).

Audience: operators / DevOps. Plugin authors should read `developer-guide.md` first.

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

## 4. Install a plugin (managed mode)

The admin UI never invokes Composer / npm in this mode. Operators run the package install themselves.

1. The admin presses **Request install** in `Admin → Plugins → Available`.
2. The host records a `plugin_operations` row with status `awaiting_external_install`. Mercure event lands in the admin UI.
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

## 11. Lock file recovery

If the lock file goes out of sync with the database:

```bash
php bin/console selfhelp:plugin:sync-lock
```

The command writes a fresh `selfhelp.plugins.lock.json` from the `plugins` + `plugin_operations` tables.

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
