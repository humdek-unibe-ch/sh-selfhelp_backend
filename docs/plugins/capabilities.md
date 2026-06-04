# Capabilities Reference

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Plugin layer code and the schemas under this folder.

Capabilities are the **runtime allow-list** that controls which host
APIs a plugin can call. The admin sees them at install time and
chooses which to grant; the plugin can declare more than it needs
and accept that the admin may deny some.

This document is the canonical list. Whenever the plugin SDK adds a
new capability, this file and
`@selfhelp/shared/src/plugin-sdk/capabilities.ts`
must be updated **together** in the same PR — the schema-parity
script enforces it.

## Naming convention

```
plugin.<surface>.<verb>[:<scope>]
```

- `surface` — what host resource is touched (`styles`, `lookups`,
  `data-tables`, `realtime`, `users`, …).
- `verb` — the operation (`contribute`, `read-own`, `write-foreign`,
  `publish`, `read`, …).
- `scope` — optional, narrows the capability (e.g.
  `plugin.realtime.publish:survey/*` only allows topics matching
  the prefix `survey/`).

## Contribution capabilities

Plugins **contribute** rows to host tables. These are additive — the
plugin owns those rows and the host renders them.

| Capability                          | What it allows                                  |
|-------------------------------------|-------------------------------------------------|
| `plugin.styles.contribute`          | Insert rows into `styles` / `styles_fields`.    |
| `plugin.api-routes.contribute`      | Insert rows into `api_routes` + permissions.    |
| `plugin.lookups.contribute`         | Insert rows into `lookups` (own type codes).    |
| `plugin.feature-flags.contribute`   | Insert rows into `plugin_feature_flags`.        |
| `plugin.permissions.contribute`     | Insert rows into `permissions` + role bindings. |
| `plugin.migrations.contribute`      | Ship a Doctrine migration class.                |

All contributed rows are tagged with `id_plugins=<plugin.id>` so the
host can clean them up on uninstall.

## Data-table capabilities

Plugins routinely store responses, runs, jobs, etc. in dedicated
tables.

| Capability                          | What it allows                                  |
|-------------------------------------|-------------------------------------------------|
| `plugin.data-tables.create-own`     | Create new `data_tables` rows tagged with the plugin. |
| `plugin.data-tables.read-own`       | Read rows from tables the plugin owns.          |
| `plugin.data-tables.write-own`      | Insert / update / delete rows in own tables.    |
| `plugin.data-tables.delete-own`     | Drop the table (purge).                          |
| `plugin.data-tables.read-foreign`   | Read rows from tables owned by **another** plugin (rare). |
| `plugin.data-tables.write-foreign`  | Write rows into tables owned by another plugin (very rare). |

The "foreign" capabilities both require trust ≥ `reviewed` and are
flagged in the admin UI.

## Realtime capabilities

Topics are namespaced under `plugin/{pluginId}/…` by the publisher,
but plugins can still publish into sub-namespaces.

| Capability                                  | What it allows                                |
|---------------------------------------------|-----------------------------------------------|
| `plugin.realtime.publish:<topic-prefix>`    | Publish to topics matching `plugin/{id}/<prefix>`. |
| `plugin.realtime.subscribe:<topic-prefix>`  | Subscribe to topics from other plugins (cross-plugin coordination). |

## User / admin capabilities

These exist for use cases like "send a notification to all users in
group X". Most plugins should not request them.

| Capability                          | What it allows                                  |
|-------------------------------------|-------------------------------------------------|
| `plugin.users.read`                 | Read the `users` table (id, email, locale).     |
| `plugin.users.write`                | Update the `users` table.                       |
| `plugin.groups.read`                | Read groups + memberships.                      |
| `plugin.notifications.send`         | Push a notification through the host's email / push service. |

## Host-process capabilities

The "dangerous" set. Almost no plugin should request these.

| Capability                          | What it allows                                  |
|-------------------------------------|-------------------------------------------------|
| `plugin.host.exec`                  | Shell out via `PackageManagerRunner` /          |
|                                     | `Symfony\Component\Process`.                    |
| `plugin.host.fs.read`               | Read files outside the plugin's own dir.        |
| `plugin.host.fs.write`              | Write files outside the plugin's own dir.       |
| `plugin.host.secrets.read`          | **Never granted.** Reserved name so the         |
|                                     | manifest validator can refuse it.               |

## How capabilities are enforced

[`PluginCapabilityValidator`](../../src/Plugin/Security/PluginCapabilityValidator.php)
is called at the entry point of every host API. The guard checks:

```php
if (!$this->guard->isGranted($pluginId, $capability)) {
    throw new CapabilityDeniedException($pluginId, $capability);
}
```

The `IPluginApi` returned by `definePlugin()` is **trimmed** at
registration time to only expose the methods matching granted
capabilities. A plugin that calls `host.users.write(...)` without
having `plugin.users.write` granted will see the method as
`undefined`; the runtime guard catches any cases where the plugin
sneaks the call through reflection.

## Listing the granted set at runtime

```php
$caps = $this->pluginRegistry->getInstalledPlugin($pluginId)->getCapabilitiesJson();
```

Or in the admin UI: **Admin → Plugins → \<plugin\> → Capabilities tab**.

## Related docs

- [Trust levels](./trust-levels.md) (capability matrix per level)
- [Security model](./security-model.md)
