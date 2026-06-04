# Testing Guidelines

Audience: Developers and technical operators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Runtime code, configuration, migrations, and tests in this repository.

> Canonical policy lives in `AGENTS.md` → **Testing Rules** (the 34 canonical rules + backend-specific additions). This document is the *implementation guide*: what exists today, how to run it, and how to copy the reference patterns. It describes the **implemented state**, not aspirations.

## Implementation status

| Capability | Status | Where |
| --- | --- | --- |
| Single PHPUnit config with DAMA transaction rollback | Implemented | `phpunit.dist.xml`, `config/packages/test/doctrine.yaml` |
| QA baseline fixture (production permission model) + fixture version | Implemented | `src/DataFixtures/Test/QaBaselineFixture.php` |
| QA frontend golden fixture (`qa-feedback` form page) + smoke proof | Implemented | `src/DataFixtures/Test/QaFrontendGoldenFixture.php`, `tests/Smoke/QaFrontendGoldenFixtureSmokeTest.php` |
| Safe test DB reset command | Implemented | `src/Command/Test/AppTestResetDbCommand.php` |
| HTTP/kernel base test cases + envelope assertions + JWT login | Implemented | `tests/Support/QaWebTestCase.php`, `QaKernelTestCase.php` |
| Performance budgets | Implemented | `tests/Support/Timing.php` |
| Mercure recorder (no real realtime traffic) | Implemented | `tests/Support/MercureTestRecorder.php`, `config/services_test.yaml` |
| No-real-outbound probe (email) | Implemented | `tests/Support/Notifier/RecordingNotifier.php` |
| Cleanup proof (no non-QA leaks) | Implemented | `tests/Support/QaCleanupVerifier.php` |
| Test factories | Implemented | `tests/Support/Factories/ActionFactory.php`, `ScheduledJobFactory.php` |
| Smoke test (login + fixture version) | Implemented | `tests/Smoke/QaLoginSmokeTest.php` |
| Public health probe (`GET /cms-api/v1/health`) | Implemented | `src/Controller/Api/V1/HealthController.php`, `migrations/Version20260602091045.php` |
| Post-deploy readiness smoke (health + login + CMS write + job + realtime) | Implemented | `tests/Smoke/HealthSmokeTest.php` |
| Post-deploy smoke CI | Implemented | `.github/workflows/post-deploy-smoke.yml` |
| Golden workflow (form → action → scheduled job) | Implemented | `tests/Golden/FormActionJobChainTest.php` |
| Golden workflow (CMS page create → publish → compare → delete) | Implemented | `tests/Golden/PageVersioningWorkflowTest.php` |
| `PermissionMatrixProvider` (admin-only matrix trait) | Implemented | `tests/Support/Security/PermissionMatrixProvider.php` |
| QA test-data convention guard (ratchet) | Implemented | `scripts/check-test-data-prefix.php`, `composer test:check-data` |
| Backend CI (reset + unit + smoke + security + golden) | Implemented | `.github/workflows/backend-tests.yml` |
| `MigrationRoundTripTestCase` + chain/per-migration round-trip tests | Implemented | `tests/Support/MigrationRoundTripTestCase.php`, `tests/Integration/Migrations/` |
| Plugin CLI integration tests (beyond `--help`) | Implemented | `tests/Integration/Command/Plugin/PluginCliCommandsTest.php` |
| Dedicated `/cms-api/v1/forms/*` route migration | Implemented | `migrations/Version20260602081706.php` |
| Migration round-trip CI | Implemented | `.github/workflows/migration-test.yml` |
| Coverage gate enforcement in CI | Implemented (shared, blocking) — see *Coverage gates* below | `sh-selfhelp_shared/vitest.config.ts`, `shared-tests.yml` |
| Playwright golden E2E + perf budgets | Implemented (Slice 7) | frontend repo: `e2e/golden/`, `e2e-golden.yml` |
| One-command frontend E2E harness (`npm run test:golden`) | Implemented | frontend repo: `scripts/e2e-stack.mjs` |
| axe-core accessibility specs | Implemented (Slice 11) | frontend repo: `e2e/a11y/a11y.spec.ts`, `e2e/utils/a11y.ts` |
| Visual regression + labelled baseline updates | Implemented (Slice 11) | frontend repo: `e2e/visual/visual.spec.ts`, `.github/workflows/visual-snapshots.yml` |
| Lighthouse CI (warning-only) | Implemented (Slice 11) | frontend repo: `lighthouserc.json`, `.github/workflows/lighthouse.yml` |
| Shared / frontend / mobile / plugin harnesses | Implemented (Slices 5–9) | respective repos |

Anything marked *Planned* is a convention in `AGENTS.md` that becomes enforceable once its utility lands. Do not reference a planned utility as if it exists.

## PHPUnit configuration (real)

`phpunit.dist.xml` (not `phpunit.xml.dist`) defines four test suites and enables DAMA:

- `Smoke` → `tests/Smoke` — fast deployment/seed verification.
- `Unit` → `tests/Unit` and the DB-free plugin-host tests (`tests/Plugin`).
- `Integration` → `tests/Integration`, `tests/Controller`, `tests/Service`, `tests/Api`.
- `Golden` → `tests/Golden` — end-to-end business workflows.
- `Certification` → `tests/Certification` — plugin certification suite (Slice 8).

The `security` PHPUnit group (`#[Group('security')]`) tags the negative-permission matrix tests. CI runs `--testsuite=Integration --group=security` so the hardened permission tests gate every PR without pulling in the not-yet-migrated legacy Integration tests.

Key facts:

- `APP_ENV=test` is forced; `failOnWarning="true"`.
- The DAMA extension (`DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension`) wraps **every test in a transaction and rolls it back** at tear-down. Do **not** call `beginTransaction()`/`rollback()` by hand — that pattern is retired.
- There is **no `<coverage>` block** in the default config (it aborts when no coverage driver is present). Generate coverage on demand with `composer test:coverage`.

## Standard commands

Use these names only (canonical rule 21 — never invent new ones):

```bash
composer test            # full suite, --testdox
composer test:unit       # Unit suite (DB-free)
composer test:integration# Integration suite
composer test:smoke      # Smoke suite (login + fixture version)
composer test:golden     # Golden workflows
composer test:migration  # migration round-trip (slow; isolated throwaway DB)
composer test:reset-db   # safe drop -> create -> migrate -> seed QA baseline
composer test:changed    # only changed/new test files (fast loop)
composer test:check-data # QA test-data convention guard (no DB; runs in CI)
composer test:release    # pre-push: check-data + reset + unit + integration + smoke + golden
composer test:nightly    # release-tier wrapper: test:release + test:random + test:migration
composer test:random     # full suite in random order (order-independence)
composer test:coverage   # HTML coverage to build/coverage/html (needs Xdebug/PCOV)
```

`composer test:nightly` is the release/nightly wrapper (plan §3): it runs `test:release` (which already includes the golden suite), then re-runs the whole suite in random order (`test:random`), then the migration round-trip group (`test:migration`). Golden is intentionally not listed twice because `test:release` already runs it.

`composer test:check-data` runs `scripts/check-test-data-prefix.php`: a fast static guard that fails the build if a non-legacy test invents non-QA business data (`'keyword'/'url'/'generated_id'` literals not `qa`-prefixed), logs in with a placeholder email (`@example.com` / `@example.org` / `@test.com`), or hardcodes a weak password. It is a **ratchet**: pre-existing offenders live in `LEGACY_ALLOWLIST` (warnings, not failures) and the list only ever shrinks. **The `LEGACY_ALLOWLIST` is now empty — every former offender (including `MailTemplateServiceTest` and `InterpolationServiceTest`) was migrated to the QA personas, so the guard reports 0 violations and 0 legacy warnings.** Do **not** reintroduce placeholder emails or non-QA business data — use `QaBaselineFixture::QA_*_EMAIL` / the `qa`/`qa_`/`qa-` prefix instead; a new offender fails the build immediately (there is nothing left to grandfather). Run `composer test:check-data -- --all` to see every offender including any (currently none) grandfathered legacy ones.

## Local setup

```bash
# 1. Start the throwaway test services (MySQL + Redis + Mercure + Mailpit)
docker compose -f docker-compose.test.yml up -d --wait

# 2. Install deps + JWT keys (the smoke test signs a real JWT)
composer install
php bin/console lexik:jwt:generate-keypair --skip-if-exists

# 3. Seed and run
composer test:reset-db
composer test:changed     # while working
composer test:release     # before pushing
```

`docker-compose.test.yml` defines health checks for MySQL/Redis/Mercure so CI (and `--wait`) block until they are ready — never `sleep`.

## QA fixtures and personas

`QaBaselineFixture` (group `qa`) seeds the QA personas through the **production permission model** (the same `Lookup`, `Group`, `Role`, `rel_groups_users` path `CreateAdminUserCommand` uses):

- `qa.admin@selfhelp.test`, `qa.editor@selfhelp.test`, `qa.user@selfhelp.test`, `qa.guest@selfhelp.test`
- Shared password constant `QaBaselineFixture::QA_PASSWORD`.
- `QaBaselineFixture::QA_FIXTURE_VERSION` (e.g. `2026_05_22_001`) is written to a DB marker. The smoke test prints and asserts it, so a stale/missing seed fails loudly.

`QaWebTestCase::setUp()` asserts the baseline is loaded (via `InteractsWithQaBaseline`) before any test runs.

### QA frontend golden fixture (`qa-feedback`)

`QaFrontendGoldenFixture` (also group `qa`, loaded alongside the baseline by
`composer test:reset-db`) owns the canonical CMS page the **frontend** golden
E2E drives, so the frontend repo no longer needs hand-exported QA variables or
a hand-built form page. It seeds:

- a page with keyword `qa-feedback` at URL `/qa-feedback` (ACL-gated, **not**
  open access);
- a `form-log` section (a log form, so the page renders without a pre-existing
  data table) carrying a non-empty `alert_success` (what makes the frontend
  `FormStyle` render its "Success" alert);
- a child `text-input` section whose CMS `name` is `qa_message` (the DOM
  `name="qa_message"` the spec fills);
- a `select + insert` ACL for the seeded **`subject`** group, so
  `qa.user@selfhelp.test` (a subject member) can view **and** submit the form —
  the real authenticated form path.

It follows the production entity/service patterns of
`tests/Support/Factories/PageSectionFactory` (Doctrine entities +
`ACLService::addGroupAcl`) without `src/` depending on a `tests/` class, is
idempotent under `--append`, and invalidates the `PAGES`/`SECTIONS`/`PERMISSIONS`
cache categories after seeding. `QaFrontendGoldenFixtureSmokeTest` (group
`smoke`) proves a fresh `test:reset-db` produced the page, the section/field
graph, and the subject ACL (and that `qa.user` resolves `select`+`insert`).

The canonical QA constants the frontend mirrors:

| Frontend env | Value | Backend source |
| --- | --- | --- |
| `QA_USER_EMAIL` | `qa.user@selfhelp.test` | `QaBaselineFixture::QA_USER_EMAIL` |
| `QA_USER_PASSWORD` | `QaPassw0rd!2026` | `QaBaselineFixture::QA_PASSWORD` |
| `QA_FORM_PAGE_KEYWORD` | `qa-feedback` | `QaFrontendGoldenFixture::QA_FORM_PAGE_KEYWORD` |
| `QA_FORM_FIELDS` | `{"qa_message":"qa automated golden entry"}` | `QaFrontendGoldenFixture::QA_FORM_FIELD_NAME` |
| `QA_FORM_SUBMIT_LABEL` | `Save` | frontend `FormStyle` default |

### One-command frontend golden E2E

The frontend repo ships a reusable harness (`scripts/e2e-stack.mjs`) so a
developer runs the whole stack with **one command and zero manual exports**:

```bash
# in sh-selfhelp_frontend
npm run test:golden
```

That harness locates this backend (via `SELFHELP_BACKEND_DIR` or the sibling
`../sh-selfhelp_backend`), brings up `docker-compose.test.yml`, generates JWT
test keys if missing, runs `composer test:reset-db` (seeding both fixtures
above), starts Symfony on `127.0.0.1:8000` and Next on `127.0.0.1:3000`, warms
the auth/page/form paths, runs the Playwright golden suite, and stops only the
processes it started. The same harness backs `npm run test:a11y` /
`test:visual` (and future `admin` / `plugin` suites) — see the frontend README
for the override knobs.

The backend is served by the built-in `php -S` through a small router
(`scripts/php-server-router.php` in the frontend repo): `php -S` does **not**
expose the process `APP_ENV` to the app (only the CLI/console SAPI does), so a
plain `php -S` would boot `dev` and miss the seeded `_test` database — the router
bridges `getenv()` → `$_SERVER` so the app boots `test`, and on POSIX enables
`PHP_CLI_SERVER_WORKERS` for concurrency. The golden spec asserts the canonical
perf budgets (login < 500 ms, form submit < 1000 ms) against the **API
round-trip**; on a single-threaded local `php -S` (Windows) the harness relaxes
only the blocking limit (timings stay logged/warned), while CI's multi-worker
server enforces the strict budgets.

## Safe DB reset

`app:test:reset-db` runs drop → create → migrate → fixtures, **each in its own subprocess** (a single process corrupts the connection during DDL and breaks the fixtures commit). Before destroying anything it asserts:

- `APP_ENV=test`,
- the database name contains `_test`,
- the DB host is in `TEST_DB_ALLOWED_HOSTS`,
- `--force` was passed,

and it prints the target database name first. It refuses to touch a non-test database.

## Base classes and utilities

- `tests/Support/QaWebTestCase` — `WebTestCase` + `loginAsQaAdmin/Editor/User/Guest()` (real JWT), `jsonRequest()`, and envelope assertions (`assertEnvelopeSuccess/Error/400/401/403/404`).
- `tests/Support/QaKernelTestCase` — kernel-level base for service/integration tests.
- `tests/Support/Timing` — performance budgets (login < 500 ms, form submit < 1000 ms, golden chain < 5000 ms) + warn/hard factors.
- `tests/Support/MercureTestRecorder` — `HubInterface` spy aliased in `config/services_test.yaml`; assert published topics, never poll.
- `tests/Support/Notifier/RecordingNotifier` — wraps captured mailer messages; `assertMailerIsNullTransport()` proves no real email left the test.
- `tests/Support/QaCleanupVerifier` — snapshot business tables before, assert every new row is `qa`-prefixed after.
- `tests/Support/Factories/*` — build `qa_`-prefixed `Action`, `DataTable`, and `ScheduledJob` entities.

## Reference patterns to copy

**Authenticated API test** — extend `QaWebTestCase`, log in as a persona, assert the envelope and one public effect:

```php
final class ExampleAdminTest extends QaWebTestCase
{
    public function testAdminCanListPages(): void
    {
        $token = $this->loginAsQaAdmin();
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $token);
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertIsArray($data);
    }
}
```

**Permission matrix** — use the `PermissionMatrixProvider` trait inside a `QaWebTestCase`. Every admin API route has the same matrix (only `qa.admin` holds the admin role), so one call covers allowed + every forbidden persona + anonymous:

```php
#[Group('security')]
final class ExampleAdminPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testListEnforcesAdminOnlyMatrix(): void
    {
        // qa.admin -> 200, qa.editor/qa.user/qa.guest -> 403, anonymous -> 401.
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/actions');
    }

    public function testCreateIsForbiddenForNonAdmins(): void
    {
        // Negative-only (no admin success call) so the matrix never mutates
        // data; send a qa_-prefixed body in case the route is wrongly allowed.
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/pages', [
            'keyword' => 'qa_should_not_create',
            'url' => '/qa-should-not-create',
        ]);
    }
}
```

`assertAdminOnlyMatrix()` is for read routes (asserts the success path too); `assertForbiddenForNonAdmins()` is for write/destructive routes (negative half only). Reference tests: `tests/Controller/Api/V1/Admin/AdminPagePermissionTest.php`, `ActionPermissionTest.php`, `tests/Api/Admin/PageVersionDraftComparisonTest.php`.

**Golden workflow** — two canonical examples to copy:

- `tests/Golden/FormActionJobChainTest.php` drives the real `DataService::saveData()` (what `FormController::submitForm` calls), asserts the saved record, the scheduled job (status `done`), the `send_mail_ok` audit transaction, the captured-but-not-sent email, zero Mercure publishes, then runs `app:scheduled-jobs:execute-due` and the `QaCleanupVerifier`. It also covers the **failure path** (`testScheduledEmailJobWithNoRecipientsFailsAndLogsSendMailFailWithoutOutbound`, `testFailedScheduledJobIsNotAutomaticallyRetriedByExecuteDue`): a job whose recipients cannot be resolved ends `failed`, logs exactly one `send_mail_fail` audit transaction (and is not retried), with no outbound email. **Deviation from the original plan (documented, intentional):** the failure path asserts the transaction-log audit only and `expectedMercurePublishes: 0` — the backend emits **no** Mercure event on scheduled-job execution, success *or* failure (`JobSchedulerService` publishes nothing; verified, not assumed). Mercure is reserved for ACL/auth refreshes and plugin-operation progress (`selfhelp/plugins/state`). No fake "job-failed" event is fabricated to satisfy the test; if a real scheduled-job realtime event is ever added, assert it here with `$this->mercure->assertTopicPublished(...)`.
- `tests/Golden/PageVersioningWorkflowTest.php` drives the CMS publishing chain through the admin API: create page → publish (creates a version) → list versions → compare draft against the published version → unpublish → delete page → assert it is gone. It is also the slice's cleanup proof (the page it creates is deleted through the API; DAMA rolls back afterward).

Copy whichever structure matches the new workflow.

**Migration round-trip** — extend `MigrationRoundTripTestCase` and tag `#[Group('migration')]`. It drives the real Doctrine console against a **dedicated throwaway database** (configured db name + `_migrt`), so it never touches the shared `*_test` DB or DAMA:

```php
#[Group('migration')]
final class Version20260602081706RoundTripTest extends MigrationRoundTripTestCase
{
    public function testFormRouteMigrationRoundTrips(): void
    {
        // up to version -> down it -> up again -> migrate latest -> schema:validate clean
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260602081706');
    }
}
```

`assertChainRoundTrips()` validates the whole chain + head migration (see `MigrationChainRoundTripTest`); `assertMigrationRoundTrips($fqcn)` targets one migration (see `Version20260501000300RoundTripTest`). These are slow and need CREATE DATABASE privilege, so they run in `migration-test.yml` (release-tier), not the PR gate. Every new Doctrine migration should get a round-trip test here.

**Plugin CLI** — `tests/Integration/Command/Plugin/PluginCliCommandsTest.php` shows the `CommandTester` pattern for console commands: boot the kernel, find the command, assert exit code + output for the success, "no plugins", and "unknown plugin" paths. Stay on read/diagnostic commands; destructive lifecycle commands are covered by `ManagedModeInstallTest` and the Slice 8 certification suite.

## CI

- `.github/workflows/backend-tests.yml` — PR gate: spins up MySQL/Redis with health checks, runs the `composer test:check-data` static guard, prepares `.env` + JWT keys, then runs reset-db → unit → smoke → security (`--group=security`) → golden, uploading PHPUnit JUnit XML + app logs on failure.
- `.github/workflows/plugin-host-check.yml` — static analysis + schema + migration + plugin-host gate.
- `.github/workflows/migration-test.yml` — migration round-trip gate (release-tier / when migrations change): runs `composer test:migration` against an isolated throwaway DB.
- `.github/workflows/post-deploy-smoke.yml` — **post-deploy tier** (plan §18.3). Reuses the backend-tests service/env setup, resets the DB (migrate + seed), and runs `composer test:smoke` — now including `HealthSmokeTest`, which hits the public `/cms-api/v1/health` probe, performs a real qa.admin login, round-trips a throwaway qa page (create → delete), executes a due scheduled job to `done`, and asserts one `acl-changed` Mercure publish, all under the 60s budget. Invoke it from the deployment pipeline (`workflow_dispatch` with the deployed `ref`) right after a release is promoted; roll back on failure.
- `.github/workflows/ecosystem-compat.yml` — **cross-repo tier** (nightly + `workflow_dispatch`, not a PR gate). Checks the repos *together*: builds `@selfhelp/shared`, runs schema parity against the backend, boots the backend smoke + golden suites, and type-checks/tests the frontend and mobile against the **unreleased** shared build. Catches "green alone, broken together" regressions before publishing. See [Cross-repo compatibility matrix](./cross-repo-compatibility-matrix.md).

Keep them green before merging.

> **CI shape note (accepted deviation).** The original plan suggested extending
> the existing plugin-check workflows in place. The implemented gates are split
> into focused per-concern workflows (`backend-tests`, `core-backend-check`,
> `migration-test`, `plugin-host-check` in the backend; `shared-tests`,
> `frontend-tests`, `plugin-mobile-check`, etc. in the sibling repos) plus the
> cross-repo `ecosystem-compat`. This is intentional: each workflow owns one
> quality gate, runs on its own triggers, and fails with a focused signal. The
> deviation is accepted; do not consolidate them back without a clear reason.

### Branch protection (required checks)

GitHub branch-protection rules are repo *settings* (configured in the GitHub UI / API), not files in this repo. Configure them to match plan §23:

- **`main`** requires: `backend-tests`, `shared-tests`, `frontend-tests`, `mobile-parity` (`plugin-mobile-check.yml`), and `plugin-host-check`.
- **`release/*`** additionally requires: `e2e-golden`, `migration-test`, and `plugin-certification`.

GitHub identifies a required status check by its **check-run name**, which is the
job's `name:` (falling back to the job id) — *not* the workflow name. The mobile
gate's job is therefore pinned to `name: mobile-parity` (job id also
`mobile-parity`) in `plugin-mobile-check.yml`, so the `mobile-parity` required
check above literally exists in GitHub Actions. When the team selects required
checks for the other gates, pick the check run produced by each gate workflow.

`post-deploy-smoke` is intentionally **not** a merge gate — it runs after deployment, not on the PR.

### Coverage gates

Canonical rule 20: ≥ 70% line coverage on `src/Service/**` + `src/Controller/**` (backend); ≥ 60% on the runtime helpers in the other repos; a PR dropping coverage > 1% on changed files is blocked.

Implemented state:

- **Shared** (`@selfhelp/shared`): a **blocking** Vitest coverage gate (`vitest.config.ts`, istanbul provider, ≥ 60% on the framework-free runtime-helper bundle — interpolation, condition, asset-URL, CMS-class classifier, page transform). `shared-tests.yml` runs `npm run test:coverage`; the job fails below threshold. Currently ~97% lines. (Istanbul, not v8: the v8 provider double-counts files on Windows — phantom 0% entries that halve the number — so the gate would fail locally; istanbul keys coverage by resolved path and is stable across Windows + CI.)
- **Backend / frontend**: coverage is **advisory (non-blocking)** today — only `@selfhelp/shared` has a blocking gate. The 70%/60% targets are the documented policy, but because current baseline coverage on the large `src/Service`/`src/Controller` and `app/` trees is well below 70%/60%, the absolute gate is **staged**: turn it on as a blocking job only once the baseline reaches the target, so it ratchets up rather than blocking every merge from day one. Until then, generate reports on demand and do not let changed-file coverage regress:
  - Backend: `composer test:coverage` → HTML report under `build/coverage/html` (needs Xdebug/PCOV). No threshold is enforced.
  - Frontend: `npm run test:coverage` (`vitest run --coverage`) → istanbul provider (text-summary + html + lcov), **no `thresholds` block**, so the run never fails on a coverage number. (Istanbul, not v8, for the same Windows double-count reason as shared.)

### Deterministic time (ClockMock)

`phpunit.dist.xml` registers the Symfony PHPUnit bridge clock mock for the
`App` namespace (`clock-mock-namespaces` parameter). Scope, verified empirically:

- **It intercepts** the *unqualified* time functions (`time()`, `microtime()`,
  `date()`, `sleep()`, …) called inside `App\…` classes. Freeze with
  `ClockMock::withClockMock($timestamp)` (tag the test `#[Group('time-sensitive')]`).
- **It does NOT intercept** *fully-qualified* `new \DateTimeImmutable('now')` /
  `new \DateTime('now')`. Namespace shadowing can only redirect unqualified
  calls, and these constructors read the system clock directly. A probe freezing
  the clock to 2030 showed `time()` returned the frozen value while
  `new \DateTimeImmutable('now')` returned the real wall-clock time.

Consequence for this codebase:

- The one place ClockMock earns its keep today is the **impersonation-token
  expiry** math (`JWTService::createImpersonationToken` → `exp = time() + ttl`,
  unqualified `time()`). It is pinned deterministically by
  `tests/Service/Auth/JWTServiceTest::testImpersonationTokenExpiryIsFrozenNowPlusConfiguredTtl`.
- The bulk of now-relative business logic — action scheduling / reminders /
  repeaters (`ActionScheduleCalculatorService`) and refresh-token expiry — uses
  fully-qualified `\DateTimeImmutable('now')`, which ClockMock cannot freeze, and
  the real "due" selection is a DB-side `date_to_be_executed <= NOW()` comparison
  (MySQL `NOW()`, which a PHP clock mock cannot affect regardless). That behavior
  is instead covered **deterministically by data-backdating** — e.g.
  `tests/Golden/FormActionJobChainTest` back-dates `date_to_be_executed` and runs
  the due-execution path. This is more production-faithful than mocking PHP time.
- ClockMock stays configured for future use: when now-relative logic is added via
  the Symfony Clock component (`ClockInterface` + `MockClock`) or unqualified time
  functions, prefer injecting `MockClock` (or freezing ClockMock) over asserting
  with a tolerance window. Do **not** refactor working `\DateTimeImmutable('now')`
  call sites solely to make them ClockMock-testable.

## Troubleshooting & slow tests

- Common failures and fixes: `docs/developer/testing-troubleshooting.md`.
- Tests slower than 10 s and their tier: `docs/developer/testing-known-slow.md`.

---

**Next**: [Deployment Process](./16-deployment-process.md)
