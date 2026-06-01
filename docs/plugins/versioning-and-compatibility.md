# Versioning & Compatibility

Every plugin declares two version fields in `plugin.json`:

```jsonc
{
  "version": "1.4.2",
  "pluginApiVersion": "1.0",
  "compatibility": {
    "selfhelp": ">=8.0.0 <9.0.0",
    "pluginApi": "^1.0"
  }
}
```

| Field                       | Meaning                                       |
|-----------------------------|-----------------------------------------------|
| `version`                   | Plugin's own SemVer.                          |
| `pluginApiVersion`          | The plugin SDK version the plugin was built against. |
| `compatibility.selfhelp`    | Acceptable host CMS version range (SemVer).   |
| `compatibility.pluginApi`   | Acceptable host plugin-API range (SemVer).    |

## How compatibility is enforced

[`PluginCompatibilityValidator`](../../src/Plugin/Versioning/PluginCompatibilityValidator.php)
runs at three points:

1. **Pre-install** — refuses installs whose `compatibility.selfhelp`
   or `compatibility.pluginApi` does not match the running host.
2. **At boot** — `PluginRuntime` warns (and disables, in safe-mode)
   plugins whose compatibility no longer matches after a host upgrade.
3. **In the doctor command** — produces the `compatibility` block
   per plugin and the per-plugin "Compatibility" health row.

Results map to three severities:

| Severity   | When                                                           |
|------------|----------------------------------------------------------------|
| `ok`       | Both `selfhelp` and `pluginApi` ranges match.                  |
| `warning`  | Soft mismatch (e.g. plugin built against `^1.0`, host on `1.1`). |
| `blocking` | Hard mismatch — plugin will not be loaded.                      |

## SemVer rules

The host applies standard SemVer with one tightened constraint:

- For `compatibility.selfhelp`, MAJOR version changes are **always**
  considered breaking. A plugin pinned to `<9.0.0` will be marked
  `blocking` against a `9.0.0` host even if no plugin-API surface
  changed.
- For `compatibility.pluginApi`, the host honors caret ranges
  (`^1.0`) and tilde ranges (`~1.0`) with the standard SemVer
  semantics.

## When to bump the host `pluginApiVersion`

We bump the **MAJOR** of `selfhelp.plugin_api_version` (defined in
`config/services.yaml`) any time:

- We remove an exported symbol from `@selfhelp/shared/plugin-sdk`.
- We change the shape of a manifest field with no compatibility shim.
- We rename a capability.
- We rename or remove a `IPluginApi` field.

We bump the **MINOR** for:

- Net-new exports / fields / capabilities (additive).
- New optional manifest sections (additive).

We bump the **PATCH** only for bug fixes that don't change
the API surface.

## When to bump the host CMS version

We bump the **MAJOR** when:

- We change the DB schema in a way that requires plugins to ship
  a new migration with their next release.
- We change the response shape of a `/cms-api/v1` route a plugin
  is documented to depend on.

We bump the **MINOR** for additive routes / fields / styles.

## Verifying compatibility locally

```bash
# Full doctor report
php bin/console selfhelp:plugin:doctor

# Just-this-plugin compatibility row
php bin/console selfhelp:plugin:doctor --json | jq '.plugins[] | select(.pluginId=="sh2-shp-survey-js").compatibility'
```

## Related docs

- [Install modes](./install-modes.md)
- [Registry & channels](./registry-and-channels.md)
- [`@selfhelp/shared` CHANGELOG](../../../sh-selfhelp_shared/CHANGELOG.md)
