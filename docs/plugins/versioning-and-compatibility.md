# Versioning & Compatibility

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-23.
Source of truth: Plugin layer code and the schemas under this folder.

Every plugin declares two version fields in `plugin.json`:

```jsonc
{
  "version": "0.1.0",
  "pluginApiVersion": "0.1.0",
  "compatibility": {
    "selfhelp": ">=0.1.0 <0.2.0",
    "pluginApi": "0.1.0",
    "mobile": "^0.1.0"          // optional: mobile renderer contract axis
  }
}
```

> **Pre-1.0 SemVer.** The core CMS and the plugin API are in the `0.x` series, where
> **every MINOR bump is breaking**. A `compatibility.selfhelp` range therefore tracks
> one core MINOR (`>=0.1.0 <0.2.0`), and a "breaking" plugin-API change bumps the
> MINOR (`0.1 → 0.2`), not the MAJOR. The rules below describe the general `1.x+`
> policy; substitute MINOR-for-MAJOR while the platform is pre-release.

| Field                       | Meaning                                       |
|-----------------------------|-----------------------------------------------|
| `version`                   | Plugin's own SemVer.                          |
| `pluginApiVersion`          | The plugin SDK version the plugin was built against. |
| `compatibility.selfhelp`    | Acceptable host CMS version range (SemVer).   |
| `compatibility.pluginApi`   | Acceptable host plugin-API range (SemVer).    |
| `compatibility.reactNative` | React Native runtime the plugin's native bundle targets (client build axis). |
| `compatibility.expoSdk`     | Expo SDK the plugin's native bundle targets (client build axis). |
| `compatibility.mobile`      | **Optional.** SelfHelp mobile *renderer contract* the plugin's native components target (SemVer range vs the `selfhelp-mobile-preview` image's advertised `mobileRendererVersion`). Omit for web-only plugins. |

> **Dual-axis mobile gate.** `reactNative` / `expoSdk` describe the *runtime* a
> plugin's native bundle needs; `compatibility.mobile` describes the SelfHelp
> *renderer contract* (mirrors `@selfhelp/shared` `MOBILE_RENDERER_VERSION`). The
> manager checks an installed plugin's `compatibility.mobile` against the mobile
> preview image's `mobileRendererVersion` and **blocks** an incompatible plugin,
> **warns** when the plugin is not in the image's bundled set (the preview then
> falls back to an "open on web" deep-link for that plugin), and **informs** for
> web-only plugins that declare no `mobile` axis. The host CMS itself does not
> enforce `compatibility.mobile` (it is a manager/preview-side axis); the schema
> only validates its shape. See
> [`../developer/cross-repo-compatibility-matrix.md`](../developer/cross-repo-compatibility-matrix.md).

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
| `warning`  | Soft mismatch (e.g. plugin built against an older `0.1.x` SDK than the host). |
| `blocking` | Hard mismatch — plugin will not be loaded.                      |

## SemVer rules

The host applies standard SemVer with one tightened constraint:

- For `compatibility.selfhelp`, the breaking-axis change is **always**
  considered breaking. Pre-1.0 that axis is the **MINOR**: a plugin
  pinned to `<0.2.0` is marked `blocking` against a `0.2.0` host even
  if no plugin-API surface changed. (Post-1.0 the same applies to the
  MAJOR.)
- For `compatibility.pluginApi`, the host honors exact (`0.1.0`),
  caret (`^0.1.0` ≡ `>=0.1.0 <0.2.0`), and tilde (`~0.1.0`) ranges
  with the standard SemVer `0.x` semantics.

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

## Pinning

An installed plugin can be **pinned** to keep its current version even
when a newer compatible release is published. Pinning is persisted on
`plugins.pinned` (boolean) — see
[`Plugin`](../../src/Entity/Plugin/Plugin.php) — and surfaced as the
`pinned` field on the admin plugin envelope.

| Surface | Behaviour |
|---------|-----------|
| API | `POST /cms-api/v1/admin/plugins/{id}/pin` and `…/unpin` (admin-only, mirror enable/disable). |
| Resolver | [`PluginReleaseResolver`](../../src/Plugin/Registry/Unified/PluginReleaseResolver.php) does not select a newer version for a pinned plugin. |
| Available updates | [`PluginAdminService::listAvailableUpdates()`](../../src/Plugin/Service/PluginAdminService.php) skips pinned plugins. |
| Core update preflight | [`SystemUpdateService`](../../src/Service/System/SystemUpdateService.php) still reports a pinned + incompatible plugin as `blocking`, and the message hints to unpin/upgrade it before the core update. A pin never silently lets an incompatible core update through. |

## Standardized compatibility errors

Both the **core update preflight** and the **plugin install/update**
flows emit the same compatibility error object via
[`CompatibilityError`](../../src/Plugin/Registry/Unified/CompatibilityError.php):

```json
{
  "component": "plugin",
  "component_id": "sh2-shp-survey-js",
  "current_version": "0.1.0",
  "target_version": "0.2.0",
  "required_range": ">=0.1.0 <0.2.0",
  "blocking": true,
  "message": "Plugin sh2-shp-survey-js is not compatible with SelfHelp 0.2.0."
}
```

`component` is `plugin` or `core`. The preflight response schema is
[`update_preflight.json`](../../config/schemas/api/v1/responses/admin/update_preflight.json);
the shared TypeScript shape lives in `@selfhelp/shared` so the frontend
and Manager render the same object.

## Related docs

- [Cross-repo compatibility matrix](../developer/cross-repo-compatibility-matrix.md) — how `@selfhelp/shared` semver anchors backend/frontend/mobile/plugin compatibility, and what to update when a contract changes.
- [Install modes](./install-modes.md)
- [Registry & channels](./registry-and-channels.md)
- [`@selfhelp/shared` CHANGELOG](../../../sh-selfhelp_shared/CHANGELOG.md)
