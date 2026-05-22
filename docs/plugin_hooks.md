# Deprecated — Dynamic PHP Proxy Hook System

> **Status:** This document is retained for historical reference only.
> The dynamic PHP proxy hook system it describes was a proposal and was
> **never implemented**. It has been **intentionally retired** in
> favour of the manifest + Symfony events + tagged services
> architecture documented under `docs/plugins/`.
>
> If you arrived here looking for the plugin extension surface, read:
>
> - [`docs/plugins/architecture.md`](plugins/architecture.md) — system-level overview.
> - [`docs/plugins/developer-guide.md`](plugins/developer-guide.md) — how to build a plugin.
> - [`docs/plugins/installation.md`](plugins/installation.md) — install / update / remove.
> - [`docs/plugins/surveyjs-plugin.md`](plugins/surveyjs-plugin.md) — reference plugin.
> - [`docs/plugins/multi-repo-agents-md.md`](plugins/multi-repo-agents-md.md) — multi-repo AI agent rule.
> - [`docs/plugins/plugin-manifest.schema.json`](plugins/plugin-manifest.schema.json) — machine-readable manifest schema.

## Why retired

The proposal below pre-dated the SurveyJS / plugin-ecosystem work. The
final design uses explicit extension points instead of a runtime
proxy interceptor:

| Concern                                | Proposal (this doc)             | Final design                                              |
| -------------------------------------- | ------------------------------- | --------------------------------------------------------- |
| Hooking core methods (`before/after`)  | Runtime proxy via `ProxyManager`| Symfony events under `App\Plugin\Event\*`                 |
| Replacing core return values           | Around / shortcircuit hooks     | Tagged services (`selfhelp.plugin.field_renderer`, …)     |
| Discovering plugin entry points        | DB-backed `hooks` table         | Manifest `plugin.json` validated by the host schema       |
| Auditability                           | Implicit                        | Every install/update writes `plugin_operations` snapshots |

Reasons the proxy approach was dropped:

1. Runtime proxies hide where behavior is contributed and are hard to
   audit.
2. They survive Symfony cache compilation poorly.
3. They can accidentally short-circuit core behavior in production.
4. They conflict with the manifest-as-source-of-truth principle the
   rest of the ecosystem relies on.

## What you should do instead

Need to react to a core action? **Dispatch / subscribe to a Symfony event.**

Need to replace a piece of UI? **Contribute a tagged service or an
`IStyleDefinition` / `IAdminPageDefinition` via the SDK.**

Need to add an API route? **Declare it in `plugin.json`** under
`apiRoutes`; the host's `ApiRouteLoader` picks it up.

Need to add a permission, lookup, feature flag, or realtime topic?
**Declare it in `plugin.json`** and seed it in your plugin's
migration.

## Removing this document

We keep the file (rather than deleting it) so external links keep
working. Once the broader documentation is more discoverable, this
file may be deleted entirely. Do not extend it.
