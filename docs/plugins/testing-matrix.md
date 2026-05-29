# Plugin Testing Matrix

A plugin is considered "release-ready" when **every** row of this
matrix is green for the supported host version range.

## The matrix

| Layer                | Test type            | Where it runs                  | Tool                          | Gate                  |
|----------------------|----------------------|--------------------------------|-------------------------------|-----------------------|
| Manifest             | Schema validation    | Plugin repo CI                 | `plugin-manifest.schema.json` | Required for publish  |
| Manifest             | Compatibility check  | Host CI per host version       | `PluginCompatibilityValidator` | Required for publish  |
| Backend code         | PHPStan max          | Plugin repo CI                 | `composer phpstan`            | Required              |
| Backend code         | Header check         | Plugin repo CI                 | `composer headers:check`      | Required              |
| Backend code         | Unit tests           | Plugin repo CI                 | PHPUnit                       | Required              |
| Backend code         | Integration tests    | Plugin repo CI vs. test DB     | PHPUnit                       | Required              |
| Backend code         | Schema-parity        | Plugin repo CI                 | `scripts/check-schema-parity.mjs` | Required          |
| Frontend code        | TypeScript           | Plugin repo CI                 | `tsc --noEmit`                | Required              |
| Frontend code        | ESLint               | Plugin repo CI                 | `eslint`                      | Required              |
| Frontend code        | Unit tests           | Plugin repo CI                 | Vitest                        | Required              |
| Frontend code        | Build artefact       | Plugin repo CI                 | Vite                          | Required              |
| Mobile code          | TypeScript           | Plugin repo CI (when `mobile`) | `tsc --noEmit`                | Required if mobile    |
| Mobile code          | Mobile-lint          | Plugin repo CI                 | `scripts/lint-mobile-plugins.mjs` | Required if mobile |
| End-to-end           | Managed-mode install | Host CI matrix                 | PHPUnit + Playwright          | Required              |
| End-to-end           | Update + rollback    | Host CI matrix                 | PHPUnit                       | Required              |
| End-to-end           | Uninstall + purge    | Host CI matrix                 | PHPUnit                       | Required              |
| End-to-end           | Web preview          | Host CI                        | Playwright                    | Required              |
| End-to-end           | Mobile preview       | Host CI (when `mobile`)        | Detox (Expo)                  | Required if mobile    |
| Operational          | Doctor smoke         | Host CI per release            | `selfhelp:plugin:doctor`      | Required              |
| Operational          | Signature verify     | Host CI per release            | `selfhelp:plugin:verify`      | Required              |

## Host CI matrix

Every plugin is tested against **all currently supported host
versions** before it is published to the `stable` channel:

| Host version | Plugin API version | PHP version | Node version |
|--------------|--------------------|-------------|--------------|
| `8.0.x`      | `1.0`              | 8.4         | 20           |
| `8.1.x`      | `1.0`              | 8.4         | 20           |
| latest dev   | bleeding edge      | 8.4         | 20           |

A plugin that fails on the latest dev branch may still publish to
`stable` (the dev branch is intentionally unstable), but it **may
not** publish to `nightly` until the failure is fixed.

## End-to-end install integration test

There is a single host-side test that exercises the full managed-mode
install flow against a real plugin:

```
tests/Integration/Plugin/ManagedModeInstallTest.php
```

The test:

1. Boots a fresh test DB.
2. Inserts a `plugin_sources` row pointing at a fixture composer + npm
   registry.
3. Calls `POST /cms-api/v1/admin/plugins/install` with a manifest.
4. Watches the `plugin_operations` row through `requested → running →
   succeeded`.
5. Reads back the lock file and asserts every key.
6. Calls `POST /cms-api/v1/admin/plugins/{id}/uninstall` and asserts
   the lock file is back to its baseline.

Every plugin should have an analogous test in its own repo that
exercises its install flow against the host's test harness (provided
by `@selfhelp/shared/testing`).

## What to test in each plugin repo

A new plugin author should ship, at minimum:

- **Backend**: unit tests for every public method on the plugin's
  services + an integration test that calls the plugin's
  contributed `/cms-api/v1/plugin/{id}/…` route end-to-end.
- **Frontend**: unit tests for every component, plus a Playwright
  story file for each route.
- **Mobile** (if applicable): Detox script that mounts the plugin's
  entry screen on iOS + Android emulators.

## Useful commands

```bash
# Run only this plugin's tests (host repo)
composer test -- --filter Plugin

# Run only the manifest validator
php bin/console plugin:manifest:validate plugins/sh2-shp-survey-js/plugin.json

# Run the doctor in JSON mode
php bin/console selfhelp:plugin:doctor --json | jq .

# Run schema-parity from the shared repo
node scripts/check-schema-parity.mjs
```

## Related docs

- [CI workflows](./ci-workflows.md)
- [Plugin operations & rollback](./plugin-operations-and-rollback.md)
- [Plugin developer guide](./developer-guide.md)
