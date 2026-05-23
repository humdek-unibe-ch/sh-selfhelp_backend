<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Plugin Repository `AGENTS.md` Template

Every SelfHelp plugin repository must contain an `AGENTS.md` at the root. Subdirectories (`backend/`, `frontend/`, `mobile/`) may add their own optional `AGENTS.md` for overrides. Copy the template below verbatim into the new repo, then fill in the plugin-specific sections marked with `<…>`.

The template assumes the plugin repo layout described in `docs/plugins/architecture.md`:

```
sh2-shp-<name>/
  AGENTS.md                       (this file)
  plugin.json
  README.md
  CHANGELOG.md
  LICENSE
  .github/workflows/validate-plugin.yml
  backend/                        Composer Symfony bundle
  frontend/                       npm package (web)
  mobile/                         optional npm package (Expo)
  docs/
```

## Template

````markdown
# AGENTS.md

Before returning anything print in chat `❤️AGENTS.md` so that we know the rules are used.

## Project Overview

This is the SelfHelp plugin `<plugin-id>` (e.g. `sh2-shp-survey-js`). It extends the SelfHelp CMS through the documented plugin ecosystem.

## Critical execution rule

This project lives inside the multi-repository SelfHelp ecosystem. Always obey the `AGENTS.md` of the repository whose files you are editing.

When editing files in this plugin repo, also re-read:

- the host backend `AGENTS.md` (`sh-selfhelp_backend/AGENTS.md`) before changing anything in `backend/`;
- the host frontend `AGENTS.md` (`sh-selfhelp_frontend/AGENTS.md`) before changing anything in `frontend/`;
- the shared package `AGENTS.md` (`sh-selfhelp_shared/AGENTS.md`) before changing anything that imports from `@selfhelp/shared`;
- the mobile `AGENTS.md` (`sh-selfhelp_mobile/AGENTS.md`) before changing anything in `mobile/`.

The canonical Multi-Repository AGENTS.md Rule lives at `sh-selfhelp_backend/docs/plugins/multi-repo-agents-md.md`. All paths are repository-relative inside the operator's workspace; never hard-code absolute paths.

## Extension points only

Plugins may only extend SelfHelp through documented extension points:

- Symfony events
- tagged services
- manifest-declared API routes
- style registry entries
- admin pages
- permissions
- scheduled jobs
- declared assets
- lookup entries (via the lookup registry policy)
- realtime Mercure topics (via `PluginRealtimePublisher`)

Plugins must not:

- directly patch core services;
- modify core routes at runtime;
- write to config files outside the installer;
- access DB tables they do not own unless the access is explicitly declared in the manifest under `dataAccess.read` / `dataAccess.write`;
- poll any core endpoint;
- touch protected core tables.

## Versioning semantics

This plugin follows SelfHelp version semantics:

- **patch** (`1.0.0 → 1.0.1`) — code change without DB change. No migration.
- **minor** (`1.0.x → 1.1.0`) — always carries a DB change. Always ships a migration.
- **major** (`1.x → 2.0`) — breaking change. Manifest's `pluginApiVersion` / `compatibility.selfhelp.*` ranges must be updated.

## Lookup registry policy

Enum-like values must live in the central `lookups` table, never as hardcoded constants in the plugin code. Lookup contributions:

- are declared in `plugin.json` under the `lookups.extends` block;
- are inserted/updated/deleted only by plugin migrations, never at runtime;
- every plugin-owned row must carry `id_plugins`.

## Realtime, no polling

Plugin progress, dashboards, chat, collaborative editing, file upload status, and job progress flow over Mercure through `App\Plugin\Realtime\PluginRealtimePublisherInterface`. Polling is only allowed for bootstrap, offline fallback, and emergency compatibility mode.

## Manifest is the source of truth

- All permissions, capabilities, dependencies, conflicts, lookups, feature flags, realtime topics, and security declarations live in `plugin.json`.
- The validator + installer derive their behavior from the manifest. Do not duplicate this metadata in code.

## Repository structure (this repo)

- `plugin.json` — manifest validated by `docs/plugins/plugin-manifest.schema.json` in the backend repo.
- `backend/` — Composer package (Symfony bundle). Type `symfony-bundle`. Package name `humdek/<plugin-id>`.
- `frontend/` — npm package. Package name `@humdek/<plugin-id>`.
- `mobile/` — optional npm package. Package name `@humdek/<plugin-id>-mobile`.
- `docs/` — plugin-specific docs.
- `.github/workflows/validate-plugin.yml` — CI validation.
- `.github/workflows/publish-to-registry.yml` — tag-triggered registry publish.
- `scripts/build-shplugin.mjs` — builds + signs the `.shplugin` archive.
- `scripts/publish-to-registry.{sh,ps1}` — publishes a new version to the public registry repo.
- `scripts/install-local.{sh,ps1}` — local dev install (symlink fast-path or `.shplugin` upload).

## `.gitignore` (mandatory)

Every plugin repo MUST gitignore: `node_modules/`, `backend/vendor/`,
`frontend/dist/`, `mobile/dist/`, `dist/`, `*.shplugin`,
`.signing-keys/`, `*.ed25519`, `*.priv`, `*.pem`, `.env`, `.env.local`,
`coverage/`, `.phpunit.result.cache`. See
`docs/plugins/publishing-workflow.md` §1.2 for the canonical template.

NEVER commit private Ed25519 signing keys. Use GitHub Actions
repository secrets:

- `SELFHELP_PLUGIN_SIGNING_KEY` — Ed25519 secret key (base64).
- `SELFHELP_PLUGIN_SIGNING_KEY_ID` — must match a host
  `SELFHELP_PLUGIN_TRUSTED_KEYS` entry.
- `REGISTRY_PUSH_TOKEN` — PAT with `contents:write` on
  `humdek-unibe-ch/sh2-plugin-registry`.

## Coding style

- PHP files use the project SPDX header (`SPDX-FileCopyrightText: 2026 Humdek, University of Bern` + `SPDX-License-Identifier: MPL-2.0`).
- TS/TSX files use the matching two-line SPDX comment header.
- Symfony backend code follows the host backend `AGENTS.md`.
- Frontend code follows the host frontend `AGENTS.md`.
- Mobile code follows the host mobile `AGENTS.md`.

## Trust level

This plugin's `security.trustLevel` is `<official | reviewed | untrusted>`. The host installer enforces the cartesian product `(trust_level × capability)`. Do not request capabilities the trust level cannot grant.

## Validation commands

- Manifest: `npx ajv-cli validate -s ../../sh-selfhelp_backend/docs/plugins/plugin-manifest.schema.json -d plugin.json`
- Backend: `composer install -d backend`, `composer phpstan -d backend`, `composer test -d backend`
- Frontend: `npm ci --prefix frontend`, `npm run typecheck --prefix frontend`, `npm run build --prefix frontend`
- Mobile (if present): `npm ci --prefix mobile`, `npm run typecheck --prefix mobile`, `npm run build --prefix mobile`

## Do not

- Do not patch any core service or core route at runtime.
- Do not insert/update/delete lookup rows at runtime.
- Do not poll core endpoints; use Mercure realtime topics.
- Do not access protected core tables.
- Do not ship CDN-loaded assets unless declared in `security.externalHosts`.
- Do not commit or push without explicit instruction.
````
