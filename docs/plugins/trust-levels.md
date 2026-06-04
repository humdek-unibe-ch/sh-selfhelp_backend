# Trust Levels

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Plugin layer code and the schemas under this folder.

Every plugin carries a single trust level that controls:

- Which signature mode applies to its installs.
- Which capabilities the admin can grant it without an explicit
  "I understand" confirmation.
- Whether the doctor flags a host upgrade as "blocking" or only
  "warning".
- Whether the plugin is allowed to ship its own static admin routes.

## The three levels

| Trust level | Verification at install                        | Default channel | Storage in DB |
|-------------|------------------------------------------------|-----------------|---------------|
| `official`  | Signature **must** match a key in `config/keys/plugin-signing/official/`. | `stable`        | `plugins.trust_level='official'` |
| `reviewed`  | Signature **must** match a key in `…/reviewed/`. | `stable`        | `'reviewed'`  |
| `untrusted` | Signature checked if present; **not** required. | Any             | `'untrusted'` |

The trust level is **set by the source**, not by the plugin itself.
A plugin published to two sources may carry two trust levels — one
per source. The admin chooses which source to install from.

## Capability matrix

| Capability                                  | `official` | `reviewed` | `untrusted` |
|---------------------------------------------|------------|------------|-------------|
| `plugin.styles.contribute`                  | ✅         | ✅         | ✅          |
| `plugin.api-routes.contribute`              | ✅         | ✅         | ✅          |
| `plugin.lookups.contribute`                 | ✅         | ✅         | ✅          |
| `plugin.feature-flags.contribute`           | ✅         | ✅         | ✅          |
| `plugin.realtime.publish:<topic-prefix>`    | ✅         | ✅         | ✅ (own prefix only) |
| `plugin.data-tables.read-own`               | ✅         | ✅         | ✅          |
| `plugin.data-tables.write-own`              | ✅         | ✅         | ✅          |
| `plugin.data-tables.read-foreign`           | ✅         | ⚠️ confirm | ❌          |
| `plugin.data-tables.write-foreign`          | ⚠️ confirm | ❌         | ❌          |
| `plugin.users.read`                         | ✅         | ⚠️ confirm | ❌          |
| `plugin.users.write`                        | ⚠️ confirm | ❌         | ❌          |
| `plugin.host.exec` (shell, fs)              | ⚠️ confirm | ❌         | ❌          |
| `plugin.host.secrets.read`                  | ❌         | ❌         | ❌          |

Legend:
- ✅ — allowed without confirmation.
- ⚠️ confirm — allowed but the admin UI shows an "I understand"
  modal listing the risk.
- ❌ — not allowed even with confirmation. The capability is
  silently dropped from the install request.

## Signature modes

The `SELFHELP_PLUGIN_SIGNATURE_MODE` env var sets the global default:

| Env value   | Behaviour for `official` / `reviewed`        | Behaviour for `untrusted` |
|-------------|----------------------------------------------|---------------------------|
| `strict`    | Hard-fail on missing or invalid signature.   | Warn on missing; hard-fail on **invalid**. |
| `lenient`   | Warn on missing; hard-fail on **invalid**.    | Warn on either.            |

Production defaults to `strict`. Dev defaults to `lenient`. Single
plugins can be force-installed past a signature failure only by
flipping the env var and re-running the operation — the orchestrator
re-reads the env on every request.

## Promoting a plugin to a higher trust level

There is **no UI** to flip trust. Promotion is a manual three-step
process:

1. Add the publisher's public key to
   `config/keys/plugin-signing/<level>/`.
2. Re-publish the source row with the new `trust_level`.
3. Re-install the plugin from the upgraded source.

This guarantees a trust change is auditable in git history.

## Demoting a plugin

A demotion uses the same flow in reverse, plus an immediate
`safe-mode on` for the plugin if the new trust level would no longer
allow the currently-granted capabilities.

## Related docs

- [Security model](./security-model.md)
- [Capabilities](./capabilities.md)
- [Registry & channels](./registry-and-channels.md)
