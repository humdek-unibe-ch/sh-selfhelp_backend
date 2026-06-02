# Plugin Testing Matrix

A plugin is considered "release-ready" when **every** row of this
matrix is green for the supported host version range.

## Status legend

The **Status** column reflects what exists in the SelfHelp host today versus
what is delivered by the standardized plugin certification harness
(`@selfhelp/shared/testing/definePluginCertification`, Slices 8A–8D):

- **IMPLEMENTED** — the tool/command exists and is runnable now (host or plugin repo).
- **CONVENTION** — a required `AGENTS.md` rule for plugin authors, but the automated
  gate is **not wired into CI yet**. Treat it as required for authors; do not assume CI enforces it.
- **NOT YET** — no tooling exists; do not claim coverage.

This matrix was re-baselined in Slice 10: each Status reflects what is actually
wired today, not what a future slice intends to add.

## The matrix

| Layer                | Test type            | Where it runs                  | Tool                          | Gate                  | Status |
|----------------------|----------------------|--------------------------------|-------------------------------|-----------------------|--------|
| Manifest             | Schema validation    | Plugin repo + host CI          | `plugin-manifest.schema.json` / `PluginManifestValidator` | Required for publish  | IMPLEMENTED (8C) |
| Manifest             | Compatibility check  | Host CI per host version       | `PluginCompatibilityValidator` | Required for publish  | IMPLEMENTED (8C) |
| Backend code         | PHPStan max          | Plugin repo CI                 | `composer phpstan`            | Required              | IMPLEMENTED |
| Backend code         | Header check         | Plugin repo CI                 | `composer headers:check`      | Required              | IMPLEMENTED |
| Backend code         | Unit tests           | Plugin repo CI                 | PHPUnit                       | Required              | IMPLEMENTED (8C, SurveyJS) |
| Backend code         | Integration tests    | Plugin repo CI vs. test DB     | PHPUnit (`InstallLifecycleCertificationTestCase`) | Required              | IMPLEMENTED (8B base) |
| Backend code         | Schema-parity        | Plugin repo CI                 | `scripts/check-schema-parity.mjs` | Required          | IMPLEMENTED (5) |
| Frontend code        | TypeScript           | Plugin repo CI                 | `tsc --noEmit`                | Required              | IMPLEMENTED |
| Frontend code        | ESLint               | Plugin repo CI                 | `eslint`                      | Required              | CONVENTION (validate-plugin.yml runs typecheck + Vitest, not eslint yet) |
| Frontend code        | Unit tests           | Plugin repo CI                 | Vitest                        | Required              | IMPLEMENTED (8D, SurveyJS) |
| Frontend code        | Build artefact       | Plugin repo CI                 | Vite                          | Required              | IMPLEMENTED |
| Mobile code          | TypeScript           | Plugin repo CI (when `mobile`) | `tsc --noEmit`                | Required if mobile    | IMPLEMENTED |
| Mobile code          | Renderer tests       | Plugin repo CI (when `mobile`) | Vitest (`__tests__/parity/` + `__tests__/renderer/`) | Required if mobile | IMPLEMENTED (8D parity snapshot + render-model snapshot; runs in `validate-plugin.yml`) |
| Mobile code          | Mobile-lint          | Plugin repo CI                 | `scripts/lint-mobile-plugins.mjs` | Required if mobile | CONVENTION (lint script not built yet; the dedicated `plugin-mobile-check.yml` + full RN render harness arrive in Slice 9) |
| End-to-end           | Managed-mode install | Host CI matrix                 | PHPUnit (`InstallLifecycleCertificationTestCase`) | Required              | IMPLEMENTED (8B, request scope) |
| End-to-end           | Update + rollback    | Host deploy smoke (CLI)        | `selfhelp:plugin:run-operation` | Required              | PARTIAL — lock-file restore/rollback-hash certified DB-free (`PluginLockFileLifecycleTest` + `LockFileAssertion`); DB-orchestrated `PluginRollbacker` run-through is DEPLOY-TIME (needs failed-op rows + non-transactional disk writes) |
| End-to-end           | Uninstall + purge    | Host deploy smoke (CLI)        | `selfhelp:plugin:run-operation` | Required              | PARTIAL — lock-file uninstall reversal certified DB-free (`PluginLockFileLifecycleTest`) + purge `--confirm` guard integration-tested (`PluginCliCommandsTest`); destructive table-drop run-through is DEPLOY-TIME |
| End-to-end           | Web preview          | Host CI                        | Playwright                    | Required              | IMPLEMENTED (8D, SurveyJS Creator) |
| End-to-end           | Mobile preview       | Host CI (when `mobile`)        | Maestro (Expo)                | Required if mobile    | NOT YET |
| Operational          | Doctor smoke         | Host CI per release            | `selfhelp:plugin:doctor`      | Required              | CONVENTION (command exists; not wired into release CI yet) |
| Operational          | Signature verify     | Release / install              | `PluginSignatureVerifier` (in installer) | Required              | CONVENTION (enforced inside install finalize; no standalone CI gate) |

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

## Install-lifecycle certification base (Slice 8B)

The reusable, host-side certification base is:

```
tests/Certification/InstallLifecycleCertificationTestCase.php   (abstract base)
tests/Certification/Plugin/CertFixturePluginCertificationTest.php (synthetic proof)
```

A plugin's backend certification suite extends the base and supplies its
`plugin.json` as the manifest; it then inherits the lifecycle assertions
against the **real** admin API (`--testsuite Certification`). Reaching
`202 Accepted` proves the manifest cleared the full validation gauntlet —
signature verification, compatibility (host + SDK version), and
capability/trust validation — and the base then asserts:

1. The install request records a `plugin_operations` row with the right
   `pluginId`, `type=install`, a managed `installMode`, and
   `installAction=install_dispatched`.
2. The operation is visible via `GET /admin/plugins/operations/{id}`.
3. The concurrency guard rejects a second lifecycle action while one is
   active, and `POST /admin/plugins/operations/{id}/cancel` clears it.

### Why the base certifies the *request*, not a finished install

In every non-`dev` environment the host runs installs in **managed mode**
(`InstallModeResolver` refuses inline/`development` mode unless
`APP_ENV=dev`). Managed mode only *records* the operation; a CLI/CI worker
(`selfhelp:plugin:run-operation`) later runs composer + npm + Doctrine
migrations and calls `PluginInstaller::finalize()`, which **writes to disk**
(`selfhelp.plugins.lock.json` + `config/selfhelp_plugin_bundles.php`). Those
writes are non-transactional and would pollute the working tree, so finalize
is deliberately a deployment step — it is **not** exercised inside the
WebTestCase DB transaction. The full `requested → running → succeeded`
run-through (composer/npm + Doctrine migrations + table drops on purge)
therefore belongs to the **deploy-time CLI smoke**, not to this base.

### Lock-file lifecycle is certified DB-free (8B/8C)

The lock-file *primitives* finalize/rollback rely on do NOT need a live install,
so they are certified directly — no DB, no kernel, no composer — in
`tests/Plugin/Lifecycle/PluginLockFileLifecycleTest.php` (Unit suite) using the
real `PluginLockFileWriter`/`PluginLockFileReader` against a throwaway temp dir,
with reusable assertions in `tests/Support/LockFileAssertion.php`:

1. **Install records an entry** — `write()` then read-back asserts the plugin's
   `id`/`version`/`checksum` are present.
2. **Uninstall reverses install state** — `removePlugin()` drops only the named
   entry; emptying the lock leaves an empty `plugins` list, not a deleted file.
3. **Rollback restores the lock-file hash** — a captured snapshot fed back
   through `restore()` is byte-identical (same SHA-256), which is exactly what
   `PluginRollbacker` calls when it replays `snapshots[lockFileBefore]`;
   `restore(null)` removes the file for a rollback-to-fresh-state.

The purge **`--confirm` guard** (irreversible op refuses before touching the
service) is integration-tested in `PluginCliCommandsTest::testPurgeRefusesWithoutConfirmFlag`.

What remains a **documented deploy-time exception**: the DB-orchestrated
`PluginRollbacker::rollback()` / `selfhelp:plugin:run-operation` path that reads
a `failed` `plugin_operations` row and runs composer/npm/migration reversal —
it needs real operation rows plus non-transactional disk writes, so the
deploy-time CLI smoke owns it, never the in-transaction WebTestCase.

> Scope note (Slice 10): Slice 10 landed the **host** post-deploy smoke —
> `.github/workflows/post-deploy-smoke.yml` running `tests/Smoke/HealthSmokeTest.php`
> (health probe + login + CMS write + scheduled job + realtime). That proves a
> deployed host is healthy. The **plugin-operation** deploy smoke above
> (`selfhelp:plugin:run-operation` finalize → update → rollback → purge against a
> live stack) is a separate deployment-time step that is documented but not yet
> CI-automated.

> **Note on `ManagedModeInstallTest`.** The older
> `tests/Controller/Api/V1/Admin/Plugin/ManagedModeInstallTest.php` predates
> the managed-mode + CLI-finalize split and its full-finalize assertions are
> stale against the current pipeline (untrusted manifests may no longer ship
> a backend bundle; unsigned installs need the dev/test signature opt-out).
> Treat `InstallLifecycleCertificationTestCase` as the canonical host-side
> backend certification; `ManagedModeInstallTest` is pending a rewrite onto
> the deploy-time CLI smoke.

Every plugin should ship a subclass of `InstallLifecycleCertificationTestCase`
(or the `@selfhelp/shared/testing` certification kit for frontend/mobile) so
its real manifest is certified against the host on every host version.

## SurveyJS reference certification (Slice 8C)

SurveyJS is the reference plugin for the certification kit. Its backend
certification is split across the two repos by what can run where:

- **Plugin repo (`sh2-shp-survey-js/backend/tests/`, runs in plugin CI):**
  - `Service/SurveyResponseServiceTest.php` + `Service/SurveyDashboardServiceTest.php`
    — unit coverage for the submission + dashboard services.
  - `Certification/PluginManifestCertificationTest.php` — standalone
    certification that `plugin.json` declares a complete compatibility
    matrix + deny-by-default capability/trust contract (no host needed).
- **Host repo (`tests/Certification/Plugin/SurveyJsPluginCertificationTest.php`):**
  runs SurveyJS's **real** `official` manifest through the host's
  `PluginManifestValidator`, `PluginCompatibilityValidator`, and
  `PluginCapabilityValidator`. It validates (not paste-installs) because an
  unsigned `official` manifest with a backend bundle cannot be paste-installed
  in the test env (see the base's scope note above). It skips cleanly when the
  plugin is not checked out as a sibling of the host.

Slice 8D adds the frontend + mobile + CI tiers, all in the plugin repo:

- **Frontend Creator E2E (`frontend/tests/e2e/creator.spec.ts`, Playwright):**
  logs in as a manage-capable admin, opens the consolidated SurveyJS admin
  page, and reaches the Survey Creator (`.svc-creator`). Release-tier and
  env-gated (`isCreatorE2eConfigured()`), with login/list/creator perf
  budgets. Self-skips with no QA stack.
- **Mobile renderer parity (`mobile/__tests__/parity/registration.test.ts`,
  Vitest):** parity + registration snapshot for the read-only `surveyjs`
  style. Guards the `PLUGIN_VERSION` ↔ `plugin.json` sync footgun and the
  declared style/feature-flag contract.
- **Mobile renderer snapshot (`mobile/__tests__/renderer/readOnlyRenderer.test.ts`,
  Vitest):** pins the read-only viewer's render model — the `extractSurveyId`
  resolution and the per-question card list (`extractQuestions`) that
  `SurveyJsReadOnlyStyle` draws one card per — via an inline snapshot. Runs in
  the Node env against the inert `react-native` stub, so it needs no native
  runtime. **Documented deviation:** a full React Native *component* render
  snapshot (RTL / jest-preset, rendering `<View>`/`<Text>` host trees) is a
  deliberate Slice 9 deferral; the inert stub exists precisely so the package
  is testable under Node until that harness lands. Mobile renderer coverage
  today = registration parity snapshot + this render-model snapshot, **not** a
  rendered-component snapshot.
- **CI:** `validate-plugin.yml` runs Vitest in the frontend + mobile jobs
  (PR tier); `plugin-certification.yml` runs the release tier (backend
  PHPStan + PHPUnit, Playwright Creator E2E with browsers, mobile parity)
  and is the check `release/*` branch protection requires (Slice 10).

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
