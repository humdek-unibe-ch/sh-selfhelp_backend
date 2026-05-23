<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# `.shplugin` archive format

`.shplugin` is the canonical, single-file distribution format for
SelfHelp plugins. It is the **main manual install path** in the admin
UI and the asset that every plugin's `publish-to-registry` workflow
attaches to its GitHub Release.

Goals:

- One file the admin can drop into the UI or copy to an air-gapped host.
- Cryptographically verifiable (Ed25519 + SHA-256).
- Pre-validated by `POST /admin/plugins/inspect-archive` so the admin
  sees compatibility, capabilities, and signature status before
  clicking Install.
- Same downstream pipeline as every other install source: extractor →
  validator → `ManifestResolver` → Messenger worker → Composer →
  migrations → finalise. No parallel installer.

## Layout

```
<plugin-id>-<version>.shplugin             (ZIP archive)
├── plugin.json                            (canonical manifest, schema v1.0)
├── signature.json                         {keyId, signature, signedPayload}
├── artifacts/
│   ├── plugin.esm.js                      runtime ESM entrypoint
│   ├── plugin.css                         (optional) stylesheet
│   └── SHA256SUMS                         "<sha256-hex>  <relative-path>" per line, sorted
├── README.md                              (optional)
└── backend/                               (Phase 2 only — vendored composer install)
```

- The ZIP must be a plain (uncompressed-method-agnostic) archive
  produced by `Compress-Archive` (Windows) or `zip -r -X` (POSIX).
- `SHA256SUMS` lists every file under `artifacts/` (except itself), one
  hash per line, sorted by path. The host recomputes every hash before
  accepting the archive.
- `signature.json#signedPayload` is the **canonical JSON document**
  emitted by `SignedPayloadBuilder` (PHP) / `sign.mjs build-payload`
  (Node). Both implementations are byte-identical (the fixture test
  enforces it). The signature is detached Ed25519 over that payload.

## Producing a `.shplugin`

Use the cross-platform Node script shipped with every plugin:

```bash
# In the plugin repo:
npm --prefix frontend run build:runtime
node scripts/build-shplugin.mjs
# → dist/<id>-<version>.shplugin
```

The script:

1. Builds the frontend runtime bundle (`vite build --mode lib`).
2. Stages every required file under `dist/shplugin/<id>-<ver>/`.
3. SHA-256s the artifacts and writes `SHA256SUMS` (sorted).
4. Builds the canonical signed payload via `sign.mjs build-payload`.
5. Signs the payload with `sign.mjs sign` (Ed25519, key from env or
   `--key`).
6. Writes `signature.json`.
7. Zips into `dist/<id>-<version>.shplugin`.
8. Self-validates by re-reading the SHA256SUMS and re-hashing.

Required env (one of):

| Env                                  | Use                                                        |
| ------------------------------------ | ---------------------------------------------------------- |
| `SELFHELP_PLUGIN_SIGNING_KEY`        | Production Ed25519 64-byte secret key (base64).            |
| `SELFHELP_PLUGIN_SIGNING_KEY_ID`     | Publisher key id matching `SELFHELP_PLUGIN_TRUSTED_KEYS`.  |
| `SELFHELP_PLUGIN_DEV_SIGNING_KEY`    | Local-dev fallback. KeyId defaults to `dev`. CI rejects it on the `official` channel. |

## Installing a `.shplugin`

### From the admin UI (preferred)

1. **Admin → Plugins → Install plugin → Upload .shplugin** tab.
2. Drag-and-drop or click-to-browse the `.shplugin` file.
3. The frontend POSTs the file to `POST /admin/plugins/inspect-archive`.
   The host extracts to a scratch dir and runs every validator, then
   returns `{manifest, compatibility, capabilities, signatureStatus,
   errors[]}`. The UI shows a preview card with the plugin name,
   version, trust level, capability list, and signature status.
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
     zip-slip protection (every entry path is normalised + must stay
     under the staging root).
   - Asserts the presence of every required file.

2. `PluginArchiveValidator`
   - Recomputes `SHA256SUMS` and asserts every line matches the
     staged file.
   - Loads `plugin.json` and validates against
     `plugin-manifest.schema.json`.
   - Recomputes the canonical signed payload from the manifest +
     artifact checksums and asserts byte-for-byte equality with
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

4. `PluginArchiveCleaner`
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
| Missing required file (`plugin.json`, …) | 400 `archive missing required file: <name>`. Staging dir purged.                       |
| Checksum mismatch                        | 400 `SHA256 mismatch for <relative-path>`. Staging dir purged.                         |
| Signed payload mismatch                  | 400 `manifest does not match signed payload`. Staging dir purged.                      |
| Bad / missing signature                  | 400 `signature verification failed: <reason>`. Staging dir purged.                     |
| Unknown keyId                            | 400 `signature key not trusted (keyId=<id>)`. Staging dir purged.                      |
| Compat or capability rejection           | 409 `plugin <id> rejected: <reason>`. Staging dir kept for diagnostics, purged on TTL. |
| Composer / migration error during finalise | `plugin_operations.status='failed'`, archive promotion rolled back, staging kept.   |

## Phase 2 — offline backend bundling

The `backend/` slot is reserved for vendored Composer installs (an
archive containing the resolved `vendor/`, `composer.json`,
`composer.lock`). Phase 1 ships the layout but the host install path
ignores the `backend/` slot and falls back to `composer require`
against the manifest's `composer.{package,version,repository}`.
Phase 2 will introduce `--offline` install mode that consumes
`backend/` directly.
