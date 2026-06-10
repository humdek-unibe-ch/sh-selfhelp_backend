# Plugin Registry & Release Channels

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-09.
Source of truth: Plugin layer code and the schemas under this folder.

Plugins come from one or more **plugin sources** (registry URLs, Git
repos, or local file paths) and ship through **release channels**
(`stable` / `beta` / `nightly` / `test`).

> **One unified registry, two installers.** A single published
> `registry.json` is consumed by BOTH installers: the **SelfHelp
> Manager** installs/updates the Docker-based **core** (backend, worker,
> scheduler images) from `core[]`/`worker[]`/`scheduler[]`/`frontend[]`
> release refs, and the **CMS/backend** installs/updates **plugins**
> from `plugins[]` release refs. See
> [`platform-and-plugin-ecosystem.md`](../operations/platform-and-plugin-ecosystem.md)
> for the split. This page documents the registry contract; the
> versioning rules live in
> [`versioning-and-compatibility.md`](./versioning-and-compatibility.md).

## Plugin sources

Stored in `plugin_sources`; described by
[`PluginSource`](../../src/Entity/Plugin/PluginSource.php):

| Column | Notes |
|--------|-------|
| `name` | Friendly source name (admin UI). |
| `kind` | `public-registry` / `private-registry` / `git` / `local`. |
| `url` | Base registry URL, Git remote, or direct local/manifest path depending on `kind`. |
| `auth_header_name` | Authorization header to send (for example `Authorization`). |
| `auth_secret_env_var` | **Name** of the env var that holds the secret. The secret itself never lives in the DB. |
| `channel` | Default release channel for this source. |
| `trust_level` | Determines which signature mode applies. |
| `enabled` | Whether the source is queried at runtime. |
| `is_system` | Host-managed source; read-only via the admin API except for `enabled`. |

A single source can serve all four channels; the channel is metadata,
not a separate URL.

Where sources are actually defined:

- The canonical source rows live in the `plugin_sources` table.
- Fresh installs seed one system row, `humdek-public`, from
  `Version20260522110723`.
- Operators manage additional rows through
  `GET/POST/PUT/DELETE /cms-api/v1/admin/plugins/sources`.
- `SELFHELP_PLUGIN_DEFAULT_REGISTRY_URL` is only an env override for
  the effective URL of the seeded `humdek-public` source; it does not
  replace the DB-backed source model.

## Release channels

| Channel | Audience | Doctor warning if stale | Auto-install? |
|---------|----------|-------------------------|---------------|
| `stable` | Production | None | Yes |
| `beta` | Staging / QA | After 30 days | Behind feature flag |
| `nightly` | Dev only | After 24 h | No |
| `test` | Publish rehearsal | n/a | No |

> The `ReleaseChannel` enum is `stable | beta | nightly | test` across
> the backend ([`RegistryReleaseRef::CHANNELS`](../../src/Plugin/Registry/Unified/RegistryReleaseRef.php)),
> `@selfhelp/shared` (`distribution.ts` + `plugin-sdk/registry.ts`), the
> SelfHelp Manager (`@shm/schemas`), and the registry wire schema
> (`registry.schema.json`). `test` is the staging/rehearsal channel used
> to dry-run a publish -> install -> update before promoting a release to
> `stable`; the legacy `alpha` channel was removed. Parity is asserted by
> `@selfhelp/shared`'s `channel-parity.test.ts`.

The channel of an installed plugin is recorded in `plugins.channel`.
The doctor compares `plugins.channel` against the source's current
channel offering and emits a `warning` row when the channel is no
longer offered by the source.

## Secret handling

Plugin sources may require auth. The DB never stores secrets; instead
it stores the **name** of the env var that holds the secret:

```php
$source->setAuthHeaderName('Authorization');
$source->setAuthSecretEnvVar('SELFHELP_PLUGIN_REGISTRY_TOKEN');
```

At runtime,
[`RegistryClient`](../../src/Plugin/Registry/RegistryClient.php)
looks up `$_SERVER['SELFHELP_PLUGIN_REGISTRY_TOKEN']` and adds the
header. Rotating the secret is a single env-var change: no DB
migration and no leaked-secret incident from a stolen DB dump.

## Adding a source

```bash
# From the admin UI (preferred): Admin -> Plugins -> Sources -> Add
# Or via the API:
curl -X POST $API/cms-api/v1/admin/plugins/sources \
  -H "Authorization: Bearer $JWT" \
  -d '{
    "name": "humdek-internal",
    "kind": "private-registry",
    "url": "https://repo.humdek.example/selfhelp-plugins",
    "channel": "stable",
    "trustLevel": "reviewed",
    "authHeaderName": "Authorization",
    "authSecretEnvVar": "HUMDEK_REGISTRY_TOKEN"
  }'
```

## Unified registry shape

The published `registry.json` is an **index of release references**, not
inline plugin entries. Each component array (`core`, `frontend`,
`scheduler`, `worker`, `plugins`) holds lightweight refs that point at
standalone, signed **release documents**. This is what lets one file
serve both installers and support **multiple versions per component**.

### Schema ownership and parity

The **canonical** registry schemas live in the registry repository
(`sh2-plugin-registry/*.schema.json`); that repo is the single source of
truth and validates everything it publishes with `npm run validate:unified`.

Each consumer keeps a **subset** schema that constrains only the fields it
reads — they are deliberately *not* byte-copies of the canonical superset:

- backend (this repo) — [`config/schemas/registry/registry-index.schema.json`](../../config/schemas/registry/registry-index.schema.json),
  [`plugin-release.schema.json`](../../config/schemas/registry/plugin-release.schema.json),
  [`core-release.schema.json`](../../config/schemas/registry/core-release.schema.json);
- SelfHelp Manager — the Zod/JSON schemas in `@shm/schemas`;
- `@selfhelp/shared` `distribution.ts` — the shared TypeScript contract.

Agreement is enforced by tests rather than copy-discipline:

- `tests/Plugin/Registry/Unified/UnifiedRegistrySchemaConformanceTest.php` —
  the backend-local fixture conforms to the backend subset.
- `tests/Plugin/Registry/Unified/CrossInstallerRegistrySchemaParityTest.php` —
  the **real** published registry documents validate against the backend
  subset (skips when the registry repo is not checked out alongside).
- `tests/Plugin/Registry/Unified/CrossInstallerRegistryFixtureParityTest.php` —
  canonical-JSON + Ed25519 parity on the real signed documents.

A canonical-schema change the backend has not absorbed therefore fails CI
here instead of silently breaking the live `/available` + `/install` flow.

The doc-folder mirror [`plugin-registry.schema.json`](./plugin-registry.schema.json)
carries the unified index schema (its `$id` is preserved for existing
links); the legacy single-version inline plugin schema is retired.

### Index: `registry.json`

```jsonc
{
  "schemaVersion": "1.0.0",
  "requiresManager": ">=0.1.0",
  "baseUrl": "https://humdek-unibe-ch.github.io/sh2-plugin-registry/",
  "publisher": { "name": "Humdek", "url": "https://www.humdek.unibe.ch/" },
  "core":     [ { "id": "selfhelp-core", "version": "0.1.0", "channel": "stable", "releaseUrl": "releases/core/selfhelp-core-0.1.0.json" } ],
  "frontend": [ /* RegistryReleaseRef[] */ ],
  "scheduler":[ /* RegistryReleaseRef[] */ ],
  "worker":   [ /* RegistryReleaseRef[] */ ],
  "plugins":  [
    { "id": "sh2-shp-survey-js", "version": "0.1.0", "channel": "stable", "releaseUrl": "releases/plugins/sh2-shp-survey-js-0.1.0.json" },
    { "id": "sh2-shp-survey-js", "version": "0.2.0", "channel": "stable", "releaseUrl": "releases/plugins/sh2-shp-survey-js-0.2.0.json" }
  ]
}
```

A `RegistryReleaseRef` is `{ id, version, channel, releaseUrl, blocked? }`.
`releaseUrl` is resolved against `baseUrl` when relative.

### Plugin release document (followed from `plugins[].releaseUrl`)

```jsonc
{
  "kind": "selfhelp-plugin-release",
  "id": "sh2-shp-survey-js",
  "version": "0.2.0",
  "channel": "stable",
  "official": true,
  "compatibility": { "core": ">=0.2.0 <0.3.0", "pluginApi": ">=0.2.0 <0.3.0" },
  "dependencies": { "plugins": [] },
  "artifacts": {
    "manifestUrl": "https://.../sh2-shp-survey-js-0.2.0/plugin.json",
    "archiveUrl":  "https://.../sh2-shp-survey-js-0.2.0.shplugin",
    "sha256": "sha256:<hex>"
  },
  "security": { "signature": "<base64>", "keyId": "prod", "signedPayload": "<canonical JSON>" }
}
```

| Field | Description |
|-------|-------------|
| `compatibility.core` | Host **SelfHelp core** range this plugin version supports (e.g. `>=0.2.0 <0.3.0`). |
| `compatibility.pluginApi` | Host **plugin-API** range this plugin version supports. |
| `artifacts.archiveUrl` | The signed `.shplugin` to download and install. |
| `artifacts.sha256` | `sha256:<hex>` of the `.shplugin`, verified after download. |
| `security` | Ed25519 detached signature over the canonical release document (see [`signing.md`](./signing.md)). |

> **Naming note (resolved drift).** The author-facing `plugin.json`
> manifest keeps `compatibility.selfhelp` + top-level `pluginApiVersion`;
> the **release document** expresses the same two axes as
> `compatibility.core` + `compatibility.pluginApi`. The publisher maps
> manifest → release at build time. Backend value object:
> [`PluginRelease`](../../src/Plugin/Registry/Unified/PluginRelease.php).

### How the backend consumes it

[`UnifiedRegistryClient`](../../src/Plugin/Registry/Unified/UnifiedRegistryClient.php)
fetches and parses the index, follows each `plugins[].releaseUrl`,
Ed25519-verifies every release document against the trusted keys, and on
install downloads the `.shplugin` and verifies its `sha256` before the
existing archive pipeline (`SHA256SUMS` + canonical payload + Ed25519 of
`plugin.json`) takes over. Malformed index/release documents are rejected
with a clear [`MalformedRegistryException`](../../src/Plugin/Registry/Unified/MalformedRegistryException.php).
[`PluginReleaseResolver`](../../src/Plugin/Registry/Unified/PluginReleaseResolver.php)
groups refs by `id` and selects the newest **compatible** version (see
the next section).

Optional index helpers: `publishedAt`, `advisoriesUrl`,
`compatibilityUrl`, `trustedKeysUrl`, and the top-level `publisher`
block.

Plugin authors do **not** edit `registry.json` by hand. The
`scripts/publish-to-registry.mjs` script in every plugin repo (single
cross-platform Node script — no `.sh` / `.ps1` wrappers) calls
`selfhelp-plugin-build-registry-entry` (shipped by the registry repo)
to compose a signed entry from the canonical signed payload that the
`.shplugin` was signed with — one signing event per release.

### Artifact + URL contract (must be absolute)

A unified plugin release ships a signed **`.shplugin` archive**
(`artifacts.archiveUrl`). The backend downloads it, verifies
`artifacts.sha256` (`sha256:<hex>`), then runs the existing archive
pipeline: extract to `var/plugins/<id>-<ver>/staging/`, validate the
in-archive `SHA256SUMS` + canonical signed payload + Ed25519 of
`plugin.json`, and promote the frontend bundle to
`public/plugin-artifacts/<id>-<ver>/`. From then on the browser imports
the bundle from the host's own origin
(`/plugin-artifacts/<id>-<ver>/plugin.esm.js`); it never talks to a CDN
at runtime. `artifacts.manifestUrl` and `artifacts.archiveUrl` **MUST**
be absolute `https://…` URLs (or resolve to absolute via the index
`baseUrl`); the schemas enforce `format: uri` + `pattern: ^https?://`.

`registry.json#baseUrl` is the single place a registry declares its
published origin, used to resolve relative `releaseUrl` /
`artifacts.*Url` paths. For private registries, set `baseUrl` to the URL
your authenticated host fetches the registry from (the same URL the
`PluginSource.url` column points at, with a trailing slash).

Plugin authors do **not** edit `registry.json` by hand: the
plugin-repo publish script signs one canonical release document per
release and adds the matching `RegistryReleaseRef` to the index — one
signing event per release.

## Multi-version resolution & compatibility

The registry holds **multiple versions per plugin** (multiple
`plugins[]` refs with the same `id`). The backend
[`PluginReleaseResolver`](../../src/Plugin/Registry/Unified/PluginReleaseResolver.php)
groups refs by `id` and:

- selects the **newest compatible** version by default — the highest
  `version` whose `compatibility.core` matches the running host and
  whose `compatibility.pluginApi` matches the host plugin-API;
- checks any explicitly **requested target version** and blocks it if
  incompatible;
- returns a clear error when **no** version is compatible;
- leaves an already-installed **older compatible** version valid (it is
  not force-upgraded);
- never auto-updates a **pinned** plugin (see
  [`versioning-and-compatibility.md`](./versioning-and-compatibility.md#pinning)).

Incompatibilities are reported with the standardized compatibility
error object (`component`, `component_id`, `current_version`,
`target_version`, `required_range`, `blocking`, `message`) emitted by
[`CompatibilityError`](../../src/Plugin/Registry/Unified/CompatibilityError.php),
the same shape the core update preflight uses.

### Upgrade examples

| Scenario | Outcome |
|----------|---------|
| Core `0.1.0` → `0.1.x` | **Allowed.** Patch within the same MINOR; installed `survey-js 0.1.0` (`>=0.1.0 <0.2.0`) stays compatible. |
| Core `0.1.0` → `0.2.0` with `survey-js 0.1.0` installed | **Blocked.** `survey-js 0.1.0` requires `>=0.1.0 <0.2.0`; preflight returns a `blocking` compatibility error naming the plugin. |
| `survey-js` available `0.1.0` + `0.2.0`, host on core `0.2.x` | **`0.2.0` selected** as newest compatible; `0.1.0` shown but marked incompatible. |
| Pinned `survey-js 0.1.0`, newer `0.2.0` published | **Stays on `0.1.0`.** Resolver skips pinned plugins; the Available view shows it as pinned/not-updateable. |
| `survey-js 0.2.0` only, host on core `0.1.x` | **Blocked / no compatible version.** Resolver returns a clear "no compatible version" error. |

## Channel promotion workflow

The recommended promotion path:

```text
nightly  ->  beta  ->  stable
   ^         ^         ^
  every    biweekly  monthly
  green    QA pass   release
   CI                cut
```

Each channel transition is a tag in the plugin's source repository:

```bash
# In the plugin repo
git tag v1.4.2-beta    # auto-pushes to beta channel
git tag v1.4.2         # auto-pushes to stable channel
```

The CI workflows in [`ci-workflows.md`](./ci-workflows.md) cover the
tag-triggered publish.

## Related docs

- [Install modes](./install-modes.md)
- [Trust levels](./trust-levels.md)
- [Security model](./security-model.md)
- [Lock file](./lock-file.md)
