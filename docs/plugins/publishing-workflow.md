<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Plugin publishing workflow (end-to-end)

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Plugin layer code and the schemas under this folder.

This is the canonical walkthrough every plugin author follows from the
first commit in a new plugin repo to a host that picks the release up
from the public registry. It also documents **exactly** which files,
folders, and database rows are created on the host when an admin clicks
*Install*, so operators know what to back up, what to gitignore, and what
to expect on disk.

Read it together with:

- [`developer-guide.md`](./developer-guide.md) — plugin anatomy + SDK contract.
- [`distribution.md`](./distribution.md) — the four install sources.
- [`shplugin-archive.md`](./shplugin-archive.md) — `.shplugin` file layout + backend pipeline.
- [`ci-workflows.md`](./ci-workflows.md) — GitHub Actions reference for every workflow.
- [`signing.md`](./signing.md) — Ed25519 signing details + key rotation.
- [`runtime-frontend-loading.md`](./runtime-frontend-loading.md) — host-side ESM loader.

---

## 1. Set up the plugin repository

### 1.1 Skeleton

Copy the skeleton from the SurveyJS reference plugin
(`plugins/sh2-shp-survey-js`). The minimal layout the host expects:

```
sh2-shp-<plugin-id>/
├── plugin.json                       # canonical manifest, schema v1.0
├── README.md
├── CHANGELOG.md                      # Keep-a-Changelog
├── LICENSE                           # MPL-2.0 (recommended)
├── AGENTS.md                         # from docs/plugins/plugin-repo-agents-md-template.md
├── .gitignore                        # see §1.2
├── .github/
│   └── workflows/
│       ├── validate-plugin.yml       # CI gate (manifest + builds + DB naming)
│       └── publish-to-registry.yml   # publishes on `v*` tag
├── backend/                          # Symfony bundle (optional)
│   ├── composer.json                 # type=symfony-bundle, package=humdek/<id>
│   ├── src/<Vendor><Name>Bundle.php  # Symfony Bundle entry point
│   ├── src/Migrations/               # Doctrine migrations (generated)
│   └── src/...                       # services, controllers, etc.
├── frontend/                         # ESM runtime bundle (optional)
│   ├── package.json                  # name=@humdek/<id>, host singletons → peerDeps
│   ├── vite.config.ts                # mode='lib', outputs dist/plugin.esm.js + plugin.css
│   └── src/index.ts                  # exports register(api)
├── mobile/                           # Expo plugin package (optional)
│   ├── package.json                  # name=@humdek/<id>-mobile
│   └── src/index.ts                  # exports registerMobile(api)
├── docs/
│   └── install.md                    # per-plugin install recipes
├── .env.example                  # documents SELFHELP_PLUGIN_*_SIGNING_KEY, SELFHELP_ADMIN_TOKEN, etc.
└── scripts/
    ├── build-shplugin.mjs            # canonical Node builder + signer
    ├── publish-to-registry.mjs       # canonical Node publisher (registry + GH release)
    └── install-local.mjs             # canonical Node local installer (.shplugin upload + symlink fast-path)
```

Every script under `scripts/` is a single cross-platform Node `.mjs`
file. There are **no** `.ps1` / `.sh` wrappers — PowerShell, Git
Bash, WSL, macOS, and Linux all run the same code path. Each script
auto-loads `<plugin-root>/.env` via Node 22's `process.loadEnvFile`,
so the signing key + admin token + host paths can live next to
`plugin.json` instead of being exported in every shell. Real
`process.env` values always win over `.env`, which keeps CI secrets
dominant.

Each side (`backend/`, `frontend/`, `mobile/`) is optional. Backend-only
or frontend-only plugins are normal; the manifest declares which slots
the plugin populates.

### 1.2 Plugin `.gitignore`

Drop the following `.gitignore` at the root of every plugin repo. It
keeps build artefacts, secrets, and local-dev junk out of the repo —
plus the new `.shplugin` archives and signing keys.

```gitignore
# Editor / OS
.DS_Store
Thumbs.db
.idea/
.vscode/*
!.vscode/extensions.json

# Node
node_modules/
npm-debug.log*
yarn-debug.log*
yarn-error.log*
.pnpm-debug.log*

# Composer
backend/vendor/
backend/composer.lock          # keep committed if you publish a `composer.lock` for repro builds; ignored by default for libraries

# Build artefacts
frontend/dist/
mobile/dist/
backend/var/

# .shplugin build output
dist/
*.shplugin                      # the archive itself is uploaded to GitHub Releases, never committed

# Local signing keys (NEVER commit private keys)
.signing-keys/
*.ed25519
*.priv
*.pem

# Local env files
.env
.env.local
.env.*.local

# Test / coverage
coverage/
.nyc_output/
.phpunit.result.cache
.phpunit.cache/

# Doctrine migration cache
backend/migrations/.migration-state
```

Required protected keys (read [`signing.md`](./signing.md)):

- `SELFHELP_PLUGIN_SIGNING_KEY` — production Ed25519 secret key
  (base64, 64 bytes). Stored as a GitHub Actions repository secret on
  the plugin repo, never on disk.
- `SELFHELP_PLUGIN_SIGNING_KEY_ID` — publisher key id matching one of
  the host's `SELFHELP_PLUGIN_TRUSTED_KEYS`.
- `SELFHELP_PLUGIN_DEV_SIGNING_KEY` — local-dev fallback. The host
  refuses dev-signed plugins on `official` / `reviewed` trust levels
  outside `APP_ENV=dev`.

Locally, drop these (and `SELFHELP_ADMIN_TOKEN` for `install-local.mjs`)
into a gitignored `<plugin>/.env` so every `node scripts/*.mjs`
invocation picks them up automatically. Each plugin ships a
`.env.example` template documenting the full set. Real `process.env`
values still win — CI secrets injected into a workflow override the
file automatically.

### 1.3 First `plugin.json`

Validate against the canonical schema before committing:

```bash
npx -y ajv-cli@5 validate \
  -s ../../sh-selfhelp_backend/docs/plugins/plugin-manifest.schema.json \
  -d plugin.json --strict=false
```

See [`developer-guide.md` §2](./developer-guide.md) for the field-by-field
contract and the SurveyJS plugin's `plugin.json` for a real-world
reference.

---

## 2. Local development loop

Two supported modes:

### 2.1 Symlink fast path (recommended for iteration)

```bash
# From the plugin repo (works on every OS)
node scripts/install-local.mjs --symlink --backend ../../sh-selfhelp_backend
```

What the script does:

1. Adds a `path` Composer repository to the backend's `composer.json`
   pointing at this plugin's `backend/` dir.
2. Runs `composer require humdek/<plugin-id>:@dev` in the backend.
3. Wires a Vite dev server URL (`http://localhost:5174/<id>/plugin.esm.js`)
   into the plugin's manifest via `frontend.runtime.devEntrypointUrl`.
4. Calls `php bin/console selfhelp:plugin:install <abs-path>/plugin.json`
   on the backend, which dispatches `InstallPluginMessage` on the
   `plugin_ops` Messenger transport.

In `development` install mode the Messenger worker runs in-process; in
`managed` mode it stops at the runbook entry and the operator finishes
with `selfhelp:plugin:run-operation`.

### 2.2 `.shplugin` round-trip

Useful for verifying the same artefact the registry will publish.

```bash
# In the plugin repo
node scripts/build-shplugin.mjs
# → dist/<plugin-id>-<version>.shplugin

# Upload via the admin UI:
# Admin → Plugins → Install plugin → Upload .shplugin
#
# Or via curl:
curl --fail-with-body \
  -H "Authorization: Bearer $SELFHELP_ADMIN_TOKEN" \
  -F "source=archive" \
  -F "archive=@dist/<plugin-id>-<version>.shplugin" \
  "$SELFHELP_HOST/cms-api/v1/admin/plugins/install"
```

The host runs the same pipeline as a registry install: extractor →
validator → signature verifier → Messenger worker → `composer require`
→ archive promote → migrations → finalize. See
[`shplugin-archive.md`](./shplugin-archive.md) for every validation gate.

---

## 3. Building the `.shplugin`

`scripts/build-shplugin.mjs` is canonical and ships with every plugin
repo. The script:

1. **Builds the frontend bundle**: `npm --prefix frontend run build`.
   Produces `frontend/dist/plugin.esm.js` (+ optional `plugin.css`).
2. **Stages files** under `dist/shplugin/<id>-<version>/`:
   - `plugin.json` (root)
   - `artifacts/plugin.esm.js` (always)
   - `artifacts/plugin.css` (only if the build emitted one — plugins
     whose CSS is inlined into the JS bundle, or admin-only / headless
     plugins, ship without a stylesheet and that is fully supported)
3. **Computes SHA-256** for every file under `artifacts/` and writes
   sorted `artifacts/SHA256SUMS`.
4. **Builds the canonical signed payload** via
   `node scripts/sign.mjs build-payload`. This produces the exact JSON
   that `SignedPayloadBuilder.php` rebuilds host-side: sorted keys,
   `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, no whitespace.
5. **Signs the payload** with Ed25519 (`sign.mjs sign`), using
   `SELFHELP_PLUGIN_SIGNING_KEY` from env (or `--key <path>`).
6. **Writes `signature.json`** = `{keyId, signature, signedPayload}`.
7. **Zips** the staged tree into `dist/<id>-<version>.shplugin`.
8. **Self-validates** by re-reading SHA256SUMS and re-hashing files.

Output:

```
dist/
└── sh2-shp-survey-js-0.1.0.shplugin   # ← upload this to GitHub Releases
```

The script never writes anything outside `dist/` or the in-place
`frontend/dist/` build folder. Both directories are gitignored.

---

## 4. Versioning + Changelog

| Diff                | Carry a migration? | Host UI behaviour                            |
|---------------------|---------------------|----------------------------------------------|
| patch `1.0.0→1.0.1` | No (rejected)       | One-click update.                            |
| minor `1.0→1.1`     | Yes (required)      | One-click update; worker runs migrations.    |
| major `1.x→2.0`     | Allowed             | UI requires *Force update* confirmation.     |

The `validate-plugin.yml` workflow rejects:

- a patch bump that ships a migration,
- a minor bump that does NOT ship a migration,
- a major bump without an updated `pluginApiVersion` /
  `compatibility.selfhelp.*`.

Always update `CHANGELOG.md` together with `plugin.json#version`. Use
the Keep-a-Changelog format.

---

## 5. GitHub release flow

The `publish-to-registry.yml` workflow runs on every push of a `v*`
tag.

```bash
# Bump version + commit
npm version --no-git-tag-version 0.2.0      # or edit plugin.json by hand
git add plugin.json CHANGELOG.md
git commit -m "chore: release v0.2.0"

# Tag + push
git tag v0.2.0
git push origin main --tags
```

End-to-end after `git push --tags`:

```
Plugin repo:
  git tag v0.2.0
  git push --tags
            │
            ▼  .github/workflows/publish-to-registry.yml
  - validate manifest against plugin-manifest.schema.json
  - npm install + npm run build (frontend + mobile)
  - scripts/build-shplugin.mjs --mode connected
      → dist/<id>-<ver>.shplugin (later renamed to -connected.shplugin)
  - sign canonical payload (Ed25519, SELFHELP_PLUGIN_SIGNING_KEY)
  - node scripts/publish-to-registry.mjs --mode connected --skip-build --push
  - scripts/build-shplugin.mjs --mode standalone
      → dist/<id>-<ver>-standalone.shplugin
  - create GitHub Release for v0.2.0, attach BOTH archives
            │
            ▼  sh2-plugin-registry repo (humdek-unibe-ch/sh2-plugin-registry)
  - manifests/<id>-<ver>.json              ← copy of plugin.json
  - artifacts/<id>-<ver>/plugin.esm.js     ← entry bundle (GH Pages)
  - artifacts/<id>-<ver>/plugin.css        ← stylesheet (GH Pages)
  - artifacts/<id>-<ver>/<chunk-hash>.js   ← EVERY Vite code-split chunk
  - artifacts/<id>-<ver>/SHA256SUMS        ← bare-filename sha256 manifest
  - registry.json                          ← new entry inserted (sorted by id)
  - commit + push (REGISTRY_PUSH_TOKEN)
            │
            ▼  .github/workflows/build-registry.yml (in registry repo)
  - validate registry.json against plugin-registry.schema.json
  - validate every manifest against plugin-manifest.schema.json
  - publish to GitHub Pages (https://humdek-unibe-ch.github.io/sh2-plugin-registry/)
            │
            ▼  Every SelfHelp host with `humdek-public` source enabled
  - Admin → Plugins → Available tab refreshes
  - new row appears; admin clicks Install → host runs the unified pipeline
```

### Connected vs standalone .shplugin

Every release ships two archives, one per install profile:

- **`<id>-<ver>-connected.shplugin`** — minimal layout
  (`plugin.json`, `artifacts/`, `signature.json`). The host installs
  the backend Composer package from `backend.composer.repository` (the
  registry's declared Packagist / VCS source) and the frontend bundle
  is downloaded by `PluginRuntimeArtifactFetcher` from
  `runtime.entrypointUrl`. Used by the registry publish step because
  the `humdek-public` registry resolves the Composer package over the
  network and never serves the archive itself.

- **`<id>-<ver>-standalone.shplugin`** — connected layout PLUS a
  `backend/package/` directory containing the plugin's Composer
  package source. The host installs the backend via a synthetic
  Composer path repository pointing at the promoted
  `var/plugins/<id>-<ver>/installed/backend/package/`, so no
  Packagist / git fetch is required for the plugin's own code. This
  is the asset humans download from the GitHub Release for
  drag-and-drop installs (**Admin → Plugins → Upload .shplugin**) and
  for fully offline / air-gapped installs.

Both archives are signed with the same Ed25519 publisher key and
contain the same canonical signed payload bytes for the runtime
fields; only the `archive` block differs (`mode: connected` vs
`mode: standalone` plus `backend.packageHash`).

Required GitHub Actions secrets on the plugin repo:

| Secret                          | Purpose                                                                                    |
|--------------------------------|--------------------------------------------------------------------------------------------|
| `SELFHELP_PLUGIN_SIGNING_KEY`   | Ed25519 base64 secret. Used by `sign.mjs sign`. Never committed.                           |
| `SELFHELP_PLUGIN_SIGNING_KEY_ID`| Matches a `keyId=…` in the host's `SELFHELP_PLUGIN_TRUSTED_KEYS`.                          |
| `REGISTRY_PUSH_TOKEN`           | PAT with `contents:write` on `humdek-unibe-ch/sh2-plugin-registry`. Missing → dry-run mode.|

Per-plugin step-by-step (Ed25519 generation, GitHub UI navigation,
host `SELFHELP_PLUGIN_TRUSTED_KEYS` wiring) is documented in each
plugin's `docs/secrets-setup.md`. See
[`sh2-shp-survey-js/docs/secrets-setup.md`](https://github.com/humdek-unibe-ch/sh2-shp-survey-js/blob/main/docs/secrets-setup.md)
for the reference walkthrough — every other plugin should copy it
verbatim and tweak the keyId.

> **Local-only development?** Set
> `SELFHELP_PLUGIN_DEV_SIGNING_KEY=<base64-64-bytes>` instead of the
> production secret. `scripts/sign.mjs` falls back to it and stamps
> `keyId="dev"`. The host accepts `keyId="dev"` only when
> `APP_ENV=dev` (and refuses it on `official`/`reviewed` trust
> levels regardless). No GitHub secrets are required for local
> iteration.

The registry repo's own `build-registry.yml` workflow publishes the
static `registry.json` to GitHub Pages. The plugin author's workflow
finishes once the registry repo accepts the push.

---

## 6. Registry repo storage layout

After a successful publish-to-registry run, the registry repo looks
like:

```
sh2-plugin-registry/
├── registry.json                          # canonical entry list (sorted by id, latest version per id)
├── plugin-registry.schema.json            # mirrored from sh-selfhelp_backend/docs/plugins/
├── plugin-manifest.schema.json            # mirrored from sh-selfhelp_backend/docs/plugins/
├── manifests/
│   ├── sh2-shp-survey-js-0.1.0.json       # historical manifests preserved per version
│   └── sh2-shp-survey-js-0.2.0.json
├── artifacts/
│   ├── sh2-shp-survey-js-0.1.0/
│   │   ├── plugin.esm.js                  # served by GH Pages
│   │   ├── plugin.css
│   │   ├── <chunk-hash>.js                # every Vite code-split chunk
│   │   └── SHA256SUMS                     # bare-filename sha256 manifest
│   └── sh2-shp-survey-js-0.2.0/
│       └── ...
└── scripts/
    ├── sign.mjs                           # canonical payload + Ed25519 signer (shared with plugins)
    └── build-registry-entry.mjs           # assembles a signed pluginEntry
```

- Every manifest snapshot is preserved per version; downgrade installs
  are possible from any historical entry.
- `registry.json` only advertises the **latest** version per
  `(id, channel)` tuple. Older versions stay reachable through
  `manifests/<id>-<ver>.json`.
- Every entry in `registry.json` has `composer`, `runtime`,
  `checksums`, `signedPayload`, `signature`, `keyId`. The
  `build-registry.yml` workflow rejects entries without them.

---

## 7. What happens on the host when admin clicks *Install*

For every install source (registry, URL, `.shplugin` archive, paste
JSON) the unified backend pipeline creates the same set of files,
folders, and database rows. Operators should know this for backup +
gitignore + recovery scenarios.

### 7.1 Files / folders touched on the backend host

```
sh-selfhelp_backend/
├── vendor/
│   └── humdek/sh-shp-survey-js/             # composer require humdek/<id>:<version>
│
├── var/
│   └── plugins/
│       └── sh2-shp-survey-js-0.1.0/
│           ├── staging/<random>/            # .shplugin uploads land here first; deleted on success
│           └── installed/                   # promoted from staging on finalize
│               ├── plugin.json
│               ├── signature.json
│               └── artifacts/...
│
├── public/
│   └── plugin-artifacts/
│       └── sh2-shp-survey-js-0.1.0/
│           ├── plugin.esm.js                # web-served, integrity-hashed
│           └── plugin.css                   # web-served (optional)
│
├── config/
│   └── selfhelp_plugin_bundles.php          # GENERATED — registers the bundle class
│
├── selfhelp.plugins.lock.json                # GENERATED — atomic state log (signing + migrations + capabilities)
│
└── composer.json                             # OPTIONALLY MUTATED — adds repository entry for custom repos
```

Notes:

- `vendor/humdek/sh-shp-<id>/` is the Composer install target. The
  worker runs `composer require humdek/<id>:<version>` against either
  Packagist or a manifest-declared custom repository.
- `var/plugins/<id>-<ver>/staging/<random>/` is created by
  `PluginArchiveExtractor` for `.shplugin` uploads. The staging dir is
  promoted to `installed/` on success and deleted on failure (with a
  cleanup TTL of `SELFHELP_PLUGIN_ARCHIVE_RETENTION_DAYS`, default 7).
- `public/plugin-artifacts/<id>-<ver>/` is the web-served root. The
  frontend's `PluginRuntime` loads `plugin.esm.js` from this path at
  request time — no Next.js rebuild needed.
- `config/selfhelp_plugin_bundles.php` is regenerated atomically by
  `PluginInstaller::finalize()`. It is included once from
  `config/bundles.php`.
- `selfhelp.plugins.lock.json` records every installed plugin's
  version, signing `keyId`, signature, runtime URLs, capabilities, and
  the SHA-256 of every migration file. It is the source of truth for
  the doctor.

### 7.2 Files / folders NOT touched

- The host frontend (`sh-selfhelp_frontend/`) is never rebuilt or
  modified. Runtime ESM bundles are loaded from
  `/plugin-artifacts/<id>-<ver>/plugin.esm.js` at request time.
- The mobile app (`sh-selfhelp_mobile/`) is not touched at runtime;
  mobile bundles are picked up at EAS build time via
  `npm run plugins:sync`.
- Core CMS tables (`users`, `roles`, `permissions`, `groups`, `pages`,
  `sections`, …) are off-limits to plugin migrations.
  `PluginMigrationGuard` blocks any `ALTER` / `DROP` / `INSERT` against
  protected tables.

### 7.3 Database rows touched

| Table                     | Rows added / changed                                                                                                          |
|---------------------------|-------------------------------------------------------------------------------------------------------------------------------|
| `plugins`                 | One row per installed plugin (`plugin_id`, `version`, `frontend_runtime_url`, `signing_key_id`, `signature_ed25519`, …).      |
| `plugin_operations`       | One row per install / update / uninstall / purge / rollback / repair. Captures `snapshots_json`, `logs_json`, `status` enum.  |
| `plugin_feature_flags`    | One row per declared feature flag (`flag_key`, `scope`, `scope_value`, `enabled`).                                            |
| `api_routes`              | Plugin-declared API routes inserted under `/cms-api/v1/plugins/{pluginId}/...`. Deleted on uninstall.                         |
| `rel_api_routes_permissions` | Permission links for plugin routes. Deleted on uninstall.                                                                  |
| `lookups`                 | Plugin-extendable or plugin-owned lookup rows tagged with `id_plugins`. Deleted on purge.                                     |
| `styles` / `styles_fields`| Plugin-contributed CMS styles tagged with `id_plugins`. Deleted on uninstall (styles) or purge (full).                        |
| Plugin-owned tables       | Created by the plugin's own migrations. `survey_runs`, `survey_responses`, `survey_response_meta` for SurveyJS. Deleted on purge only. |

### 7.4 Mercure topics published

The worker emits progress on `selfhelp/plugins/state` for every step
(`requested → running → succeeded|failed`). Authenticated admin
sessions subscribed to that topic see live progress in the operations
panel.

### 7.5 Lifecycle cheatsheet

| Action      | Composer    | Migrations | DB rows                                             | Web artefacts                  |
|-------------|-------------|------------|-----------------------------------------------------|--------------------------------|
| Install     | `require`   | up         | `plugins` + `plugin_operations` + lookups + styles | `public/plugin-artifacts/...` copied |
| Update      | `require <new>` | up   | `plugins.version` += , `plugin_operations` audit  | atomic replace of artefacts    |
| Disable     | —           | —          | `plugins.enabled = 0`, bundles file regenerated     | artefacts kept                 |
| Enable      | —           | —          | `plugins.enabled = 1`, bundles file regenerated     | artefacts kept                 |
| Uninstall   | `remove`    | —          | `plugins` row deleted, lock entry removed           | artefacts kept                 |
| Purge       | —           | —          | plugin-owned tables dropped + `id_plugins` rows wiped + lookups wiped | `public/plugin-artifacts/<id>-<ver>/` removed |
| Rollback    | `require <prev>` | down  | snapshot restored from `plugin_operations.snapshots_json` | previous artefacts restored |

### 7.6 What the operator should back up

- Database (the operator already backs this up nightly).
- `selfhelp.plugins.lock.json` — lets `selfhelp:plugin:repair` rebuild
  state without the DB. Cheap to back up; do it.
- `var/plugins/<id>-<ver>/installed/` — only matters for air-gapped
  hosts that may need to re-promote without internet access.
- `.env` — for `SELFHELP_PLUGIN_TRUSTED_KEYS`.

The operator should **gitignore** on the host:

- `vendor/` (already ignored by every Symfony repo).
- `var/` (already ignored).
- `public/plugin-artifacts/` (added by the plugin refactor — see
  [`.gitignore`](../../.gitignore)).
- `selfhelp.plugins.lock.json*` (added by the plugin refactor — the
  authoritative lock file is regenerated from the DB by
  `selfhelp:plugin:repair`).
- `config/selfhelp_plugin_bundles.php` (already ignored — generated).

---

## 8. Operator-side runbook (summary)

```bash
# 1. Make sure the plugin operations worker is running.
php bin/console messenger:consume plugin_ops --time-limit=3600

# 2. Trigger the install. Either:
# a) Admin UI: Plugins → Available → Install
# b) CLI:
php bin/console selfhelp:plugin:install /abs/path/to/plugin.json

# 3. (Managed mode only) The worker writes a runbook into plugin_operations.logs_json.
# Operator runs the composer step + deploys + finalises:
composer require humdek/sh2-shp-survey-js:0.1.0 --no-interaction --no-scripts
git commit -am "chore: pin sh2-shp-survey-js 0.1.0"
git push  # CD picks it up

php bin/console selfhelp:plugin:run-operation <operationId>

# 4. Confirm:
php bin/console selfhelp:plugin:status
php bin/console selfhelp:plugin:doctor
```

If anything goes wrong, see [`installation.md` §11 — Lock file
recovery](./installation.md#11-lock-file-recovery).

---

## 9. Testing notes — gates that must be green before you publish

A version is publishable only when both CI tiers below pass. The full grid +
"where it runs" lives in [`testing-matrix.md`](./testing-matrix.md);
[`ci-workflows.md`](./ci-workflows.md) is the GitHub Actions reference.

**Plugin-repo CI (required for publish):**

- Manifest **schema validation** + **compatibility** against every supported host
  version (`plugin-manifest.schema.json` / `PluginCompatibilityValidator`).
- Backend `composer phpstan` (max) + `composer headers:check` + PHPUnit
  (unit/integration) + **schema-parity** (`check:schemas`).
- Frontend `tsc --noEmit` + Vitest + Vite build; mobile `tsc` when the plugin
  declares `mobile`.
- **Archive validation** before upload: `selfhelp:plugin:validate-archive <file> [--json]`
  runs the exact host `inspect-archive` pipeline and exits non-zero on any error.
- **Signing**: the released `.shplugin` carries an Ed25519 signature + SHA-256
  checksum the host's `PluginSignatureVerifier` re-checks on install (see
  [`signing.md`](./signing.md)).

**Shared-package CI (`@selfhelp/shared`, binding on `main`):**

- `plugin-sdk-check.yml` guards the SDK contract every plugin compiles against
  (type-check, build, `usePluginRealtime` export, schema parity, Vitest). A
  regression here ripples into every plugin repo.
- Release gate: `npm run test:release` (headers → typecheck → schema parity →
  blocking coverage → build).

**Host-repo certification (run on the host, per host version):**

- Each plugin ships a subclass of
  `tests/Certification/InstallLifecycleCertificationTestCase.php` so its REAL
  manifest is certified against the host (`--testsuite Certification`). SurveyJS
  is the reference (`tests/Certification/Plugin/SurveyJsPluginCertificationTest.php`).

What is **not** gated in CI and stays a deploy-time step: the
`selfhelp:plugin:run-operation` finalize/update/rollback/purge run-through against
a live stack (composer/npm + migrations + non-transactional disk writes). See
[`testing-matrix.md`](./testing-matrix.md) for the documented exception.

---

## 10. Cross-references

| Topic                            | Doc                                                                                                       |
|----------------------------------|-----------------------------------------------------------------------------------------------------------|
| Plugin anatomy                   | [`developer-guide.md`](./developer-guide.md)                                                              |
| Manifest schema (canonical v1.0) | [`plugin-manifest.schema.json`](./plugin-manifest.schema.json)                                            |
| Install modes (dev/managed/trusted) | [`install-modes.md`](./install-modes.md)                                                               |
| `.shplugin` format               | [`shplugin-archive.md`](./shplugin-archive.md)                                                            |
| Registry repo                    | [`registry-and-channels.md`](./registry-and-channels.md), [`distribution.md`](./distribution.md)          |
| Signing                          | [`signing.md`](./signing.md), [`trusted-keys.md`](./trusted-keys.md)                                      |
| CI workflows                     | [`ci-workflows.md`](./ci-workflows.md)                                                                    |
| Frontend ESM loader              | [`runtime-frontend-loading.md`](./runtime-frontend-loading.md)                                            |
| Lock file                        | [`lock-file.md`](./lock-file.md)                                                                          |
| Operation history + rollback     | [`plugin-operations-and-rollback.md`](./plugin-operations-and-rollback.md)                                |
| Recovery procedures              | [`installation.md` §10–§11](./installation.md#10-safe-mode)                                              |
