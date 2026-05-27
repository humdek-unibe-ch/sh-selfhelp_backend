<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Runtime frontend loading (ESM)

SelfHelp v2 plugins ship their frontend code as **runtime-loaded ESM
bundles**. The host frontend never imports a plugin's npm package at
build time. Every plugin frontend bundle is fetched at boot via
`await import(<url>)` and registered through the public Plugin SDK.

Why:

- The host frontend rarely needs to rebuild when plugin frontend code
  changes — `next build` produces a stable bundle and plugin updates
  ship through the registry alone.
- Plugin authors keep full control over their tooling (Vite, esbuild,
  Rollup, whatever) as long as they output a single ESM entry.
- The host shell can publish a strict CSP because the only allowed
  `script-src` origins are the configured registry/source URLs.

## Manifest declaration

Every published `plugin.json` declares its runtime under
`frontend.runtime`:

```json
"frontend": {
  "runtime": {
    "entrypoint": "dist/plugin.esm.js",
    "stylesheet": "dist/plugin.css",
    "format": "esm",
    "devEntrypointUrl": "http://localhost:5174/sh2-shp-survey-js/plugin.esm.js"
  }
}
```

- `entrypoint` — repo-relative path inside the .shplugin. The host
  publish/promote step rewrites this to the public URL of the served
  artifact (`/plugin-artifacts/<id>-<version>/plugin.esm.js`).
- `stylesheet` — optional. The host runtime injects it with `integrity`
  + `crossorigin` attributes when present.
- `format` — `esm` (only supported value in v1).
- `devEntrypointUrl` — optional dev-server URL. In `development` install
  mode the host loads this URL instead of the published artifact so
  the plugin's Vite dev server (with HMR) drives the loaded code.

## Per-installation pin

The installed `Plugin` row stores the resolved URLs:

| Column                            | Source                                                            |
| --------------------------------- | ----------------------------------------------------------------- |
| `frontend_runtime_url`            | `frontend.runtime.entrypoint` after promotion                     |
| `frontend_runtime_stylesheet_url` | `frontend.runtime.stylesheet` after promotion                     |
| `frontend_runtime_integrity`      | `frontend.runtime.integrity`                                      |
| `frontend_runtime_format`         | `frontend.runtime.format`                                         |

`selfhelp.plugins.lock.json#plugins.<id>.frontend` mirrors the same
fields. The lock file is the audit source the doctor compares against
when checking for drift.

## Host runtime loader

`sh-selfhelp_frontend/src/app/components/frontend/plugin-runtime/PluginRuntime.ts`
boots once per app load. For each plugin in the manifest:

1. If `frontend_runtime_url` is missing → warn + skip (typically a
   backend-only plugin).
2. If `stylesheet_url` is set → inject a `<link rel="stylesheet"
   crossorigin="anonymous" [integrity=…]>` and remember the node for
   unmount.
3. Dynamic import: `const mod = await import(runtimeUrl)`. The bundle
   must export `register(api)` (default or named).
4. Call `register(pluginApi)`. The returned `IPluginRegistration`
   payload is stored in the registry; the host merges its styles /
   admin pages / menu items / feature flags / realtime topics / health
   checks into the host shell.
5. If the plugin is later disabled or uninstalled, the host calls the
   registration's `dispose` (if present) and removes the stylesheet
   `<link>`.

## CSP

The host shell extends its `script-src`, `style-src`, and `connect-src`
with the published origin of every enabled plugin source plus the
host's own `/plugin-artifacts/` path. The admin CMS automatically
appends an origin when a new source is enabled; admins do **not**
need to hand-edit CSP rules.

For local dev with a plugin Vite dev server, set
`SELFHELP_PLUGIN_DEV_ORIGINS=http://localhost:5174,http://localhost:5175`
to extend the CSP for the development build.

## Dev workflow

```bash
# One-time attach/register from the plugin checkout:
cd plugins/sh2-shp-survey-js
node scripts/install-local.mjs --symlink

# Plugin terminal:
npm --prefix frontend run dev:runtime

# Host terminal (no rebuild needed for plugin UI edits):
cd sh-selfhelp_frontend
npm run dev
```

When the plugin's source changes:

1. `install-local.mjs --symlink` passes a temporary path-repository
   manifest to the plugin installer. The Messenger worker installs the
   backend package into the isolated plugin Composer root
   (`var/plugin-composer/`), registers the bundle through the generated
   plugin bundles file, runs the plugin migrations, enables the plugin,
   and stores the manifest `devEntrypointUrl` as the active runtime URL.
   The host root `composer.json`, `composer.lock`, and
   `config/bundles.php` stay untouched.
2. `npm --prefix frontend run dev:runtime` runs the plugin's Vite
   library build in watch mode, serves `frontend/dist` at the declared
   dev URL, and exposes a small SSE reload endpoint.
3. The host `PluginRuntime` listens to that endpoint, disposes the
   existing plugin registration, re-imports `plugin.esm.js` with a
   cache-busting query string, and updates the active admin/styles
   snapshot. A hard refresh is only needed if the module itself fails to
   re-register cleanly.
