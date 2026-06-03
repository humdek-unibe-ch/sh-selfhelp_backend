# Plugin Registry & Release Channels

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Plugin layer code and the schemas under this folder.

Plugins come from one or more **plugin sources** (registry URLs, Git
repos, or local file paths) and ship through **release channels**
(`stable` / `beta` / `alpha` / `nightly`).

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
| `alpha` | Internal preview | After 14 days | No |
| `nightly` | Dev only | After 24 h | No |

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

## Registry entry shape (v1.0)

Every `pluginEntry` in `registry.json` is validated against the
canonical schema at
[`docs/plugins/plugin-registry.schema.json`](./plugin-registry.schema.json).
The `sh2-plugin-registry` repo pins to this file so the registry +
backend share a single source of truth. Required fields:

| Field           | Description                                                                                          |
| --------------- | ---------------------------------------------------------------------------------------------------- |
| `id`            | Kebab-case plugin id.                                                                                |
| `name`          | Display name.                                                                                        |
| `version`       | SemVer version (`MAJOR.MINOR.PATCH[-prerelease]`).                                                   |
| `trustLevel`    | `official` / `reviewed` / `untrusted`.                                                               |
| `composer`      | `{package, version, repository?}`. The host runs `composer require <package>:<version>` against this. |
| `runtime`       | `{entrypointUrl, format='esm', stylesheetUrl?, integrity?, stylesheetIntegrity?}`. The host loads the entrypoint via `import()`. |
| `checksums`     | `{frontendEsm, frontendCss?}` hex SHA-256.                                                           |
| `signature`     | Base64 Ed25519 detached signature of `signedPayload`.                                                |
| `signedPayload` | Canonical JSON document (see [`signing.md`](./signing.md)). Byte-identical between PHP + Node impls.|
| `keyId`         | Publisher key id resolved via `SELFHELP_PLUGIN_TRUSTED_KEYS`.                                       |

Optional helpers: `channel`, `homepage`, `description`,
`manifestUrl` (path to the full canonical `plugin.json`),
`changelogUrl`, `compatibility` (forwarded to the compatibility
check), and the registry's top-level `publisher` block.

Plugin authors do **not** edit `registry.json` by hand. The
`scripts/publish-to-registry.mjs` script in every plugin repo (single
cross-platform Node script — no `.sh` / `.ps1` wrappers) calls
`selfhelp-plugin-build-registry-entry` (shipped by the registry repo)
to compose a signed entry from the canonical signed payload that the
`.shplugin` was signed with — one signing event per release.

### Runtime URL contract (must be absolute)

`runtime.entrypointUrl` and `runtime.stylesheetUrl` in every published
entry **MUST** be absolute `https://…` URLs. The host uses these URLs
**at install time** as the *download source* — `PluginRuntimeArtifactFetcher`
fetches the bundle, verifies its SHA-256 against the signed
`checksums.frontendEsm`, then fetches the sibling `SHA256SUMS` text
file (`<entrypoint-dir>/SHA256SUMS`) and downloads every Vite
code-split chunk listed in it, verifying each chunk's SHA-256 against
the manifest. The full tree (entry + stylesheet + every chunk) lands
in `public/plugin-artifacts/<id>-<ver>/`. From then on the browser
imports the bundle from the host's own origin
(`/plugin-artifacts/<id>-<ver>/plugin.esm.js`); it never talks to
GitHub Pages or any other CDN at runtime. The browser would refuse a
bare specifier like `artifacts/foo/plugin.esm.js` and, more
importantly, the bundle's internal imports (`/api/plugins/runtime-shim/*`)
and code-split chunk imports
(`./survey-creator-react-<hash>.js`) resolve against the importer's
origin, so a CDN-hosted entrypoint would 404 on its own dependencies.

The chunk manifest itself is anchored to the signed canonical
payload: the host refuses to trust `SHA256SUMS` unless its
`plugin.esm.js` line's hash matches `checksums.frontendEsm`. That
gives chunk integrity an equivalent guarantee to the in-archive
`SHA256SUMS` used by `.shplugin` installs without expanding the
canonical signed payload schema.

The canonical schema (`docs/plugins/plugin-registry.schema.json`,
mirrored into the registry repo as `registry.schema.json`) enforces
this via `format: uri` + `pattern: ^https?://` on both URL fields and
on the top-level `baseUrl`.

To make publishers DRY, every registry declares its own published
origin in a single place:

```json
{
    "schemaVersion": "1.0",
    "baseUrl": "https://humdek-unibe-ch.github.io/sh2-plugin-registry/",
    "publishedAt": "...",
    "publisher": { ... },
    "plugins": [ ... ]
}
```

`scripts/publish-to-registry.mjs` reads `registry.json#baseUrl` from
the target registry checkout, joins it to the relative artifact path
(`artifacts/<id>-<version>/plugin.esm.js`), and feeds the resulting
absolute URL into `build-registry-entry.mjs`. The signed canonical
payload contains the absolute URL, so the host's
`SignedPayloadBuilder` recompute matches the publisher's signature
without any host-side normalisation.

Resolution order in the publisher (highest priority first):

1. `--registry-base-url https://…/` CLI flag.
2. `SELFHELP_REGISTRY_BASE_URL` environment variable.
3. `<registry>/registry.json#baseUrl`.

The publisher throws when none of these resolves to a valid `https?://`
URL — there is no silent fallback to relative paths.

For private registries, set `baseUrl` to the URL where your
authenticated host fetches the registry from (the same URL the
`PluginSource.url` column points at, with a trailing slash).

## Channel promotion workflow

The recommended promotion path:

```text
nightly  ->  alpha  ->  beta  ->  stable
   ^         ^          ^         ^
  every    weekly     biweekly  monthly
  green    triage     QA pass   release
   CI                            cut
```

Each channel transition is a tag in the plugin's source repository:

```bash
# In the plugin repo
git tag v1.4.2-alpha   # auto-pushes to alpha channel
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
