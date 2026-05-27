# Plugin Security Model

The plugin system gives plugins **deliberate, narrow access** to the
host. Plugins are PHP/TS code running in the host process, so any
"sandbox" claim would be false. The model instead leans on three
defensive layers:

1. **Signed releases**, verified before install.
2. **Capability allow-lists**, enforced at runtime.
3. **Operational guard-rails** (install modes, safe-mode, the
   doctor, audit logs).

## Threat model

| Threat                                              | Defence                                  |
|-----------------------------------------------------|------------------------------------------|
| Compromised registry serves a malicious package     | Signature verification + trust levels.    |
| Plugin tries to read another plugin's data tables   | Capability check + entity-scope ACL.      |
| Plugin tries to call composer / npm at runtime      | `PackageManagerRunner` gated by capability and install mode. |
| Plugin runs unbounded SQL / breaks the host         | Doctrine guard rails + connection pool caps. |
| Plugin leaks the JWT signing key                    | Keys live in env vars, not DB; capability `host.secrets.read` is never granted. |
| Plugin attempts to escalate to admin                | All admin routes require `admin.*` permissions tied to roles, not plugin code. |

## Signed releases

Every published plugin tag carries an `ed25519` signature of the
release tarball. The signature is checked by
[`PluginSignatureVerifier`](../../src/Plugin/Signature/PluginSignatureVerifier.php)
before install. Two modes:

- **`strict`** (production default): unsigned or invalid-signature
  installs **fail**.
- **`lenient`** (dev default): unsigned installs **warn** but
  proceed.

Keys live under [`config/keys/plugin-signing/`](../../config/keys/plugin-signing/);
new public keys must be added to the manifest signer registry before
the first signed release from that signer.

## Capability allow-lists

Plugins declare what host APIs they want in
`plugin.json.capabilities`:

```jsonc
"capabilities": [
  "plugin.styles.contribute",
  "plugin.api-routes.contribute",
  "plugin.realtime.publish:survey/*",
  "plugin.data-tables.read-own",
  "plugin.data-tables.write-own"
]
```

The admin grants capabilities **explicitly** at install time. The
host then enforces these in three places:

1. **PHP runtime** — `CapabilityGuard::assertGranted($pluginId,
   $cap)` is called at the entry point of every host API the plugin
   could invoke (publishing realtime, calling
   `PackageManagerRunner`, etc.).
2. **API routes** — every plugin-contributed route has an
   `ApiSecurityListener` rule that re-checks the plugin's capability
   before invoking the controller.
3. **TS runtime** — `definePlugin()` returns a frozen `IPluginApi`
   that only includes the methods matching granted capabilities.

A revoked capability does **not** require a re-install; it takes
effect at the next request boundary.

## Trust levels

`plugin.trust_level` + `plugin_sources.trust_level` are crossed to
decide which signature mode to apply and which capabilities are
**dangerous** (require an explicit "I understand" confirmation in
the admin UI):

| Trust level   | Source             | Allowed capabilities                          |
|---------------|--------------------|-----------------------------------------------|
| `official`    | First-party only.  | All.                                          |
| `reviewed`    | Trusted vendors.   | All except `plugin.host.exec`.                |
| `untrusted`   | Third party.       | "Read-own" + contribute APIs only.            |

See [`trust-levels.md`](./trust-levels.md) for the full per-capability
matrix.

## Operational guard-rails

- **Install modes** — `managed` mode runs composer/npm in CI, not in
  the web request, which means a malicious package's install scripts
  cannot affect the live host. See
  [`install-modes.md`](./install-modes.md).
- **Safe mode** — `php bin/console selfhelp:plugin:safe-mode on`
  disables every plugin (without uninstalling) for recovery.
- **The doctor** — `php bin/console selfhelp:plugin:doctor` reports
  drift, failed operations, and unreachable health endpoints.
- **Audit log** — every plugin operation row is **append-only**.
  Failed operations stay visible in the admin UI forever.

## What plugins **cannot** do

- Read or write env vars.
- Read or write the JWT signing key.
- Read or write any table they did not declare in
  `plugin.json.tables.owned` or that the admin did not explicitly
  grant via the `plugin.data-tables.read-foreign` /
  `plugin.data-tables.write-foreign` capabilities.
- Modify host migrations.
- Issue HTTP responses outside the `/cms-api/v1/plugin/{pluginId}/…`
  scope unless they ship contributions to one of the host's
  domain controllers (and the host owns the route registration).
- Override host services (only **decorators** are allowed, see
  [`architecture.md`](./architecture.md) § Service container).

## Reporting a vulnerability

Plugin authors and host operators should report any signature
bypass, capability bypass, or privilege-escalation finding through
the channels in `SECURITY.md` at the host repository root. **Do not**
file public GitHub issues for unpatched plugin vulnerabilities.

## Related docs

- [Trust levels](./trust-levels.md)
- [Capabilities](./capabilities.md)
- [Install modes](./install-modes.md)
- [GDPR & data ownership](./gdpr-and-data-ownership.md)
