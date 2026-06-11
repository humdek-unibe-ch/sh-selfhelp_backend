<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Plugin CI workflows

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Plugin layer code and the schemas under this folder.

This document describes every GitHub Actions workflow that protects the plugin ecosystem. They are the executable contract that backs the implementation plan in `selfhelp_plugin_ecosystem_f01f00ef.plan.md` (§21 CI/CD integration).

Each repository in the ecosystem has its own workflow. They share a common structure:

- run on every `pull_request` to `main` and on every `push` to `main`;
- pin Node 22 and (where applicable) PHP 8.4;
- never depend on a live database or live Mercure hub;
- fail fast on anything that would silently degrade the contract between hosts and plugins.

There are seven workflows in total — five validation gates plus two registry pipelines.

| # | Workflow file | Repository | Owner of the contract |
|---|---------------|------------|------------------------|
| 1 | `.github/workflows/plugin-host-check.yml`      | `sh-selfhelp_backend`        | Backend host: schemas, CLI, DI graph, Doctrine mapping |
| 2 | `.github/workflows/plugin-runtime-check.yml`   | `sh-selfhelp_frontend`       | Frontend host: PluginRuntime, host singletons, Next.js build |
| 3 | `.github/workflows/plugin-sdk-check.yml`       | `sh-selfhelp_shared`         | Shared SDK: TS types ↔ JSON schemas, build, hook export |
| 4 | `.github/workflows/plugin-mobile-check.yml`    | `sh-selfhelp_mobile`         | Mobile host: registry parity, plugins:sync, web export |
| 5 | `.github/workflows/validate-plugin.yml`        | every `plugins/<plugin-id>/` | Per-plugin gate (every PR / push to main): manifest, DB-naming, builds, host-singleton policy |
| 6 | `.github/workflows/publish-to-registry.yml`    | every `plugins/<plugin-id>/` | Tag-triggered publish: pushes a manifest entry to `sh2-plugin-registry`. |
| 7 | `.github/workflows/build-registry.yml`         | `sh2-plugin-registry`        | Validates the registry contents and publishes them to GitHub Pages. |

## How they work together

```
                ┌───────────────────────────────────────────┐
                │           pull_request to main            │
                └───────────────────────────────────────────┘
                                     │
        ┌─────────────┬──────────────┼──────────────┬─────────────────┐
        ▼             ▼              ▼              ▼                 ▼
  plugin-host-   plugin-runtime-  plugin-sdk-  plugin-mobile-   validate-plugin
   check.yml       check.yml      check.yml      check.yml         .yml
   (backend)       (frontend)     (shared)       (mobile)         (plugin)
        │             │              │              │                 │
        └─────────────┴──────────────┴──────────────┴─────────────────┘
                                     │
                     all must pass before merge to main
```

The plugin workflow is published in every plugin repository (the SurveyJS plugin is the reference implementation). It can be copied unchanged into any new plugin — only the `humdek-unibe-ch/sh-selfhelp_backend` reference would need to be repointed if a fork lives elsewhere.

## 1. `plugin-host-check.yml` (backend)

**Repository**: `sh-selfhelp_backend`

**Purpose**: guard the Symfony plugin host — the code that loads, validates, and orchestrates plugins.

| Job step | What it actually does | Failure means |
|----------|------------------------|----------------|
| Validate plugin JSON Schemas | Compiles `plugin-manifest.schema.json`, `plugin-registry.schema.json`, and `plugin-lock.schema.json` with `ajv` (Draft-07). | A schema we publish is itself broken — every downstream plugin would fail to validate. |
| Validate admin-plugins request schemas | Compiles every `config/schemas/api/v1/requests/admin/plugins/*.json` request body schema. | The admin plugin manager endpoints would reject all input. |
| PHPStan (plugin scope) | Runs `phpstan` on `src/Plugin`, `src/Controller/Api/V1/Admin/Plugin`, `src/Controller/Api/V1/Plugin` at the plugin-host repo's configured level. | A type regression in the plugin manager — the most fragile area of the host. |
| Doctrine mapping validation | `bin/console doctrine:schema:validate --skip-sync`. Verifies entity mapping is internally consistent without touching a DB. | An entity mapping is malformed (forgotten FK, broken association). |
| Plugin CLI list + `--help` | Lists `selfhelp:plugin:*` commands and calls `--help` on each of the 14 canonical commands. | A DI graph regression makes a command unconstructible. |
| Plugin DI autowiring | `debug:autowiring 'App\\Plugin'` + greps for `PluginManifestLoader`, `PluginRealtimePublisherInterface`, `PluginAdminService`. | A service definition got lost or renamed without the public alias being updated. |
| `selfhelp:plugin:doctor` smoke | Runs the doctor command on a fresh checkout (no plugin installed) and tolerates a non-zero exit. | The doctor command throws or imports something that no longer exists. |

**Local equivalent**:

```bash
composer install
composer phpstan
php bin/console doctrine:schema:validate --skip-sync --no-interaction
php bin/console list selfhelp:plugin --no-interaction
php bin/console selfhelp:plugin:doctor --no-interaction
```

## 2. `plugin-runtime-check.yml` (frontend)

**Repository**: `sh-selfhelp_frontend`

**Purpose**: guard the Next.js plugin runtime — `PluginRuntime`, `PluginsProvider`, the open `BasicStyle` dispatcher, and the admin Plugins UI.

| Job step | What it actually does | Failure means |
|----------|------------------------|----------------|
| Type-check | `npx tsc --noEmit` over the whole tree. Catches plugin contributions that broke `IPluginRegistration` or `IStyleDefinition`. | Type contract regression. |
| ESLint | `npm run lint --max-warnings=0` (warns only — does not block merge). | Style regression. |
| Plugins sync dry-run | When `SELFHELP_BACKEND_URL` is set, runs `node scripts/plugins-sync.mjs --backend <url> --dry-run` and fails if the script would modify any tracked file (`package.json`, `selfhelp.plugins.lock.json`, `registered.ts`). | Manifest-driven dependencies drifted from what is committed — non-deterministic builds. |
| Host-singleton dependency check | `npm ls --all <pkg>` for `react`, `react-dom`, `@tanstack/react-query`, `@mantine/core`. Counts unique resolved versions. | A plugin (or a host package update) dragged in a second copy of a singleton dep. React explodes when this happens. |
| Next.js build | `npm run build`. The full production build is the ultimate proof that no plugin import or admin page broke a route. | Production build regression. |

**Local equivalent**:

```bash
npm ci
npx tsc --noEmit
npm ls --all react react-dom @tanstack/react-query @mantine/core
npm run build
```

## 3. `plugin-sdk-check.yml` (shared)

**Repository**: `sh-selfhelp_shared`

**Purpose**: guard the contract every plugin compiles against — `@selfhelp/shared` itself.

| Job step | What it actually does | Failure means |
|----------|------------------------|----------------|
| Type-check | `npm run typecheck`. | Any type regression in `definePlugin`, `defineMobilePlugin`, `IPluginRegistration`, `usePluginRealtime`, etc. |
| Build | `npm run build` (tsup CJS + ESM + DTS). | Published artifact would be unbuildable. |
| Verify `usePluginRealtime` export | Greps `dist/plugin-sdk/index.{d.ts,js,mjs}` for `usePluginRealtime`. | The realtime hook stopped being exported (plan §10 contract regression). |
| Schema parity | Runs `scripts/check-schema-parity.mjs`. Compares the `required` arrays of `plugin-manifest.schema.json`, `plugin-registry.schema.json`, and `plugin-lock.schema.json` (cloned from `sh-selfhelp_backend`) against the matching `src/plugin-sdk/{manifest,registry,lock}.ts` mirrors. | A JSON Schema gained a new required property but the TypeScript mirror was not updated (or vice versa). |
| Tests | `npm test` if any Vitest tests exist. | Unit-test regression. |

**Local equivalent**:

```bash
npm ci
npm run typecheck
npm run build
npm run check:schemas
npm test
```

The schema-parity script lives in `scripts/check-schema-parity.mjs`. It cleanly **skips** with exit 0 when the backend repo is not checked out next to the shared repo, so local developers without a backend checkout can still run `npm run check:schemas` without spurious failures. In CI the workflow explicitly clones `humdek-unibe-ch/sh-selfhelp_backend`, so the check is binding there.

## 4. `plugin-mobile-check.yml` (mobile)

**Repository**: `sh-selfhelp_mobile`

**Purpose**: guard the Expo / React Native plugin runtime.

| Job step | What it actually does | Failure means |
|----------|------------------------|----------------|
| Type-check | `npm run typecheck` over the whole app. | A plugin style impl signature drift. |
| Style registry parity | `npm test` runs `__tests__/registry-parity.test.mjs` under the built-in Node test runner. For every category of `BASE_STYLE_REGISTRY` it asserts that each core style has a matching `styleImpls` entry and there are no orphan impls. | A core style was added without a mobile impl — the app would render `UnknownStyle` at runtime. |
| Plugins sync dry-run | Same contract as the frontend. | Mobile manifest drifted from what is committed. |
| Expo web export | `npm run web:build`. Soft-fail to avoid blocking on Expo config issues that the runner cannot resolve; native EAS builds remain on EAS Cloud. | Web bundle regression (informational). |

**Local equivalent**:

```bash
npm ci --legacy-peer-deps
npm run typecheck
npm test
npm run web:build
```

## 5. `validate-plugin.yml` (per-plugin)

**Repository**: every `plugins/<plugin-id>/` — the SurveyJS plugin is the reference.

**Purpose**: gate every plugin so it satisfies the published contract before a registry would publish a new version.

| Job | What it actually does | Failure means |
|-----|------------------------|----------------|
| `manifest` | Validates `plugin.json` against the host's `plugin-manifest.schema.json` with `ajv`. Then cross-checks that `dataAccess.ownedTables` lists exactly the tables the migration creates. | The plugin lies about which tables it owns. |
| `db-naming` | Greps the migration file for `CREATE TABLE`, `FOREIGN KEY`, and `CONSTRAINT/INDEX/UNIQUE INDEX` statements. Enforces three host AGENTS.md DB rules: plural lowercase_snake_case table names; FK columns shaped as `id_<plural_target_table>` (no singular forms like `id_survey` or `created_by_user_id`); constraint / index names prefixed with `pk_/fk_/idx_/uq_`. | The plugin breaks the host DB naming convention. This is exactly the rule the SurveyJS plugin violated in its first revision; this job exists so it never happens again. |
| `backend` | `composer install` → PHPStan → PHPUnit when tests exist. | A type or runtime regression in the bundle. |
| `frontend` | `npm ci` → typecheck → build. Then enforces the **host-singleton policy**: `react`, `react-dom`, `@selfhelp/shared`, `@mantine/core`, `@tanstack/react-query` must NEVER appear under `dependencies` — only under `peerDependencies`. | A second copy of a host singleton would be installed at runtime and break React reconciliation. |
| `mobile` | Same shape as frontend, host-singleton list is `react`, `react-native`, `expo-router`, `@selfhelp/shared`. | Mobile-side host-singleton violation. |

**Local equivalent for a new plugin author** (run from inside the plugin repo):

```bash
# Manifest
npx -y ajv-cli@5 validate \
  -s ../../sh-selfhelp_backend/docs/plugins/plugin-manifest.schema.json \
  -d plugin.json --strict=false

# Backend
cd backend && composer install && composer phpstan && cd ..

# Frontend
cd frontend && npm ci && npm run typecheck && npm run build && cd ..

# Mobile
cd mobile && npm ci && npm run typecheck && npm run build && cd ..
```

## Required GitHub repository configuration

The workflows are zero-config out of the box, but two optional inputs unlock the deterministic plugin-sync check:

| Input | Where to set | Used by | Purpose |
|-------|--------------|---------|---------|
| `SELFHELP_BACKEND_URL` | Repository → Settings → Variables → Actions | `plugin-runtime-check.yml`, `plugin-mobile-check.yml` | Public base URL of a SelfHelp backend that exposes `/api/plugins/manifest`. When unset the check is skipped (not failed). |
| `SELFHELP_API_TOKEN`   | Repository → Settings → Secrets → Actions | same | Bearer token for the manifest endpoint when the backend requires authentication. |

When both inputs are configured the workflow asserts the plugin lock file + `package.json` + the per-EAS registered file are exactly what `plugins:sync --dry-run` would write — failing if the repo's committed copy is stale. This is how the plan's "deterministic plugin pinning" contract is enforced in CI.

## 6. `publish-to-registry.yml` (per-plugin)

**Repository**: every `plugins/<plugin-id>/` — the SurveyJS plugin is the reference.

**Purpose**: ship a tagged plugin version to the public
`humdek-unibe-ch/sh2-plugin-registry` repository so every host that has
the seeded `humdek-public` source picks it up automatically on the next
"Available" tab open.

**Trigger**: push of a `v*` tag (e.g. `git tag v0.2.0 && git push --tags`)
or a manual `workflow_dispatch` (with a channel input).

| Job step | What it does | Failure means |
|----------|--------------|---------------|
| Checkout plugin + registry | Clones the plugin repo and the `humdek-unibe-ch/sh2-plugin-registry` repo using the `REGISTRY_PUSH_TOKEN` PAT. | Without the secret the job still builds + validates but skips the push, so a fresh fork can dry-run the workflow. |
| Validate manifest | Runs `ajv` against the vendored `docs/plugins/plugin-manifest.schema.json`. | Tag must reflect a manifest that the host can install. |
| Build frontend / mobile | `npm install --legacy-peer-deps && npm run build` in `frontend/` and `mobile/`. | Build regression — the registry never advertises an unbuildable version. |
| `node scripts/publish-to-registry.mjs --skip-build --push` | Copies the plugin's `plugin.json` to `<registry>/manifests/<id>-<version>.json`, upserts the entry in `<registry>/registry.json`, commits, and pushes. | The registry repo's own `build-registry.yml` workflow (see below) takes over to publish to GitHub Pages. |

**The flow end-to-end**:

```text
Plugin repo:
  git tag v0.2.0
  git push --tags
            │
            ▼  publish-to-registry.yml
  - validate manifest
  - build frontend + mobile
  - copy manifest to sh2-plugin-registry/manifests/
  - update registry.json (sorted by id; previous entries for same id are replaced)
  - commit + push to registry main
            │
            ▼  build-registry.yml (in sh2-plugin-registry)
  - validate registry.json against plugin-registry.schema.json
  - validate every manifest in manifests/ against plugin-manifest.schema.json
  - publish to GitHub Pages (https://humdek-unibe-ch.github.io/sh2-plugin-registry/)
            │
            ▼  Hosts with `humdek-public` source enabled
  - the next "Available" tab open shows v0.2.0
  - admins click Install → Request → composer/npm → Finalize
```

**Required secrets / variables** on the plugin repo:

| Secret | Used for |
|--------|----------|
| `REGISTRY_PUSH_TOKEN` | Personal Access Token with `contents:write` on `humdek-unibe-ch/sh2-plugin-registry`. Without it the job runs in dry-run mode and prints a warning to the workflow summary. |

**Optional flags** when running `scripts/publish-to-registry.mjs`
locally (single cross-platform Node script — works on PowerShell,
Git Bash, WSL, macOS, and Linux):

```bash
# Dry-run (does not commit anything)
node scripts/publish-to-registry.mjs --dry-run

# Different release channel (default is 'stable')
node scripts/publish-to-registry.mjs --channel beta --push

# Auto-publish the npm packages too (needs NPM_TOKEN in env or .env)
node scripts/publish-to-registry.mjs --publish-npm --push

# Cut a GitHub Release (uses `gh` CLI, attaches the .shplugin)
node scripts/publish-to-registry.mjs --release --push
```

The script auto-loads `<plugin>/.env`, so
`SELFHELP_SIGNING_KEY` /
`SELFHELP_SIGNING_KEY_ID` /
`SELFHELP_REGISTRY_PATH` / `NPM_TOKEN` can live in a single gitignored
file next to `plugin.json`. Real `process.env` values still win, so
secrets injected by the GitHub Action override the file automatically.

The current GitHub Actions workflow does **not** pass `--publish-npm`.
Plugin authors who want the same tag to push to npm can either add an
explicit `npm publish` step before the `Publish to registry` step, or
extend the workflow with an `NPM_TOKEN` secret and a custom flag. The
registry only advertises the version + manifest; it does not bundle
the npm artefact.

## Adding a new plugin

1. Copy `plugins/sh2-shp-survey-js/.github/workflows/validate-plugin.yml`
   **and** `publish-to-registry.yml` into the new plugin repository.
2. Verify the `backend/`, `frontend/`, and `mobile/` directory names match the new plugin's layout (or adjust the workflow accordingly).
3. Make sure the plugin's `plugin.json` declares `dataAccess.ownedTables` correctly — the `manifest` job will compare it to your migration file.
4. The first push will run all five validation jobs; if any DB-naming gate trips, fix the entity / migration before publishing the plugin to the registry.
5. Add `REGISTRY_PUSH_TOKEN` to the new repo's secrets (Settings → Secrets and variables → Actions). Without it the publish workflow runs in dry-run mode.
6. Tag the first release: `git tag v0.1.0 && git push --tags`.
7. Confirm the registry workflow appended a manifest under `sh2-plugin-registry/manifests/<id>-<version>.json`. If GitHub Pages is the source for the registry repo, the new version is visible at `https://humdek-unibe-ch.github.io/sh2-plugin-registry/registry.json` within a minute.

## When a check is too strict

If a job in a host workflow blocks a merge for a reason that is clearly not the plan's intent, prefer to:

1. Open a follow-up PR adjusting the workflow alongside the code change.
2. Avoid `continue-on-error: true` on the binding gates (manifest schema, DB naming, host-singleton, type-check, build). The plan-§21 contract is the single most useful regression guard in the repo.
3. Use a workflow-level `if: github.event.pull_request.head.repo.fork == false` only for jobs that need a registry token (none of the current jobs do, but future ones might).

## See also

- Plan: `selfhelp_plugin_ecosystem_f01f00ef.plan.md` §21 (CI/CD integration).
- Host AGENTS.md → "Database Rules" — the rules the per-plugin `db-naming` job enforces.
- `docs/plugins/architecture.md` — the architectural contract every check in this document defends.
