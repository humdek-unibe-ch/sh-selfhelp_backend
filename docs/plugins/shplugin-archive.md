<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# `.shplugin` archive format

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Plugin layer code and the schemas under this folder.

`.shplugin` is the canonical, single-file distribution format for
SelfHelp plugins. It is the **main manual install path** in the admin
UI and the asset that every plugin's `publish-to-registry` workflow
attaches to its GitHub Release.

Goals:

- One file the admin can drop into the UI as the canonical manual
  install path.
- Cryptographically verifiable (Ed25519 + SHA-256).
- Pre-validated by `POST /admin/plugins/inspect-archive` so the admin
  sees compatibility, capabilities, and signature status before
  clicking Install.
- Same downstream pipeline as every other install source: extractor →
  validator → `ManifestResolver` → Messenger worker → Composer →
  migrations → finalise. No parallel installer.

> A `.shplugin` is **not** a fully self-contained install bundle.
> Composer still resolves the plugin's third-party PHP dependencies
> (`symfony/*`, `doctrine/*`, …) from Packagist or the host's
> configured Composer mirror at install time. The archive contains
> the plugin's own backend bundle (in `standalone` mode) plus its
> compiled frontend artifacts — nothing else. Hosts without outbound
> Composer access need a private Composer mirror; that is out of
> scope for this document.

## Archive modes

A `.shplugin` declares its install mode via the top-level
`archive.mode` field in `plugin.json`:

| `archive.mode` | Backend resolution                                       | Network during install                  | Use case                                                |
| -------------- | -------------------------------------------------------- | --------------------------------------- | ------------------------------------------------------- |
| `connected` (default) | Host runs `composer require` against Packagist / VCS, using `backend.composer.{package,version,repository?}`. The plugin itself + every transitive PHP dependency is resolved from Packagist / mirror. | Required (Packagist + VCS).             | Public plugins, registry installs, fastest publish loop. |
| `standalone`   | Host registers a Composer path repository pointing at the staged `backend/package/` and runs `composer require` from there. The plugin's own bundle is taken from the archive; transitive PHP dependencies are still resolved by Composer at install time. | Required. Composer resolves third-party PHP dependencies (`symfony/*`, `doctrine/*`, …) from Packagist or the configured private mirror. Only the plugin's own backend bundle is bundled. | Internal / private plugins where the plugin source is not published to Packagist (private orgs, deterministic publishing snapshots, plugins that intentionally keep their bundle outside the public Composer ecosystem). |

`archive.mode` is signed. The host rejects archives whose mode does
not match the recomputed canonical payload.

## Layout

```
<plugin-id>-<version>.shplugin             (ZIP archive)
├── plugin.json                            (canonical manifest, schema v1.0)
├── signature.json                         {keyId, signature, signedPayload}
├── artifacts/
│   ├── plugin.esm.js                      runtime ESM entrypoint
│   ├── plugin.css                         (optional) stylesheet
│   └── SHA256SUMS                         "<sha256-hex>  <archive-root path>" per line, sorted
├── backend/                               (only when archive.mode=standalone)
│   └── package/
│       ├── composer.json                  name + version MUST match plugin.json
│       ├── src/                           Symfony bundle PHP source
│       ├── config/                        (optional) bundle config
│       ├── migrations/                    (optional) Doctrine migrations
│       └── Resources/                     (optional) bundle resources
├── README.md                              (optional)
└── LICENSE                                (optional)
```

- The ZIP MUST use **forward-slash** entry names (canonical ZIP
  convention). `scripts/build-shplugin.mjs` writes them directly with
  a built-in pure-Node ZIP writer; PowerShell's `Compress-Archive` is
  NOT used because it serialises backslashes on Windows which the host
  validator rejects (`SHA256SUMS entry must be archive-root-relative
  and live under artifacts/ or backend/package/`).
- `artifacts/plugin.css` is **optional**. Plugins whose Vite build
  inlines CSS into the JS bundle, and admin-only / headless plugins,
  ship without one. The canonical signed payload omits
  `stylesheetUrl` + `frontendCss` accordingly, and the host's
  `PluginArchiveValidator` mirrors that based on staging-dir presence.
- `SHA256SUMS` lists every file under `artifacts/` AND under
  `backend/package/` (when present), one hash per line, sorted by
  archive-root-relative path. **Each entry path MUST be
  archive-root-relative and start with `artifacts/` or
  `backend/package/`** — the host validator rejects any other prefix
  (`SHA256SUMS entry "<rel>" must be archive-root-relative and live
  under artifacts/ or backend/package/`). `scripts/build-shplugin.mjs`
  writes them in this form. The host recomputes every hash before
  accepting the archive AND runs a two-way diff against the on-disk
  files under `backend/package/` so files appended after signing are
  detected.
- `backend/package/` is **only present when `archive.mode=standalone`**.
  The directory contains the plugin's Symfony bundle Composer package
  (the same one normally published to Packagist). `backend/vendor/`
  is **never** included — third-party Composer deps are still
  resolved at install time. The package's `composer.json` MUST
  declare a `version` field equal to `plugin.json#version`, and the
  `name` field MUST equal `plugin.json#backend.composer.package`.
  Mismatches are hard-rejected by both `scripts/build-shplugin.mjs`
  and `PluginArchiveValidator`.
- `signature.json#signedPayload` is the **canonical JSON document**
  emitted by `SignedPayloadBuilder` (PHP) / `sign.mjs build-payload`
  (Node). Both implementations are byte-identical (the fixture tests
  enforce it). The signature is detached Ed25519 over that payload.
  For standalone archives the payload additionally pins
  `archive.backend.packageHash` — a sha256 over the sorted
  `<hex>  <archive-root path>` lines for every backend file. Tampering
  with `backend/package/` post-signing changes the recomputed hash and
  rejects the archive.

## Producing a `.shplugin`

Use the cross-platform Node script shipped with every plugin. The
same command works on PowerShell, Git Bash / WSL, macOS, and Linux —
there is no `.sh` / `.ps1` wrapper:

```bash
# Connected archive (default):
node scripts/build-shplugin.mjs
# → dist/<id>-<version>.shplugin (frontend only; backend resolved by Composer at install time)

# Standalone archive (bundled backend):
node scripts/build-shplugin.mjs --mode standalone
# → dist/<id>-<version>.shplugin (frontend + backend/package/ inlined)
```

The `--mode` flag wins over `plugin.json#archive.mode` when both are
present. Default is `connected`.

The script:

1. Auto-installs `frontend/node_modules` if `vite` is missing.
2. Builds the frontend runtime bundle (`vite build` in library mode).
3. For `--mode standalone`: validates the publisher contract
   (`backend/composer.json#name` must equal
   `plugin.json#backend.composer.package`; `composer.json#version`
   must equal `plugin.json#version`) and stages `backend/package/`
   from an explicit include-list (`composer.json`, `src/`, `config/`,
   `migrations/`, `Resources/`, and optional `LICENSE`/`README.md`/
   `CHANGELOG.md`). `vendor/`, `var/`, `tests/`, dot-files, and
   `composer.lock` are explicitly excluded.
4. SHA-256s every staged file (artifacts/ plus backend/package/) and
   writes `SHA256SUMS` (sorted, archive-root-relative paths).
5. Builds the canonical signed payload via `sign.mjs build-payload`
   (sibling registry checkout required at `../sh2-plugin-registry`).
   For standalone archives the payload includes the `archive` block
   with a derived `backend.packageHash`.
6. Signs the payload with `sign.mjs sign` (Ed25519, key from env or
   `--key`).
7. Writes `signature.json`.
8. Writes the staged copy of `plugin.json` with the `archive` block
   populated to match the chosen mode (the repo-root `plugin.json` is
   never modified).
9. Writes the deterministic ZIP via a built-in pure-Node ZIP writer
   (forward-slash entry names, fixed mtime).
10. Self-validates by re-reading the SHA256SUMS and re-hashing.

Required env (one of):

| Env                                  | Use                                                        |
| ------------------------------------ | ---------------------------------------------------------- |
| `SELFHELP_SIGNING_KEY`        | Production Ed25519 64-byte secret key (base64).            |
| `SELFHELP_SIGNING_KEY_ID`     | Publisher key id matching `SELFHELP_PLUGIN_TRUSTED_KEYS`.  |
| `SELFHELP_PLUGIN_DEV_SIGNING_KEY`    | Local-dev fallback. KeyId defaults to `dev`. CI rejects it on the `official` channel. |

The script auto-loads `<plugin>/.env` via Node 22's
`process.loadEnvFile`, so these can live in a single gitignored file
next to `plugin.json` instead of being exported in every shell. Real
`process.env` values still win over `.env`, which keeps CI secrets
dominant.

## Installing a `.shplugin`

### From the admin UI (preferred)

1. **Admin → Plugins → Install plugin → Upload .shplugin** tab.
2. Drag-and-drop or click-to-browse the `.shplugin` file.
3. The frontend POSTs the file to `POST /admin/plugins/inspect-archive`.
   The host extracts to a scratch dir and runs every validator, then
   returns `{manifest, compatibility, capabilities, signature,
   archive, errors[]}` where `signature.status ∈ {verified, unsigned,
   untrusted-key, invalid}`. The UI shows a preview card with the
   plugin name, version, trust level, capability list, and signature
   status.
4. Click **Install** to POST the same file to
   `POST /admin/plugins/install` with `source=archive`. The host
   queues an `InstallPluginMessage` on the `plugin_ops` Messenger
   transport, then finalises in the worker.

### From the CLI (managed mode)

```bash
curl --fail-with-body \
  -H "Authorization: Bearer $SELFHELP_ADMIN_TOKEN" \
  -F "source=archive" \
  -F "archive=@<id>-<version>.shplugin" \
  "$HOST/cms-api/v1/admin/plugins/install"

php bin/console messenger:consume plugin_ops --limit=1 --time-limit=120
```

`messenger:consume` drains the queue and runs the install/update/uninstall
handler inline; in production a dedicated worker stays running.

## Backend pipeline

1. `PluginArchiveExtractor`
   - Rejects files that are not `.shplugin` by extension.
   - Rejects ZIPs above `SELFHELP_PLUGIN_ARCHIVE_MAX_BYTES` (default
     20 MB).
   - Magic-byte check (`PK\x03\x04`) before extraction.
   - Extracts to `var/plugins/<id>-<version>/staging/<random>/` with
     zip-slip protection. Every entry path is normalised AND must
     start with one of the recognised top-level prefixes (`artifacts/`,
     `backend/`) or be one of the allow-listed top-level files
     (`plugin.json`, `signature.json`, `README.md`, `LICENSE`,
     `CHANGELOG.md`). Anything else is rejected.
   - Asserts the presence of every required file. For
     `archive.mode=standalone` archives this additionally requires
     `backend/package/composer.json` so downstream validation can
     read the staged Composer package.

2. `PluginArchiveValidator`
   - Recomputes `SHA256SUMS` and asserts every line matches the
     staged file (covers both `artifacts/` and `backend/package/`).
   - For `archive.mode=standalone` runs a two-way diff: every file
     under `backend/package/` on disk MUST also appear in
     `SHA256SUMS`. Files appended after signing fail the diff.
   - Loads `plugin.json` and validates against
     `plugin-manifest.schema.json`.
   - For `archive.mode=standalone`: asserts
     `backend/package/composer.json#{name,version}` equal
     `plugin.json#backend.composer.package` and `plugin.json#version`,
     and rejects a `composer.json#scripts` block unless the operator
     sets `SELFHELP_PLUGIN_ALLOW_COMPOSER_SCRIPTS=1` in `.env.local`
     (or `.env.dev` for local dev). The flag is wired through DI in
     `config/services.yaml`; `composer require` itself always runs with
     `--no-scripts`, so the flag only relaxes the manifest-time
     rejection.
   - Recomputes the canonical signed payload from the manifest +
     artifact checksums (and the derived `archive.backend.packageHash`
     for standalone) and asserts byte-for-byte equality with
     `signature.json#signedPayload`.
   - Verifies the Ed25519 signature via `PluginSignatureVerifier`
     (rejects on unknown `keyId` and on bad signature).

3. `PluginArchivePromoter`
   - On finalise: moves `staging/<random>/` to
     `var/plugins/<id>-<version>/installed/`.
   - Copies `artifacts/*` to
     `public/plugin-artifacts/<id>-<version>/` so the frontend can
     load the runtime ESM + CSS via plain HTTP.
   - Rewrites the manifest's `frontend.runtime.entrypointUrl` /
     `stylesheetUrl` to point at those public paths so the host's
     `Plugin` row records the served URLs (not the original staging
     paths).
   - For `archive.mode=standalone`: the promoter runs BEFORE
     `composer require` (driven by `InstallPluginHandler`) so the
     Composer path repository can point at the durable
     `installed/backend/package/` location, not the transient staging
     dir.

4. `InstallPluginHandler` (Messenger worker)
   - For `archive.mode=connected` (and all non-archive sources):
     `composer require` against the manifest's
     `backend.composer.repository` (Packagist when absent), then
     promote.
   - For `archive.mode=standalone`: promote first, then `composer
     require` with a synthetic `type: "path"` repository pointing at
     `installed/backend/package/` with `options.symlink=false` so
     vendor/ holds a real copy rather than a fragile symlink.

5. `PluginArchiveCleaner`
   - Periodically purges `var/plugins/*/staging/<random>/` dirs
     older than 7 days (an aborted install, a failed validation, a
     superseded inspect call). Operator-runnable command:
     `bin/console selfhelp:plugin:cleanup-archives`.

## Failure modes

| Failure                                  | Behaviour                                                                              |
| ---------------------------------------- | -------------------------------------------------------------------------------------- |
| Wrong file extension                     | 400 `archive must be a .shplugin file`. No staging dir created.                        |
| Above size limit                         | 413 `archive too large`. Configurable via `SELFHELP_PLUGIN_ARCHIVE_MAX_BYTES`.         |
| Zip-slip path attempt                    | 400 `archive entry escapes staging directory`. Staging dir purged immediately.         |
| Missing required file (`plugin.json`, `signature.json`, `artifacts/SHA256SUMS`, `artifacts/plugin.esm.js`) | 400 `Archive is missing required entries: <names>`. Staging dir purged. `artifacts/plugin.css` is intentionally NOT in this list — it is optional. |
| Checksum mismatch                        | 400 `SHA256 mismatch for <relative-path>`. Staging dir purged.                         |
| Signed payload mismatch                  | 400 `manifest does not match signed payload`. Staging dir purged.                      |
| Bad / missing signature                  | 400 `signature verification failed: <reason>`. Staging dir purged.                     |
| Unknown keyId                            | 400 `signature key not trusted (keyId=<id>)`. Staging dir purged.                      |
| Compat or capability rejection           | 409 `plugin <id> rejected: <reason>`. Staging dir kept for diagnostics, purged on TTL. |
| Composer / migration error during finalise | `plugin_operations.status='failed'`, archive promotion rolled back, staging kept.   |

## Standalone backend bundling

`archive.mode=standalone` (set in `plugin.json` or via the build
script's `--mode standalone` flag) inlines the plugin's own backend
Composer package under `backend/package/`; the host installs it via
a Composer path repository instead of `composer require` against
Packagist.

The standalone mode never bundles third-party Composer dependencies.
Composer remains the single owner of dependency resolution, security
advisories, and updates for every non-plugin PHP package. Hosts
without outbound Composer access need to provide their own private
Composer mirror; configuring such a mirror is outside the scope of
this document.

Out of scope for `.shplugin` archives:

- `backend/vendor/` is **not** included — third-party PHP
  dependencies (`symfony/*`, `doctrine/*`, …) are resolved by
  Composer at install time, the same way every other Symfony
  package on the host is. Hosts with no outbound HTTPS to Packagist
  must configure a private Composer mirror in the host's
  `composer.json#repositories`.
- Mobile bundles are still external (Expo / EAS update channels).
  The `mobile.package` declaration in `plugin.json` continues to
  point at the npm package.
