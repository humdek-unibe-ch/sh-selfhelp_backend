# Plugin Ecosystem — Architecture

This document is the system-level overview of the SelfHelp plugin
ecosystem. It complements:

**Audience: operators**
- [`installation.md`](./installation.md) — how to install / update / remove plugins.
- [`install-modes.md`](./install-modes.md) — development / managed / trusted modes.
- [`lock-file.md`](./lock-file.md) — `selfhelp.plugins.lock.json` contract.
- [`registry-and-channels.md`](./registry-and-channels.md) — sources + release channels.
- [`trust-levels.md`](./trust-levels.md) — official / reviewed / untrusted.
- [`plugin-operations-and-rollback.md`](./plugin-operations-and-rollback.md) — operation lifecycle.
- [`ci-workflows.md`](./ci-workflows.md) — host & plugin CI pipelines.

**Audience: plugin authors**
- [`developer-guide.md`](./developer-guide.md) — how to build a plugin.
- [`publishing-workflow.md`](./publishing-workflow.md) — end-to-end author flow (repo skeleton, `.gitignore`, `.shplugin` build, GitHub release, registry pickup, what gets installed where).
- [`capabilities.md`](./capabilities.md) — capability allow-list reference.
- [`versioning-and-compatibility.md`](./versioning-and-compatibility.md) — SemVer + host compatibility.
- [`mobile-plugins.md`](./mobile-plugins.md) — Expo mobile target rules.
- [`realtime-and-no-polling.md`](./realtime-and-no-polling.md) — Mercure topics + the no-polling rule.
- [`lookups.md`](./lookups.md) — extending the host enum store.
- [`feature-flags.md`](./feature-flags.md) — runtime feature flags.
- [`testing-matrix.md`](./testing-matrix.md) — required tests per plugin.
- [`surveyjs-plugin.md`](./surveyjs-plugin.md) — first-reference plugin design.
- [`plugin-repo-agents-md-template.md`](./plugin-repo-agents-md-template.md) — drop-in AGENTS.md.

**Audience: security & data-protection officers**
- [`security-model.md`](./security-model.md) — threat model, signatures, capability enforcement.
- [`gdpr-and-data-ownership.md`](./gdpr-and-data-ownership.md) — ownership rule, export & erasure.

**Audience: tooling**
- [`multi-repo-agents-md.md`](./multi-repo-agents-md.md) — AGENTS.md coordination across repos.
- [`plugin-manifest.schema.json`](./plugin-manifest.schema.json) — manifest schema.
- [`plugin-lock.schema.json`](./plugin-lock.schema.json) — lock-file schema.
- [`plugin-registry.schema.json`](./plugin-registry.schema.json) — registry-source schema.

## 1. Goal

Provide a plugin layer for SelfHelp CMS that is:

- **Multi-target** — a single plugin ships backend (Symfony), web (Next.js + Mantine), and optional mobile (Expo) packages.
- **Manifest-driven** — `plugin.json` is the single source of truth; the host installer derives behavior from it.
- **Audit-friendly** — every install / update / uninstall / purge is recorded as a `plugin_operations` transaction with snapshots and rollback descriptors.
- **Deterministic** — `selfhelp.plugins.lock.json` pins versions across the backend / frontend / mobile builds.
- **Secure by default** — capabilities, trust levels, protected core tables, migration safety, signature verification, CSP control.
- **Realtime-by-default** — Mercure for everything dynamic; no polling.

## 2. Repos and packages

```
sh-selfhelp_backend/        Symfony CMS host
sh-selfhelp_frontend/       Next.js + Mantine host
sh-selfhelp_mobile/         Expo + React Native host
sh-selfhelp_shared/         Shared TS SDK (@selfhelp/shared + plugin-sdk subpath)
plugins/sh2-shp-<name>/     One repo per plugin
```

A plugin lives in its own repo and ships up to three packages:

- `humdek/sh2-shp-<name>` (Composer) — Symfony bundle.
- `@humdek/sh2-shp-<name>` (npm) — frontend package.
- `@humdek/sh2-shp-<name>-mobile` (npm) — optional mobile package.

The plugin SDK lives at `@selfhelp/shared/plugin-sdk`. Plugins import
from this single subpath; other internal paths of `@selfhelp/shared`
are not part of the SDK contract.

## 3. Installation modes

Three modes, recorded per-install in the lock file:

| Mode          | Composer / npm   | Admin UI                | When to use                              |
| ------------- | ---------------- | ----------------------- | ---------------------------------------- |
| `development` | runs in-process  | full UI                 | Local dev only. Disabled in CI.          |
| `managed`     | runs externally  | request + finalize UI   | Production (separation of duties).       |
| `trusted`     | runs in-process  | full UI, audited        | Air-gapped / on-prem with a verified mirror. |

In `managed` mode the admin UI never shells out to `composer` / `npm`;
the operator runs the external package install, then calls
`selfhelp:plugin:run-operation <id>` to finalize.

## 4. Backend layout (host)

```
sh-selfhelp_backend/
├── src/Plugin/
│   ├── Archive/          PluginArchiveExtractor, PluginArchiveValidator,
│   │                     PluginArchivePromoter (atomic copy-then-rename),
│   │                     PluginArchiveInspectionService, PluginArchiveCleaner
│   ├── Bundle/           PluginBundlesFileWriter (regenerates config/selfhelp_plugin_bundles.php)
│   ├── Event/            Lifecycle + ApiRouteRegistryEvent + LookupRegistryEvent + StyleRegistryEvent + …
│   ├── Lifecycle/        PluginInstaller, PluginUpdater, PluginUninstaller,
│   │                     PluginEnabler, PluginPurger, PluginRollbacker,
│   │                     PluginRepairer, PluginSafeMode, PluginOperationLock,
│   │                     PluginOperationRecorder, PluginLockFileReader/Writer,
│   │                     InstallModeResolver
│   ├── Manifest/         PluginManifest, PluginManifestLoader, PluginManifestValidator,
│   │                     ManifestResolver (single normaliser for registry|url|paste|archive),
│   │                     ResolvedSource (DTO)
│   ├── Messenger/        InstallPluginMessage/Handler, UpdatePluginMessage/Handler,
│   │                     UninstallPluginMessage/Handler
│   ├── PackageManager/   PackageManagerRunner (composer require / remove, streams output)
│   ├── Realtime/         PluginRealtimePublisher (Mercure-backed)
│   ├── Registry/         RegistryClient (private + public sources with auth headers),
│   │                     PluginSourceUrlResolver
│   ├── Security/         PluginCapabilityValidator, PluginCapabilityViolationException,
│   │                     PluginDataAccessGuard, PluginMigrationGuard,
│   │                     PluginSignatureVerifier (Ed25519), SignedPayloadBuilder,
│   │                     PluginSignatureException
│   ├── Service/          PluginAdminService (facade)
│   └── Versioning/       SemverHelper, PluginCompatibilityValidator
├── src/Entity/Plugin/    Plugin (+ signing_key_id + signature_ed25519), PluginOperation,
│                         PluginSource, PluginFeatureFlag
├── src/Command/Plugin/   selfhelp:plugin:* CLI commands
├── config/
│   ├── selfhelp_plugin_bundles.php   ← regenerated atomically by the installer / uninstaller
│   ├── packages/messenger.yaml       ← `plugin_ops` transport (MESSENGER_PLUGIN_OPS_DSN)
│   └── packages/lock.yaml            ← distributed lock for plugin operations
└── migrations/Version*.php                plugin-layer install and API-surface migrations
```

## 5. Frontend layout (host)

```
sh-selfhelp_frontend/
├── src/app/components/frontend/plugin-runtime/
│   ├── PluginRuntime.ts          Boots, calls plugin register(), merges contributions
│   ├── PluginsProvider.tsx       React Context + hooks (usePluginRuntime, etc.)
│   └── index.ts
├── src/app/components/frontend/styles/BasicStyle.tsx
│       — Dispatcher seeded by `styleImpls` map + plugin contributions.
├── src/app/components/cms/plugins/   Admin Plugins UI
│   ├── plugins-page/
│   ├── plugin-sources-panel/
│   ├── plugin-host-route/
│   └── hooks/useAdminPlugins.ts
├── src/app/admin/plugins/page.tsx                       /admin/plugins
├── src/app/admin/plugins-host/[pluginId]/[slug]/page.tsx /admin/plugins-host/<id>/<slug>
└── scripts/plugins-sync.mjs       Regenerates selfhelp.plugins.lock.json + package.json
```

The frontend uses **no polling** for plugin state — the admin shell
subscribes to the host's `selfhelp/plugins/state` Mercure topic and
invalidates the React Query cache on every operation event.

## 6. Mobile layout (host)

```
sh-selfhelp_mobile/
├── components/styles/
│   ├── index.ts          Core mobile style impls (typed by TStyleImplMap)
│   └── registered.ts     Generated per EAS profile by plugins:sync
├── components/renderer/
│   ├── BasicStyle.tsx        Dispatches core → plugin → OpenOnWebFallback → UnknownStyle
│   └── OpenOnWebFallback.tsx
└── scripts/plugins-sync.mjs   Generates registered.ts + package.json + lock
```

The mobile build is **per EAS profile**. Plugins explicitly opt their
mobile package into a profile via the manifest's `mobile.profiles`
array (omit to mean "all"). Missing plugins gracefully fall back to
the web app.

## 7. Plugin operation lifecycle

```
PluginAdminService::install|update|uninstall()  ─►  ManifestResolver
                                                         ↓
                              PluginCapabilityValidator + CompatibilityValidator
                                                         ↓
                              persist plugin_operations (status=requested, with snapshot
                              of manifest + resolvedSource + signing.keyId + signature)
                                                         ↓
                              MessageBus::dispatch(InstallPluginMessage|...)
                                                         ↓  (returns 202 to admin UI)
                              Messenger worker (plugin_ops transport)
                                                         ↓
                              composer require / remove  (streams logs into logs_json)
                                                         ↓
                              [archive only] PluginArchivePromoter::promote()  →
                                copy-to-temp + atomic rename →
                                  var/plugins/<id>-<ver>/installed/
                                  public/plugin-artifacts/<id>-<ver>/
                                                         ↓
                              PluginInstaller|Updater|Uninstaller::finalize()
                                                         ↓
                              regenerate config/selfhelp_plugin_bundles.php
                                                         ↓
                              run plugin Doctrine migrations
                                                         ↓
                              upsert/remove selfhelp.plugins.lock.json
                              (signing + migrations[].sha256 included)
                                                         ↓
                              dispatch Plugin{Installed,Updated,Uninstalled}Event
                                                         ↓
                              publish to selfhelp/plugins/state via Mercure
```

Every step writes a `plugin_operations` row with snapshots of:

- manifest before / after,
- resolved source (kind, sourceName, manifestUrl, keyId, signature),
- lock file snapshot before / after,
- migration scan,
- rollback descriptor (operator-readable steps).

Failed operations are recorded as `failed`. `rollback` is a separate
operation type that re-applies the previous snapshot.

In `managed` mode the worker stops at the runbook stage (writes a
`managed-runbook` entry into `logs_json`) and the operator runs the
composer command + `selfhelp:plugin:run-operation <opId>` to invoke
`finalize()`. There is no browser-side "finalize" step.

## 8. Concurrency

- `App\Plugin\Lifecycle\PluginOperationLock` enforces a single in-flight operation per plugin **and** a single in-flight operation cluster-wide using the Symfony Lock component.
- Default DSNs (`LOCK_DSN`, `PLUGIN_LOCK_DSN`) are `flock`; production swaps in Redis.
- All orchestrators release the lock in `try { … } finally { release }` blocks.

## 9. Extension points (no monkey-patching)

Plugins extend the host through documented events + tagged services
only. They never patch core services, never modify core routes at
runtime, and never write to config outside the installer.

### Symfony events (host → plugin)

- `App\Plugin\Event\StyleRegistryEvent` — contribute styles.
- `App\Plugin\Event\LookupRegistryEvent` — contribute lookup rows for known type codes.
- `App\Plugin\Event\PluginRealtimeTopicRegistryEvent` — declare realtime topics.
- `App\Plugin\Event\ApiRouteRegistryEvent` — register additional routes (always under `/plugins/{pluginId}/`).
- `App\Plugin\Event\Lifecycle\*` — react to install / update / enable / disable / uninstall / purge events.
- `App\Plugin\Event\PluginRealtimePermissionEvent` — scope SSE subscriber JWTs.
- `App\Plugin\Event\ScheduledJobTypeEvent` — register a scheduled job type.

### Tagged services (host → plugin runtime)

- `selfhelp.plugin.field_renderer` — custom CMS field edit/view widgets.
- `selfhelp.plugin.scheduled_job` — scheduled job handlers.
- `selfhelp.plugin.health_check` — health checks shown in the doctor command.

### Frontend extension surface

- `IPluginRegistration.styles` — extra CMS style renderers (web).
- `IPluginRegistration.adminPages` — extra admin pages (web).
- `IPluginRegistration.menuItems` — extra admin menu entries.
- `IPluginRegistration.realtimeTopics` — declared realtime topics.
- `IPluginRegistration.featureFlags` — declared feature flags.
- `IPluginRegistration.healthChecks` — declared health probes.

### Mobile extension surface

- `IMobilePluginRegistration.styles` — read-only or interactive mobile renderers.
- `IMobilePluginRegistration.featureFlags` — mirrored from the manifest.

### What plugins must NOT do

- Patch core services (no proxy / hook / monkey-patch system — see §11).
- Modify core routes at runtime.
- Write to config outside the installer.
- Access undeclared DB tables.
- Poll core endpoints.
- Touch protected core tables (`users`, `roles`, `permissions`, `plugins`, `plugin_*`, etc.).

## 10. Security

- **Trust levels**: `official` (signed Ed25519), `reviewed`, `untrusted`.
- **Capabilities** are deny-by-default; the installer enforces `(trust × capability)`.
- **Protected tables** (`ProtectedTablesPolicy`) cannot be touched by plugin migrations.
- **Migration guard** (`PluginMigrationGuard`) sniffs SQL and rejects migrations that touch non-owned tables (when no explicit dataAccess declaration is present).
- **Signature verifier** (`PluginSignatureVerifier`) validates the optional Ed25519 signature against pinned public keys before install.
- **Secrets**: license keys / API tokens come from `.env`. Only admin-only routes expose them.
- **DB user separation** (recommended at deploy time): a plugin DB user that cannot reach protected core tables.

## 11. Why no plugin proxy hooks

The legacy `docs/plugin_hooks.md` proposal described a runtime proxy
system based on `ocramius/proxy-manager`. That approach is **not
implemented** and is intentionally retired in favor of explicit
Symfony events and tagged services. Reasons:

- Explicit subscriber registrations are auditable.
- They survive Symfony cache compilation.
- They cannot accidentally short-circuit core behavior.
- They line up with the manifest-as-source-of-truth principle.

If a future plugin really needs to intercept a core method, propose a
new event in the host (`App\Plugin\Event\*`) instead.

## 12. The `selfhelp.plugins.lock.json` file

Deterministic root-level lock file. Written atomically by the
installer (tmp + rename, with `.bak` fallback). Contains, for every
installed plugin:

- pinned `version` + `pluginApiVersion`,
- `trustLevel` + `capabilities` granted,
- `installMode` (development / managed / trusted),
- `backend.package` + `backend.bundleClass`,
- `frontend.runtimeUrl` + `stylesheetUrl` + `integrity` + `format`,
- `mobile.package` + `mobile.version`,
- `checksum` (`Plugin.checksumSha256`),
- `signing.keyId` + `signing.signature` (Ed25519, base64),
- `compatibility.selfhelp` (manifest range),
- `migrations[]` — `[{file, sha256}]` of every Doctrine migration
  shipped in the plugin's bundle (the writer walks
  `<bundleDir>/Migrations/*.php` and hashes them so a host with the
  same lock can detect that the plugin's migration set has drifted),
- `enabled` flag, `updatedAt`.

The lock file mirrors what the database knows; the installer also
regenerates `config/selfhelp_plugin_bundles.php` so the next boot
loads exactly the bundles declared in the lock.

## 13. CLI summary

| Command                                            | Phase  | Purpose                                              |
| -------------------------------------------------- | ------ | ---------------------------------------------------- |
| `selfhelp:plugin:install <manifest>`               | any    | Dispatch an install operation (worker handles composer + finalize) |
| `selfhelp:plugin:update <manifest>`                | any    | Dispatch an update operation                         |
| `selfhelp:plugin:uninstall <id>`                   | any    | Dispatch an uninstall operation (keeps plugin tables) |
| `selfhelp:plugin:enable <id>` / `:disable`         | any    | Toggle without removing data                         |
| `selfhelp:plugin:purge <id> --confirm`             | any    | Drop plugin-owned tables (destructive)               |
| `selfhelp:plugin:sync-lock`                        | any    | Regenerate lock + bundles file from DB state         |
| `selfhelp:plugin:check-compatibility`              | any    | Per-plugin compatibility report                      |
| `selfhelp:plugin:check-updates`                    | any    | Cross-reference installed vs available versions       |
| `selfhelp:plugin:doctor [--ci] [--json]`           | any    | Global plugin health report                          |
| `selfhelp:plugin:safe-mode --enable|--disable`     | any    | Emergency safe-mode (boots without plugin bundles)   |
| `selfhelp:plugin:run-operation <id>`               | managed| Finalize a `managed` mode operation after composer ran|
| `selfhelp:plugin:validate-archive <path>`          | any    | Run the inspect-archive pipeline on a local .shplugin |
| `selfhelp:plugin:cleanup-archives`                 | any    | Reap orphaned `.shplugin` staging dirs               |
| `selfhelp:plugin:purge-staging <id> [--all]`       | any    | Force-delete staging dirs (dry-run unless `--confirm`)|

## 14. SurveyJS plugin (first reference)

See `surveyjs-plugin.md`. The plugin exercises every part of the
ecosystem: backend bundle with entities + migration, frontend styles,
admin pages, custom question types, Mantine theme bridge, Tiptap
rich-text adapter, mobile readonly + Open-on-Web fallback, realtime
topics, feature flags, health check, and license-key endpoint.

## 15. What is intentionally NOT part of this phase

- Native mobile SurveyJS renderer (`survey-react-native` evaluation). Deferred.
- Frontend admin field registry (custom CMS field renderers contributed by plugins to the admin UI). Deferred.
- Plugin proxy / hook engine. Permanently retired (see §11).
