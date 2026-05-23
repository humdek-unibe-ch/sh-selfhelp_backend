# SelfHelp Plugin Developer Guide

Audience: plugin authors building against `@selfhelp/shared/plugin-sdk` and
the SelfHelp Symfony backend (CMS host). This document is the canonical
"how to build a SelfHelp plugin" reference. It is paired with:

- `docs/plugins/plugin-manifest.schema.json` (machine-readable manifest schema)
- `docs/plugins/multi-repo-agents-md.md` (AGENTS.md rules across repos)
- `docs/plugins/plugin-repo-agents-md-template.md` (drop-in AGENTS.md for plugin repos)
- `docs/plugins/architecture.md` (system-level architecture overview, see Phase 7)

> SelfHelp CMS lives in `sh-selfhelp_backend` (Symfony 7.4 + PHP 8.4). The
> frontend lives in `sh-selfhelp_frontend` (Next.js + Mantine). The
> mobile app lives in `sh-selfhelp_mobile` (Expo / React Native). The
> shared TypeScript SDK lives in `sh-selfhelp_shared`. Plugins always
> live in their own repository: `sh-shp-<name>`.

---

## 1. Plugin Anatomy

A SelfHelp plugin is a multi-package repository. The minimum layout is:

```
sh-shp-<name>/
├── plugin.json                       # manifest (canonical)
├── AGENTS.md                         # plugin AGENTS rules (use the template)
├── README.md
├── CHANGELOG.md
├── server/                           # Symfony bundle (optional)
│   ├── composer.json
│   ├── src/<Vendor><Name>Bundle.php
│   ├── src/...
│   └── src/Migrations/Version*.php
├── react/                            # Next.js plugin package (web)
│   ├── package.json                  # name "@selfhelp/sh-shp-<name>"
│   ├── src/index.ts                  # exports `register`
│   └── src/styles/, src/admin/, ...
└── mobile/                           # Expo plugin package (optional)
    ├── package.json                  # name "@selfhelp-mobile/sh-shp-<name>"
    └── src/index.ts                  # exports `registerMobile`
```

Not every plugin needs every part. A plugin can ship:

- backend-only (Composer package + migrations + bundle),
- frontend-only (npm package contributing styles / admin pages),
- mobile-only (npm package consumed by EAS builds),
- or any combination of the three.

The presence/absence of each side is declared explicitly in `plugin.json`.

---

## 2. The Manifest (`plugin.json`)

The manifest is the source of truth used by the host installer. It is
validated against `plugin-manifest.schema.json`.

A minimal manifest looks like this:

```json
{
  "id": "sh2-shp-example",
  "name": "Example Plugin",
  "version": "1.0.0",
  "pluginApiVersion": "1.0",
  "license": "MPL-2.0",
  "compatibility": {
    "selfhelp": "^2.0",
    "php": "^8.4",
    "node": "^22"
  },
  "security": {
    "trustLevel": "reviewed",
    "capabilities": ["frontendStyles"]
  },
  "backend": {
    "bundleClass": "SelfHelp\\ExampleBundle\\SelfHelpExampleBundle",
    "composer": {
      "package": "selfhelp/sh-shp-example",
      "version": "1.0.0"
    }
  },
  "frontend": {
    "runtime": {
      "entrypoint": "dist/plugin.esm.js",
      "stylesheet": "dist/plugin.css",
      "format": "esm"
    }
  },
  "styles": [
    {
      "name": "example-banner",
      "description": "A banner style contributed by the example plugin.",
      "canHaveChildren": true
    }
  ]
}
```

Key rules:

- `id` must be **kebab-case** and unique across the registry.
- `version` follows SemVer:
  - **patch** (`1.0.0` → `1.0.1`): no schema change, no migration. The
    installer rejects a patch bump that ships a migration file.
  - **minor** (`1.0.x` → `1.1.0`): must ship at least one migration class
    (the installer enforces this even for "no-op" minor bumps).
  - **major** (`1.x` → `2.x`): breaking. Migration optional.
- `pluginApiVersion` declares the SDK contract version (currently `1.0`).
  Patch/minor mismatches in the SDK never break a plugin. Major SDK bumps
  do; you must re-test against the new SDK.
- `security.capabilities` is **deny-by-default**. You can only use a
  capability that is listed. If you load an external CDN, you must list
  it under `security.externalHosts` and request the `externalNetworkAccess`
  capability.
- `compatibility.selfhelp` must satisfy the host CMS version range. The
  admin UI shows a clear "blocking" reason when this fails.

See `plugin-manifest.schema.json` for the full property list.

---

## 3. The SDK (`@selfhelp/shared/plugin-sdk`)

Every plugin package imports from this single subpath. Importing from
other parts of `@selfhelp/shared` is unsupported and will break across
SDK upgrades.

Frontend plugins call `definePlugin()`:

```ts
import { definePlugin } from '@selfhelp/shared/plugin-sdk';
import { ExampleBanner } from './styles/ExampleBanner';

export const register = definePlugin({
    id: 'sh2-shp-example',
    version: '1.0.0',
    pluginApiVersion: '1.0',
    styles: [
        {
            name: 'example-banner',
            description: 'Example banner.',
            category: 'plugin',
            canHaveChildren: true,
            component: ExampleBanner,
        },
    ],
    adminPages: [
        {
            slug: 'settings',
            title: 'Example settings',
            permission: 'example.manage',
            component: ExampleAdminPage,
        },
    ],
    menuItems: [
        {
            key: 'example.settings',
            label: 'Example',
            href: '/admin/plugins-host/sh2-shp-example/settings',
            permission: 'example.manage',
            position: { section: 'admin', order: 200 },
        },
    ],
    featureFlags: [
        { key: 'example.beta-ui', label: 'Beta UI', defaultEnabled: false },
    ],
});
```

Mobile plugins call `defineMobilePlugin()`:

```ts
import { defineMobilePlugin } from '@selfhelp/shared/plugin-sdk';
import { ExampleBannerMobile } from './styles/ExampleBannerMobile';

export const registerMobile = defineMobilePlugin({
    id: 'sh2-shp-example',
    version: '1.0.0',
    pluginApiVersion: '1.0',
    styles: [
        {
            name: 'example-banner',
            description: 'Example banner (mobile).',
            category: 'plugin',
            canHaveChildren: true,
            component: ExampleBannerMobile,
        },
    ],
});
```

Both `definePlugin()` and `defineMobilePlugin()` throw at boot if the
plugin's `pluginApiVersion` is not compatible with the host SDK. The
host `PluginRuntime` catches the error, logs it, and skips the plugin
instead of crashing the page.

### Style contributions

A plugin style is just another CMS style. The host renders it through
the same `BasicStyle.tsx` dispatcher used for core styles, so plugin
styles automatically get:

- the standard `getCssClass()` helper (Mantine spacing tokens),
- debug overlays in CMS preview mode,
- proper recursive child rendering when `canHaveChildren: true`,
- the `parentActive` / `parentColor` / `childIndex` props.

Your component receives the same props as a core style component. See
`sh-selfhelp_frontend/src/types/styles` for the prop shapes.

### Admin pages

Pages contributed under `adminPages` are mounted at
`/admin/plugins-host/{pluginId}/{slug}`. The host shell handles the
layout, breadcrumbs, and permission gating; your component renders the
page body.

### Menu items

Menu items are merged into the host menu by the `PluginRuntime`. The
host honors the `position.section` (`admin`, `cms`, `tools`) and
`position.order`. Items always require their declared `permission` to
be visible.

### Realtime topics

Use `definePluginRealtimeTopic()` to declare a topic. Plugins **never**
talk to Mercure directly. The host installer registers each topic with
its scoped path `selfhelp/plugin/{pluginId}/{key}`. Backend code
publishes through `IPluginRealtimePublisher` (passed as `api.realtime`
inside the Symfony bundle).

### Feature flags

Flags are declared in the manifest **and** passed to `definePlugin()`.
The host seeds a `plugin_feature_flags` row with `defaultEnabled` and
exposes the flag to your code via `api.isFeatureEnabled('beta-ui')`.

### Rich text editor adapter

If your plugin needs rich text, use `api.richTextEditor` rather than
embedding your own editor. The host provides a Tiptap-backed adapter
with consistent styling, paste handling, sanitization, and a stable
serialization format.

---

## 4. Backend Plugins (Symfony Bundle)

A backend plugin ships:

- a Composer package (`server/composer.json`),
- a Symfony bundle class implementing the standard `BundleInterface`
  contract,
- optional Doctrine migrations (`server/src/Migrations/`),
- optional API controllers,
- optional event listeners and services.

Bundle registration is **dynamic**. The host writes
`config/selfhelp_plugin_bundles.php` automatically when you install,
enable, disable, or uninstall. You never edit `bundles.php` by hand.

The host enforces these guard rails on the backend side:

- **Migration safety**: migrations are only allowed to touch tables the
  plugin owns. The host's `MigrationGuard` aborts a migration that
  tries to `ALTER` core tables. Use `id_plugins` columns to mark
  plugin-owned data in core tables when collaboration is needed.
- **Capability checks**: a plugin without `databaseMigrations` cannot
  run migrations; a plugin without `externalNetworkAccess` cannot use
  the HTTP client; etc.
- **Transaction logging**: any data-changing operation must go through
  `TransactionService` like the rest of the CMS code.
- **API routes**: routes declared under `apiRoutes` in the manifest are
  registered automatically. Route permissions and validation schemas
  belong to the plugin's repo.

### Migrations and the plugin lifecycle

The plugin's migration files live in
`server/src/Migrations/Version*.php`. They are timestamped with the
standard `bin/console doctrine:migrations:generate` command (do **not**
hand-name migration files).

The host invokes migrations **only** inside the Messenger
`InstallPluginHandler` / `UpdatePluginHandler`, after Composer has
installed the new code, and within the plugin operation transaction.
If a migration fails the operation is recorded as `failed`, any
artifacts promoted to `public/plugin-artifacts/` are rolled back, and
the plugin is left in its previous state.

---

## 5. Installation Modes

The CMS supports three install modes; each plugin install records the
mode that produced it in the lock file.

| Mode          | Composer                     | When to use                                                       |
| ------------- | ---------------------------- | ----------------------------------------------------------------- |
| `development` | run by the Messenger worker  | Local dev only. Disabled in CI.                                   |
| `managed`     | run by the operator manually | Production. The worker writes a runbook entry into `plugin_operations.logs_json`; the operator finalizes with `selfhelp:plugin:run-operation`. |
| `trusted`     | run by the Messenger worker  | Air-gapped on-prem installs where signed archives are pre-vetted. |

Frontend bundles are runtime ESM. The host serves
`/plugin-artifacts/<id>-<ver>/plugin.esm.js` directly out of
`public/plugin-artifacts/`, so plugin updates never require an npm
install or Next.js rebuild on the host frontend.

---

## 6. Trust Levels and Capabilities

Three trust levels:

- `official` — published by the SelfHelp core team, signed Ed25519.
- `reviewed` — community plugins that passed the publishing review.
- `untrusted` — local-only plugins. Forbidden to ship a Composer
  package or run migrations. Allowed to contribute frontend-only
  styles / admin pages.

Capabilities are deny-by-default. The installer compares declared
capabilities against the plugin's trust level and refuses the install
if the plugin requests something its trust level doesn't allow. The
admin sees the unmet requirement in the install request UI.

---

## 7. Versioning and Compatibility

| Thing                | Versioning rule                                                        |
| -------------------- | ---------------------------------------------------------------------- |
| Plugin (`version`)   | SemVer. Patch = no migration. Minor = ships migration. Major = breaking.|
| SDK (`pluginApiVersion`) | Independent SemVer, currently `1.0`. Host honors `host minor >= plugin minor` within the same major. |
| Host CMS             | SemVer. Declared via `compatibility.selfhelp` (npm-style range).        |
| Lock file            | `schemaVersion` is part of the lock JSON. Currently `1.0`.             |

`assertPluginVersionSemantics(prev, next, { hasMigration })` runs in
plugin CI to catch SemVer rule violations before publishing.
`assertCmsCompatibility(cmsVersion, range)` runs at install time on
the host.

---

## 8. Data Storage Patterns

Plugins usually fall into one of these patterns:

1. **No persistent state** — purely visual styles. Nothing to store.
2. **Plugin-owned tables** — entities + migrations + services
   inside the plugin bundle. Use plugin-namespaced table names like
   `survey_runs`, `survey_responses`.
3. **CMS `data_tables` extension** — write form submissions / answers
   into existing `data_tables`/`data_rows`/`data_cells` so the rest of
   the CMS (export, analytics, scheduled jobs) sees them as normal
   submissions.

Patterns 2 and 3 can be combined. The SurveyJS plugin uses pattern 2
for survey definitions and pattern 3 for survey responses.

---

## 9. CMS Extension Points

The host exposes Symfony event subscribers and tagged services for the
common extension points. Plugins should always extend via these,
**never** by editing core files.

- **Sensible page lifecycle**: subscribe to
  `App\Plugin\Event\Page\PageContextEvent` to inject plugin styles into
  the page tree at render time.
- **Custom CMS field renderers**: register a tagged service for
  `selfhelp.plugin.field_renderer` to provide a custom edit/view widget
  for a manifest-declared field.
- **CSP rules**: declare `security.cspRules` in the manifest. The
  installer merges them into the global CSP on enable / disable.
- **Lookup registry**: declare `lookups.extends[]` in the manifest. The
  installer reconciles the rows on each install / update.
- **Scheduled jobs**: declare `scheduledJobs[]` in the manifest and
  register a tagged job handler (`selfhelp.plugin.scheduled_job`) in
  your bundle.
- **Realtime topics**: declared in manifest, published via
  `IPluginRealtimePublisher`.

---

## 10. Plugin Health and Diagnostics

Declare health checks in your plugin registration:

```ts
export const register = definePlugin({
    // ...
    healthChecks: [
        {
            key: 'example.license',
            label: 'License key reachable',
            severity: 'warning',
            run: async () => {
                const ok = await checkLicenseEndpoint();
                return ok ? { status: 'ok' } : { status: 'warn', detail: 'License endpoint unreachable.' };
            },
        },
    ],
});
```

Health checks show up:

- in the plugin detail page,
- as part of `selfhelp:plugin:doctor`,
- in CI when `selfhelp:plugin:doctor --ci` is wired into deployment
  preflight.

---

## 11. Plugin Lifecycle (operator side)

The CMS installer flow is **one HTTP request → one Messenger message**:

1. **Install / update** — the admin sends `POST /admin/plugins/install`
   or `POST /admin/plugins/{pluginId}/update` (JSON for
   `source ∈ {registry, url, paste}`, multipart for
   `source=archive`). The service validates compatibility +
   capabilities + signature + (on update) `expectedPluginId`, persists
   a `plugin_operations` row, and dispatches
   `InstallPluginMessage` / `UpdatePluginMessage` on the `plugin_ops`
   transport. The response is `202 Accepted` with the operation id.
2. **Worker run** — the Messenger worker
   (`php bin/console messenger:consume plugin_ops`) runs `composer
   require`, optionally promotes a `.shplugin` archive, runs the
   plugin's Doctrine migrations, regenerates
   `config/selfhelp_plugin_bundles.php`, writes the lock file, then
   dispatches the matching `Plugin{Installed,Updated}Event`. Progress
   is streamed to the `selfhelp/plugins/state` Mercure topic.
3. **Managed mode** — in `managed` mode the worker stops after writing
   a runbook entry into `plugin_operations.logs_json`. The operator
   runs the composer step + deploys + calls
   `selfhelp:plugin:run-operation <opId>` which invokes the same
   `finalize()`. No browser finalize step exists.
4. **Enable / disable** — synchronous; toggles the `enabled` flag,
   regenerates the bundles file, invalidates relevant caches.
5. **Uninstall** — `POST /admin/plugins/{pluginId}/uninstall` mirrors
   the install/update flow: persists an operation row, dispatches
   `UninstallPluginMessage`. The worker runs `composer remove`, then
   `PluginUninstaller::finalize()` deletes the `plugins` row and
   regenerates the lock + bundles files. Plugin-owned tables stay in
   place.
6. **Purge** — destructive. Drops plugin-owned tables and removes
   `id_plugins`-tagged rows. Requires `confirmedPluginId` in the
   request body and `--confirm` on CLI.
7. **Repair / sync-lock** — recomputes the lock file and bundles file
   from the DB state. Idempotent.

Every operation creates a `plugin_operations` row with type,
before/after manifest snapshots, bundles + lock file checksums,
migration scan, signing metadata (keyId + signature), and a rollback
descriptor.

---

## 12. Realtime Communication (no-polling policy)

The host uses Mercure for all dynamic updates. Plugins **must** use the
host realtime publisher; running an own Mercure client is forbidden
and will be blocked at install time.

Frontend plugins subscribe to topics through the host's React Query
key invalidation: the `usePluginRuntime()` snapshot lists declared
topics, and the host shell wires SSE subscriptions automatically. You
just have to publish.

If you absolutely must poll (e.g. a third-party LLM provider that
doesn't push), implement the polling **inside your backend scheduled
job**, not in the frontend.

---

## 13. Plugin AGENTS.md

Every plugin repo ships an `AGENTS.md` derived from
`docs/plugins/plugin-repo-agents-md-template.md`. The template embeds
the multi-repo coordination rules and the plugin-specific guard rails
the AI agent must follow when editing the plugin (manifest schema,
SemVer rule, capability declarations, migration safety, etc.).

Plugin repos must include the multi-repo rule, the architecture rule,
the lifecycle rule, and the SemVer rule **without modification**. The
plugin can append its own rules below.

---

## 14. Publishing

Production plugins are published to:

- **Composer registry** for the backend package, and / or
- **npm registry** (or a private scoped registry) for the frontend and
  mobile packages.

Each release ships:

- a tarball (CDN-cached),
- a SHA-256 checksum,
- optionally an Ed25519 signature for `official` plugins.

The host installer downloads the artifact, verifies the checksum
(mandatory) and the signature (when configured), and refuses the
install on mismatch.

The plugin must also publish a `registry.json` entry. See
`@selfhelp/shared/plugin-sdk` → `IPluginRegistry` for the document
shape.

---

## 15. Testing

Run the host CMS in development install mode:

```bash
APP_ENV=dev composer install
# Start the Messenger worker that processes plugin operations:
php bin/console messenger:consume plugin_ops --time-limit=3600
# In another shell, install a plugin from a local manifest or .shplugin:
php bin/console selfhelp:plugin:install path/to/plugin.json
# or
php bin/console selfhelp:plugin:validate-archive path/to/plugin-1.0.0.shplugin
```

Useful CLI commands:

| Command                                          | Purpose                                            |
| ------------------------------------------------ | -------------------------------------------------- |
| `selfhelp:plugin:install <manifest>`             | Dispatch an install operation                      |
| `selfhelp:plugin:update <manifest>`              | Dispatch an update operation                       |
| `selfhelp:plugin:enable <id>`                    | Enable a plugin                                    |
| `selfhelp:plugin:disable <id>`                   | Disable a plugin                                   |
| `selfhelp:plugin:uninstall <id>`                 | Dispatch an uninstall (keeps data)                 |
| `selfhelp:plugin:purge <id> --confirm`           | Drop plugin-owned tables                           |
| `selfhelp:plugin:sync-lock`                      | Regenerate lock + bundles file from DB state       |
| `selfhelp:plugin:check-compatibility`            | Per-plugin compatibility report                    |
| `selfhelp:plugin:check-updates`                  | Cross-reference installed plugins vs registries    |
| `selfhelp:plugin:doctor [--ci] [--json]`         | Global plugin health report                        |
| `selfhelp:plugin:safe-mode --enable|--disable`   | Emergency safe-mode (no plugin bundles)            |
| `selfhelp:plugin:run-operation <id>`             | Finalize a `managed` mode operation                |
| `selfhelp:plugin:validate-archive <path>`        | Run the inspect-archive pipeline on a local file   |
| `selfhelp:plugin:cleanup-archives`               | Reap orphaned `.shplugin` staging dirs             |
| `selfhelp:plugin:purge-staging <id> [--all]`     | Force-delete staging dirs for one or all plugins   |

---

## 16. Open Questions / Roadmap

- Mobile plugin packaging through EAS profile-specific lock entries
  is still being finalized (Phase 5).
- The SurveyJS plugin (Phase 6) is the first reference plugin and
  drives most of the SDK 1.0 surface validation.
- The plugin proxy / hook system described in `docs/plugin_hooks.md`
  is **proposal only** and will be retired in Phase 7 once the
  current event-based extension points cover all real-world cases.
