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
- `devEntrypointUrl` — optional dev-server URL. The host uses this only
  for local development installs that come from a pasted local manifest
  (for example `install-local.mjs --symlink` or
  `selfhelp:plugin:install <plugin.json>` on a dev host). Registry
  installs still pin the published runtime URL from the registry entry.

## Per-installation pin

The installed `Plugin` row stores the resolved URLs:

| Column                            | Source                                                            |
| --------------------------------- | ----------------------------------------------------------------- |
| `frontend_runtime_url`            | resolved install source runtime URL: dev URL for local paste installs, `/plugin-artifacts/...` for archives, published registry URL for registry installs |
| `frontend_runtime_stylesheet_url` | resolved install source stylesheet URL                            |
| `frontend_runtime_integrity`      | resolved install source integrity                                 |
| `frontend_runtime_format`         | resolved install source format                                    |

`selfhelp.plugins.lock.json#plugins.<id>.frontend` mirrors the same
fields. The lock file is the audit source the doctor compares against
when checking for drift.

### URL shapes by install source

| Install source            | Stored `frontend_runtime_url`                                                                   | Who chooses it                                                                                                                                                |
| ------------------------- | ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `registry`                | Absolute `https://…/artifacts/<id>-<ver>/plugin.esm.js`                                          | The publisher (`publish-to-registry.mjs` joins `<registry>/registry.json#baseUrl` to the relative artifact path **before** signing). See [`registry-and-channels.md`](./registry-and-channels.md#runtime-url-contract-must-be-absolute). |
| `url`                     | Absolute URL the manifest or registry entry served                                              | The publisher (manifest's `frontend.runtime.entrypoint` for `.shplugin`-derived hosts, or the registry entry passed alongside the manifest URL).               |
| `archive` (`.shplugin`)   | Host-relative `/plugin-artifacts/<id>-<ver>/plugin.esm.js`                                       | The host. `PluginInstaller` promotes the archive's `artifacts/plugin.esm.js` into `public/plugin-artifacts/<id>-<ver>/` and rewrites the URL accordingly.    |
| `paste` (development)     | Whatever the manifest's `frontend.runtime.devEntrypointUrl` declares (e.g. `http://localhost:5174/<id>/plugin.esm.js`) | The plugin author's dev workflow (`install-local.mjs --symlink`).                                                                                              |

The browser's `await import(<runtimeUrl>)` requires either an absolute
URL or a host-relative path starting with `/`. Bare module specifiers
like `artifacts/foo/plugin.esm.js` are rejected by every browser. The
canonical registry schema enforces `pattern: ^https?://` on
`runtime.entrypointUrl` and `runtime.stylesheetUrl` so a broken
publisher cannot reach GitHub Pages in the first place.

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
