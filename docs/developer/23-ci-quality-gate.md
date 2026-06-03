<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Backend CI quality gate (`plugin-host-check`)

Audience: Developers and technical operators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Runtime code, configuration, migrations, and tests in this repository.

The workflow at `.github/workflows/plugin-host-check.yml` is the backend
quality gate that must pass before code merges to `main`. It runs on
every pull request and push to `main`, plus on manual dispatch.

Despite the historical name (`plugin-host-check`) it validates the whole
Symfony host, with extra emphasis on the plugin subsystem. It boots the
real application in `APP_ENV=test` against a throwaway **MySQL 8** and
**Redis 7** so the checks exercise the same code paths a developer hits
locally.

## What it checks

Steps run top to bottom; static checks first (fast feedback), then the
database-backed checks.

| # | Step | Strict? | Needs DB/Redis |
|---|------|---------|----------------|
| 1 | `composer validate --strict` | strict | no |
| 2 | `composer install` (cached, `--no-scripts`) | strict | no |
| 3 | Prepare test env (`.env` + JWT keys) | setup | no |
| 4 | `composer audit` | **advisory** | no |
| 5 | License headers (`composer headers:check`) | strict | no |
| 6 | PHP syntax lint (`php -l`, whole tree) | strict | no |
| 7 | Self-contained JSON Schemas (manifest/registry/lock + request) compile as Draft-07 | strict | no |
| 8 | Response JSON Schema fragments are well-formed + present | strict | no |
| 9 | PHPUnit `unit` suite (DB-free plugin-host tests) | strict | no |
| 10 | `lint:yaml config --parse-tags` | strict | no |
| 11 | `lint:container` (whole DI graph) | strict | no |
| 12 | Create test DB (`doctrine:database:create`) | strict | **yes** |
| 13 | Run migrations (`doctrine:migrations:migrate`) | strict | **yes** |
| 14 | `doctrine:schema:validate` (mapping **and** live-DB sync) | strict | **yes** |
| 15 | Plugin CLI smoke (every required command + `--help`) | strict | yes (kernel) |
| 16 | Plugin DI autowiring (key services wireable) | strict | yes (kernel) |
| 17 | `selfhelp:plugin:doctor --ci` | strict (error-only) | **yes** |

> **PHPStan is not run here.** Static analysis is the separate, strict,
> no-baseline [`core-backend-check`](./24-core-phpstan-gate.md) gate, which
> analyses the whole core (plugin code included) at level max.

"Needs DB/Redis = yes (kernel)" means the step boots the Symfony kernel
(so it needs the prepared `.env` and the service containers to exist),
but it does not itself run SQL.

## How the environment is prepared (the root-cause fix)

A fresh checkout has **no `.env`** — `.env`, `.env.local`, `.env.*` are
gitignored and there is no committed `.env.dist`. Any `bin/console`
command therefore used to die in Symfony Dotenv `bootEnv()` with:

```
Symfony\Component\Dotenv\Exception\PathException:
Unable to read the ".../.env" environment file.
```

That single failure is why the previous workflow hid the Doctrine and
doctor steps behind `|| true`. The fix:

1. `cp .env.default .env` — `.env.default` is the tracked canonical
   template.
2. The workflow's job-level `env:` block overrides the few CI-specific
   values (`APP_ENV`, `DATABASE_URL`, `REDIS_URL`, `MERCURE_PUBLIC_URL`,
   `APP_DEBUG`). Real environment variables take precedence over the
   `.env` file because Symfony Dotenv does not override already-set vars.
3. `lexik:jwt:generate-keypair --skip-if-exists` writes the gitignored
   JWT keypair so the kernel is fully bootable.

`DATABASE_URL` points at the MySQL service with database `selfhelp_ci`.
The `when@test` Doctrine config appends `_test`, so the **effective**
database is `selfhelp_ci_test`; `doctrine:database:create` creates that
name automatically.

`MERCURE_PUBLIC_URL` is intentionally empty so `plugin:doctor` skips its
Mercure hub reachability probe (no hub runs in CI).

## Strict vs advisory

- **Strict** steps fail the build. There is no `|| true` anywhere in the
  workflow.
- **Advisory**: only `composer audit` (`continue-on-error: true`). It
  currently reports transitive `twig/twig` advisories that are not
  host-exploitable from this API backend and are out of scope to bump in
  this change. Promote it to strict once the dependency tree can resolve
  clean. Everything the audit prints is still visible in the logs.

## Plugin doctor CI contract

`selfhelp:plugin:doctor --ci` exits:

- **0** when there are no `error`-level findings (warnings and
  informational notices are printed but do not fail CI), and
- **1** when any site check is `error` or any plugin compatibility is
  `error`.

The decision is implemented in
`PluginDoctorCommand::reportHasFatalError()` and covered by
`tests/Plugin/Command/PluginDoctorExitContractTest`. On a fresh checkout
with no plugins installed the report is a clean zero-exit baseline.

## PHPStan

This gate does **not** run PHPStan. Static analysis lives in the separate,
strict, no-baseline [`core-backend-check`](./24-core-phpstan-gate.md)
gate, which analyses the whole core (`bin/`, `config/`, `public/`, `src/`
— plugin code included) at level max with no baseline. Run it locally with
`composer phpstan`.

## Required GitHub branch protection checks

> **Branch protection is a GitHub *setting*, not a file in this repo.** Nothing
> in the working tree can enable or verify it. A test only prevents broken code
> from shipping **if its check is marked "Required" in the repo's branch
> protection rules** (Settings → Branches → branch protection rule → *Require
> status checks to pass*). This section is the authoritative list of which
> checks an admin must enable, per repo. Keep it in sync with
> [`15-testing-guidelines.md` → Branch protection](./15-testing-guidelines.md#branch-protection-required-checks).

The status-check name GitHub matches is the **workflow `name:`** (or the job
name when a workflow has multiple jobs). The names below are the workflow names.

### Backend (`sh-selfhelp_backend`)

| Check | Workflow | Gate | Required on |
|-------|----------|------|-------------|
| `core-backend-check` | `core-backend-check.yml` | PHPStan level max, no baseline (whole core) | `main`, `release/*` |
| `plugin-host-check` | `plugin-host-check.yml` | host validate + schema + migrations + DI + plugin doctor (this doc) | `main`, `release/*` |
| `backend-tests` | `backend-tests.yml` | `check-data` guard → reset-db → unit → smoke → security → golden | `main`, `release/*` |
| `migration-test` | `migration-test.yml` | migration up/down round-trip on a throwaway DB | `release/*` (and on PRs that touch `migrations/`) |
| `ecosystem-compat` | `ecosystem-compat.yml` | cross-repo nightly compatibility | **not a merge gate** (nightly + manual) |
| `post-deploy-smoke` | `post-deploy-smoke.yml` | post-deploy health/login/page/job/Mercure | **not a merge gate** (runs after deploy) |

### Shared (`sh-selfhelp_shared`)

| Check | Workflow | Gate | Required on |
|-------|----------|------|-------------|
| `shared-tests` | `shared-tests.yml` | type-check + Vitest + **blocking coverage gate** + `check:schemas` (schema parity) | `main`, `release/*` |
| `plugin-sdk-check` | `plugin-sdk-check.yml` | plugin SDK contract validation | `main`, `release/*` |

### Frontend (`sh-selfhelp_frontend`)

| Check | Workflow | Gate | Required on |
|-------|----------|------|-------------|
| `frontend-tests` | `frontend-tests.yml` | Vitest + lint (coverage advisory) | `main`, `release/*` |
| `plugin-runtime-check` | `plugin-runtime-check.yml` | plugin ESM runtime contract | `main`, `release/*` |
| `e2e-golden` | `e2e-golden.yml` | Playwright golden + a11y (axe) | `release/*` |
| `publish-verify` | `publish-verify.yml` | build/publish verification | `release/*` |
| `lighthouse`, `visual-snapshots` | resp. workflows | performance / visual regression | **advisory** (promote when stable) |

### Mobile (`sh-selfhelp_mobile`)

| Check | Workflow | Gate | Required on |
|-------|----------|------|-------------|
| `mobile-parity` | `plugin-mobile-check.yml` | mobile renderer parity + `node --test` | `main`, `release/*` |

### Plugin repos

Each plugin repo runs its own host-compat / certification workflow
(`plugin-host-check` equivalent + manifest validation). Require it on the
plugin's protected branches before publishing to the registry. See
[`docs/plugins/versioning-and-compatibility.md`](../plugins/versioning-and-compatibility.md)
and the [cross-repo compatibility matrix](./cross-repo-compatibility-matrix.md).

### Coverage gate state (what blocks today)

- **Shared is the only blocking coverage gate.** `shared-tests.yml` runs
  `npm run test:coverage` (istanbul, ≥ 60% on the framework-free runtime-helper
  bundle) and fails below threshold. Currently ~97%.
- **Backend and frontend coverage are advisory (staged).** The canonical policy
  is ≥ 70% on `src/Service`/`src/Controller` (backend) and ≥ 60% on new files
  (frontend), but the absolute gate is **not** enforced yet because the current
  baseline is below target. Generate reports on demand
  (`composer test:coverage`, frontend `npm run test:coverage`) and do not let
  changed-file coverage regress.
- **Before flipping a coverage gate to blocking:** raise the baseline above the
  target on the gated trees, add the `thresholds` block (frontend Vitest) or a
  failing-below-threshold step (backend), confirm it passes on `main`, *then*
  mark the check required. Ratchet up; never enable a gate that fails every PR
  from day one.

### Frontend ESLint (staged, not yet blocking)

The frontend ships an ESLint flat config (`eslint.config.mjs`) and an `npm run
lint` script (`eslint . --ext .ts,.tsx`), but **lint is intentionally not a
blocking CI step yet**. `frontend-tests.yml` runs license headers + Vitest;
`plugin-runtime-check.yml` owns `tsc` + build. Promoting lint to blocking with
`--max-warnings=0` is **not currently safe**: a full run reports **411 problems
(126 errors, 285 warnings)**. The dominant blockers are the React 19 compiler
rules `react-hooks/refs` and `react-hooks/purity` (e.g.
`src/utils/performance-monitor.utils.ts` mutates a ref and calls `Date.now()`
during render) plus `unused-imports/no-unused-vars` warnings.

To promote it (do **not** enable while errors exist):

1. Fix the 126 errors first (the `react-hooks/*` purity/ref violations are real
   correctness smells, not style nits — fix the cause, do not blanket-disable
   the rule).
2. Add a `Lint` step to `frontend-tests.yml` running `npm run lint --
   --max-warnings=0` (or a dedicated `frontend-lint` job/workflow).
3. Confirm it passes on `main`, then mark the check required in branch
   protection.

Until then, run `npm run lint` locally before pushing and do not add new
violations to changed files.

## Reproduce locally

The repository already ships a Docker stack with MySQL, Redis, Mercure
and Mailpit:

```bash
docker compose up -d
```

Then mirror the CI steps (the DB-backed ones need the test env):

```bash
# 1. env + deps
cp .env.default .env
composer install
php bin/console lexik:jwt:generate-keypair --skip-if-exists

# 2. static, no DB
composer validate --strict
composer headers:check
find src config public bin migrations tests -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
php bin/phpunit --testsuite=unit
php bin/console lint:yaml config --parse-tags
php bin/console lint:container

# 3. DB-backed (APP_ENV=test uses the *_test database)
export APP_ENV=test
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:schema:validate
php bin/console list selfhelp:plugin
php bin/console debug:autowiring 'App\Plugin' --all
MERCURE_PUBLIC_URL= php bin/console selfhelp:plugin:doctor --ci
```

JSON Schema validation needs Node:

```bash
npm install --no-save ajv-cli@5 ajv-formats@3
npx ajv compile -s docs/plugins/plugin-manifest.schema.json --strict=false
```

## Common failures and what they mean

- **`Unable to read the ".env" environment file`** — you skipped
  `cp .env.default .env`. CI does this in "Prepare test environment".
- **`doctrine:schema:validate` reports drift** — your entity change has
  no matching migration. Generate one with `php bin/console
  make:migration` (never hand-write the version) and re-run.
  - Note: locally you may also see `survey_*` tables in the diff if the
    SurveyJS plugin is installed. Those are owned by the plugin bundle
    and are **not** present in CI (a fresh checkout has no plugin bundles),
    so CI validates the host schema only.
- **Plugin CLI smoke fails** — a `selfhelp:plugin:*` command was removed
  or its service wiring broke. Update the `required` list in the workflow
  if the command was intentionally renamed/removed.
- **`plugin:doctor --ci` exits 1** — a site check or plugin compatibility
  is `error`. Read the printed report; warnings alone never fail CI.
- **`lint:yaml` fails on `!tagged_iterator`** — run it with
  `--parse-tags` (the workflow already does).

## Known blockers / TODO

- **Full PHPUnit suite is not wired into CI.** Only the DB-free `unit`
  suite (`tests/Plugin`) runs. The `Project Test Suite` needs seeded
  fixtures and a real login flow against the test DB. Follow-up: add a
  fixtures-loading + login bootstrap and a separate CI step (or job) that
  runs the integration suite against the same MySQL/Redis services.
- **Plugin install lifecycle is not exercised end-to-end in CI.** Plugin
  migrations are run by the install/update Messenger worker
  (`PluginMigrationRunner`), not by this gate — this gate validates the
  **host** schema only and does not weaken that lifecycle. Exercising a
  real install needs a packaged `.shplugin` fixture plus the messenger
  worker (`messenger:consume plugin_ops`). Follow-up: add a signed test
  fixture and a job that installs it, asserts the plugin row + migrations
  ran, then uninstalls.
- **`composer audit` is advisory.** Promote to strict once the transitive
  `twig/twig` advisories resolve.
