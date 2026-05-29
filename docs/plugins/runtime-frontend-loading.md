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

| Install source            | Stored `frontend_runtime_url`                                                                   | Who chooses it / how the artifact lands on disk                                                                                                                                                                                                                                                                            |
| ------------------------- | ----------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `registry`                | Host-relative `/plugin-artifacts/<id>-<ver>/plugin.esm.js`                                       | The host. `InstallPluginHandler` invokes `PluginRuntimeArtifactFetcher` to download the absolute `https://…/artifacts/<id>-<ver>/plugin.esm.js` from the registry, verify its SHA-256 against `expectedChecksums.frontendEsm` (signed by the publisher), fetch the sibling `SHA256SUMS` manifest, verify it is anchored to the same signed entry hash, then download every listed code-split chunk and verify each chunk's SHA-256 before promoting the whole tree into `public/plugin-artifacts/<id>-<ver>/`. |
| `url`                     | Host-relative `/plugin-artifacts/<id>-<ver>/plugin.esm.js`                                       | The host. Same flow as `registry` — fetcher reads `resolved.runtime.entrypointUrl`, verifies the checksum + SHA256SUMS-listed chunks, promotes the whole tree to the host.                                                                                                                                                  |
| `archive` (`.shplugin`)   | Host-relative `/plugin-artifacts/<id>-<ver>/plugin.esm.js`                                       | The host. `PluginArchivePromoter` promotes the archive's `artifacts/` tree (entry + stylesheet + every code-split chunk) out of the staging dir into `public/plugin-artifacts/<id>-<ver>/`. SHA256SUMS in the archive is validated by `PluginArchiveValidator` before promotion.                                              |
| `paste` (development)     | Whatever the manifest's `frontend.runtime.devEntrypointUrl` declares (e.g. `http://localhost:5174/<id>/plugin.esm.js`) | The plugin author's dev workflow (`install-local.mjs --symlink`). No download — Vite serves the bundle from the dev server with live reload.                                                                                                                                                                                |

Every non-dev install ends up serving the bundle from
`/plugin-artifacts/<id>-<ver>/` on the SelfHelp host. This is
**mandatory** because plugin ESM bundles emit host-only imports like
`/api/plugins/runtime-shim/react` and `/api/plugins/runtime-shim/@selfhelp/shared/plugin-sdk`,
which the browser resolves against the importing module's origin.
Serving the bundle from a CDN (or directly from GitHub Pages) would
make those internal imports resolve to the CDN origin and 404, so the
dynamic `import()` would reject with "Failed to fetch dynamically
imported module" even though the entry URL itself is reachable.

The canonical registry schema still enforces `pattern: ^https?://`
on `runtime.entrypointUrl` and `runtime.stylesheetUrl` — that absolute
URL is the *download source* the host fetches from at install time,
not the URL the browser ever uses at runtime.

### Code-split chunks & the `SHA256SUMS` manifest

Modern Vite library builds emit a single entry (`plugin.esm.js`) plus
one or more *code-split chunks* whose filenames embed a content hash
(`survey-creator-react-DJSXYH6o.js`, `_commonjsHelpers-DaMA6jEr.js`,
…). Once the host imports `plugin.esm.js`, the chunk imports inside
the bundle resolve relative to the entry's URL — so every chunk
**must live in the same directory** as the entry on the host.

For `archive` installs this is automatic: `PluginArchivePromoter`
copies the entire `artifacts/` tree. For `registry` / `url` installs
the host can't enumerate the registry's directory listing over HTTP,
so the publisher ships a `SHA256SUMS` text file next to the entry
(format: `<sha256>  <filename>` per line) listing **every** artifact
that was produced by the build. `PluginRuntimeArtifactFetcher` does:

1. Download + verify `plugin.esm.js` against the signed
   `checksums.frontendEsm`.
2. Download + verify `plugin.css` against `checksums.frontendCss`
   when the manifest declares a stylesheet.
3. Try to fetch the sibling `<entrypoint-dir>/SHA256SUMS`.
   - If the URL 404s, the plugin has no chunks (or predates the
     manifest contract). Promotion completes with just entry + css.
   - If found, parse the manifest. Refuse to trust it unless its
     `plugin.esm.js` line's hash matches the signed
     `checksums.frontendEsm` (anchoring chunk integrity to the same
     payload the publisher signed).
4. For every other listed filename: download it next to the entry
   and verify its SHA-256 against the manifest line. Mismatch =
   abort install. Path traversal (`../`, absolute paths, etc.) is
   refused before the request is even made.

The publish script (`scripts/publish-to-registry.mjs`) re-emits a
stripped-down `SHA256SUMS` for the registry artifacts dir (bare
filenames, no archive-root prefix, frontend lines only) so the host
parser does not have to know anything about the `.shplugin` archive
layout.

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
2. `npm --prefix frontend run dev:runtime` boots a Vite middleware
   server that transforms `src/index.ts` on demand (no full library
   rebuild between edits), serves the bundle at the declared dev URL,
   exposes the `__selfhelp_plugin_reload` SSE endpoint, and proxies
   `/api/plugins/runtime-shim/*` back to the Next.js host so bare
   specifiers like `react` and `@mantine/core` resolve to the host's
   singleton modules — not a second copy from the plugin's own
   `node_modules`. The shim-rewriting Vite plugin must run in BOTH
   build and dev modes; gating it to `command === 'build'` was the
   root cause of dev-runtime live reload regressions in earlier
   plugin versions.
3. The host `PluginRuntime` listens to that endpoint, disposes the
   existing plugin registration, re-imports `plugin.esm.js` with a
   cache-busting query string, and updates the active admin/styles
   snapshot. A hard refresh is only needed if the module itself fails to
   re-register cleanly.

The full singleton list the host shims to plugin bundles lives in
`@selfhelp/shared/plugin-sdk` under `PLUGIN_RUNTIME_SHIM_SPECIFIERS`.
Both the plugin's Vite build and the host's `/api/plugins/runtime-shim/*`
allowlist read from that constant, so they cannot drift.
