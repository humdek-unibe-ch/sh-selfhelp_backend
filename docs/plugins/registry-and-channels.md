# Plugin Registry & Release Channels

Plugins come from one or more **plugin sources** (composer/npm
registries, Git repos, or local file paths) and ship through **release
channels** (`stable` / `beta` / `alpha` / `nightly`).

## Plugin sources

Stored in `plugin_sources`; described by
[`PluginSource`](../../src/Entity/Plugin/PluginSource.php):

| Column                | Notes                                             |
|-----------------------|---------------------------------------------------|
| `name`                | Friendly source name (admin UI).                  |
| `kind`                | `public-registry` / `private-registry` / `git` / `local`. |
| `composer_url`        | Composer repository base URL.                     |
| `npm_url`             | npm registry URL.                                 |
| `git_url`             | Git remote (when `kind=git`).                     |
| `auth_header_name`    | Authorization header to send (e.g. `Authorization`). |
| `auth_secret_env_var` | **Name** of the env var that holds the secret. The secret itself **never** lives in the DB. |
| `channel`             | Default release channel for this source.          |
| `trust_level`         | Determines which signature mode applies.          |

A single source can serve all four channels — the channel is just a
metadata field, not a separate URL.

## Release channels

| Channel   | Audience                | Doctor warning if stale | Auto-install? |
|-----------|-------------------------|-------------------------|---------------|
| `stable`  | Production              | None                    | Yes           |
| `beta`    | Staging / QA            | After 30 days           | Behind feature flag |
| `alpha`   | Internal preview        | After 14 days           | No            |
| `nightly` | Dev only                | After 24 h              | No            |

The channel of an installed plugin is recorded in `plugins.channel`.
The doctor compares `plugins.channel` against the source's current
channel offering and emits a `warning` row when the channel is no
longer offered by the source.

## Secret handling

Plugin sources may require auth. The DB **never** stores secrets;
instead it stores the **name** of the env var that holds the secret:

```php
$source->setAuthHeaderName('Authorization');
$source->setAuthSecretEnvVar('SELFHELP_PLUGIN_REGISTRY_TOKEN');
```

At runtime, [`PluginSourceClient`](../../src/Plugin/Source/PluginSourceClient.php)
looks up `$_SERVER['SELFHELP_PLUGIN_REGISTRY_TOKEN']` and adds the
header. Rotating the secret is a single env-var change — no DB
migration, no leaked-secret incident from a stolen DB dump.

## Adding a source

```bash
# From the admin UI (preferred): Admin → Plugins → Sources → Add
# Or via the API:
curl -X POST $API/cms-api/v1/admin/plugin-sources \
  -H "Authorization: Bearer $JWT" \
  -d '{
    "name": "humdek-internal",
    "kind": "private-registry",
    "composerUrl": "https://repo.humdek.example/composer",
    "npmUrl": "https://repo.humdek.example/npm",
    "channel": "stable",
    "trustLevel": "reviewed",
    "authHeaderName": "Authorization",
    "authSecretEnvVar": "HUMDEK_REGISTRY_TOKEN"
  }'
```

## Channel promotion workflow

The recommended promotion path:

```
nightly  →  alpha  →  beta  →  stable
   ↑         ↑          ↑         ↑
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
