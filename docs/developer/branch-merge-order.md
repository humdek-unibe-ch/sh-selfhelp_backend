<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Cross-Repo CI Design and Branch Merge Order

Audience: Developers and technical operators.
Status: active.
Applies to: SelfHelp2 ecosystem repositories (backend, shared, frontend, mobile, manager, plugin registry, plugin repos).
Last verified: 2026-06-10.
Source of truth: the workflow files under each repo's `.github/workflows/` plus this document; when they disagree, the workflows win and this document must be updated.

## The problem this design solves

Coordinated features touch several repositories at once. Everybody works on
equally named feature branches (e.g. `feature/selfhelp-manager-installer` in
every repo). The old CI design hardcoded `ref: main` for every cross-repo
checkout and compared vendored schema copies against
`raw.githubusercontent.com/.../main/...`. The result was a circular mess:

- `sh-selfhelp_shared`'s schema-parity gate failed because the new backend
  JSON schemas existed only on the backend feature branch, not on `main`.
- `sh2-plugin-registry`'s build failed because its vendored
  `plugin-manifest.schema.json` had to stay byte-identical with the backend
  `main` copy, which did not yet allow the new `pluginApiVersion` format.
- `sh2-shp-survey-js`'s mobile tests failed because they ran against the
  npm-PUBLISHED `@selfhelp/shared` (SDK `1.2`) while the whole wave had
  already reconciled to the `0.1.0` plugin-API line on branches.
- Nothing could merge first without breaking somebody else's gate: a circular
  dependency between PR gates, resolved manually over and over again.

## Design rule 1: same-branch-or-main sibling resolution

Every cross-repo reference in CI resolves the sibling ref like this:

1. Take the branch name of the current build
   (`$GITHUB_HEAD_REF` on `pull_request`, else `$GITHUB_REF_NAME`).
2. If a branch with the SAME NAME exists in the sibling repo, use it.
3. Otherwise fall back to `main`.

```bash
BRANCH="${GITHUB_HEAD_REF:-$GITHUB_REF_NAME}"
REF="main"
if [ -n "$BRANCH" ] && [ "$BRANCH" != "main" ] && \
   git ls-remote --exit-code --heads \
     "https://x-access-token:${GH_TOKEN}@github.com/humdek-unibe-ch/<repo>.git" \
     "refs/heads/${BRANCH}" > /dev/null 2>&1; then
  REF="$BRANCH"
fi
```

Consequences:

- A PR from a coordinated feature branch gates against the matching feature
  branches of its siblings — the wave is validated as a whole, before any
  merge.
- A PR from a solo branch (no equally named sibling branch) gates against
  `main` — exactly the old behaviour.
- Pushes to `main` (post-merge runs) and scheduled runs gate `main`-vs-`main`.
- Branch deletion after merge is harmless: the resolver falls back to `main`.
- Tags (`v*`) never match a sibling branch name, so release workflows always
  resolve `main` — by release time everything must be merged anyway.

The convention this rule relies on: **coordinated cross-repo work uses the
same branch name in every affected repo.** That is the only thing you have to
do to opt in; nothing to configure, no manual ref juggling.

## Design rule 2: test against the contract you will ship with

Repos that consume `@selfhelp/shared` from npm can be ahead of (or behind)
the published package during a wave. Wherever a CI job exercises the shared
SDK *behaviourally* (e.g. `defineMobilePlugin()` asserting
`PLUGIN_API_VERSION`), the job builds `@selfhelp/shared` from the
same-branch-or-main checkout, `npm pack`s it, and overrides the npm-resolved
copy with that tarball (`npm install --no-save <tgz>`). The published package
is never the gate for branch work; the branch contract is.

Vendored schema copies (`plugin-manifest.schema.json` in the registry and in
every plugin repo) must stay byte-identical with the canonical backend copy
at `docs/plugins/plugin-manifest.schema.json`. The sync checks compare
against the same-branch-or-main backend copy, so a schema change travels with
the wave instead of deadlocking it.

## Dependency graph (who provides what to whom)

```
sh-selfhelp_backend  ◄── CONTRACT ANCHOR (self-contained PR gates)
  │  provides: JSON schemas (config/schemas/api/v1), the canonical
  │  plugin-manifest.schema.json, core version line, REST API
  │
  ├──► sh-selfhelp_shared      (schema parity: TS types must cover backend schemas)
  ├──► sh2-plugin-registry     (vendored manifest schema must be byte-identical)
  ├──► plugin repos            (plugin.json validates against the host schema)
  ├──► sh-selfhelp_frontend    (consumes the REST API; e2e runs the real backend)
  ├──► sh-selfhelp_mobile      (consumes the REST API)
  └──► sh-manager              (e2e builds backend/frontend images)

sh-selfhelp_shared   ◄── SDK / TYPES LAYER
  │  provides: @selfhelp/shared npm package, plugin SDK (PLUGIN_API_VERSION),
  │  style registry, runtime helpers
  │
  ├──► sh-selfhelp_frontend    (npm dependency)
  ├──► sh-selfhelp_mobile      (npm dependency + style-registry parity test)
  └──► plugin repos            (plugin SDK: definePlugin/defineMobilePlugin)

sh2-plugin-registry  ◄── DISTRIBUTION LAYER (static site)
  └──► hosts + sh-manager      (runtime consumption of published releases)

plugin repos (e.g. sh2-shp-survey-js)  ◄── LEAF LAYER
  └──► publish into sh2-plugin-registry on release tags
```

`sh-manager`'s PR gate (`ci.yml`) is fully self-contained (fixture registry,
offline); its cross-repo needs only appear in the nightly/dispatch
`e2e-docker.yml`.

## What runs when you open a PR (workflow inventory)

| Repo | Workflow | PR gate? | Cross-repo refs | What it proves |
|------|----------|----------|-----------------|----------------|
| backend | `core-backend-check.yml` | yes | none | PHPStan level max, zero baseline |
| backend | `plugin-host-check.yml` | yes | none | composer validate, headers, lint, JSON schemas compile, unit suite, container lint, migrations + schema validate, plugin CLI/DI/doctor |
| backend | `backend-tests.yml` | yes | none | reset-db + unit + smoke + security matrix + golden + certification suites |
| backend | `migration-test.yml` | yes (migration paths) | none | migration up()+down() round-trips |
| backend | `ecosystem-compat.yml` | no (nightly + dispatch) | shared, frontend, mobile (same-branch-or-main) | shared build + schema parity, backend smoke/golden, frontend+mobile vs locally built shared |
| shared | `shared-tests.yml` | yes | backend (same-branch-or-main) | typecheck, Vitest + coverage gate, schema parity vs backend |
| shared | `plugin-sdk-check.yml` | yes | backend (same-branch-or-main) | headers, typecheck, tsup build, SDK exports, schema parity, unit tests |
| shared | `publish.yml` | no (release) | none | npm publish via trusted publishing |
| frontend | `frontend-tests.yml` | yes | none | headers, Vitest (MSW, no real outbound) |
| frontend | `plugin-runtime-check.yml` | yes | none | tsc, plugins-sync dry-run, host-singleton check, Next build |
| frontend | `e2e-golden.yml` | yes (e2e paths) | backend (same-branch-or-main) | Playwright golden flows against the real stack |
| frontend | `visual-snapshots.yml` | label/dispatch | backend (same-branch-or-main) | visual regression baselines |
| frontend | `lighthouse.yml` | dispatch | backend (same-branch-or-main) | performance budgets |
| mobile | `plugin-mobile-check.yml` | yes | shared (same-branch-or-main) | headers, typecheck, renderer + style-registry parity, plugins-sync dry-run, web export |
| manager | `ci.yml` | yes | none | typecheck, lint, tests, schema+signature validation, license report, build, offline smoke |
| manager | `e2e-docker.yml` | no (nightly + dispatch) | backend, frontend (input > same-branch > main) | real-Docker install/update/backup/restore/rollback journey |
| registry | `build-registry.yml` | yes | backend raw file (same-branch-or-main) | registry.json + manifests validate, signed releases verify, trust guard, schema-sync check; publishes Pages on main |
| registry | `publish-core-release.yml` | no (dispatch) | none | assembles + signs platform releases as reviewed PRs |
| plugin survey-js | `validate-plugin.yml` | yes | backend (same-branch-or-main), shared (same-branch-or-main, built + packed) | manifest schema + ownership, DB naming, PHPStan + PHPUnit (incl. manifest certification), frontend/mobile typecheck + tests + build against the branch-built SDK, host-singleton policy |
| plugin survey-js | `plugin-certification.yml` | release tier (`release/**`, tags) | shared (same-branch-or-main, built + packed) | PHPStan + full backend suite, Creator E2E, mobile parity |
| plugin survey-js | `publish-to-registry.yml` | no (tags + dispatch) | registry repo | build, sign, validate manifest against the registry's vendored schema, publish |

## Safe merge order

The order exists because post-merge `push: main` runs gate `main`-vs-`main`:
once your repo merges, its siblings' gates start seeing your `main`. Merge
providers before consumers and every post-merge run stays green.

**Phase 0 — before merging anything**

- All PRs of the wave are green (they gate against each other via
  same-branch-or-main).
- Optionally dispatch `ecosystem-compat` from the backend feature branch: it
  validates backend + shared + frontend + mobile together at branch refs.

**Phase 1 — `sh-selfhelp_backend` (the contract anchor, always first)**

- Why first: backend PR gates are fully self-contained, and every other
  repo's gate compares against backend `main` after merging. Merging backend
  first turns everyone else's `main` comparison green.
- Validation: `composer phpstan` (0 errors), `composer test:release`.

**Phase 2 — `sh-selfhelp_shared` and `sh2-plugin-registry` (parallel)**

- shared — why after backend: its schema-parity gate needs the new backend
  schemas to exist on backend `main`.
  After merge: bump the package version and publish to npm (`publish.yml`).
  A `PLUGIN_API_VERSION` change is a breaking SDK change — version the
  package accordingly and update the
  [cross-repo compatibility matrix](cross-repo-compatibility-matrix.md).
- registry — why after backend: its vendored `plugin-manifest.schema.json`
  must be byte-identical with backend `main`.
  After merge: `build-registry.yml` republishes GitHub Pages automatically.

**Phase 3 — `sh-selfhelp_frontend` and `sh-selfhelp_mobile` (parallel)**

- Why after backend: their e2e/parity gates run the real backend at `main`.
- Why after shared: they consume `@selfhelp/shared` from npm. If the wave
  added new shared types they use, bump the dependency/lock to the version
  published in Phase 2 (on the feature branch before merging, or in the
  merge PR itself).
- Validation: `npm run test:release` (frontend), `npm run typecheck && npm
  test` (mobile).

**Phase 4 — `sh-manager`**

- Why late: `ci.yml` is self-contained so it could merge anytime, but the
  nightly `e2e-docker.yml` builds backend + frontend images from `main`, so
  merging after Phases 1 and 3 keeps the nightly meaningful.

**Phase 5 — plugin repos (e.g. `sh2-shp-survey-js`)**

- Why last: `validate-plugin.yml` validates `plugin.json` against the
  backend `main` schema and tests against the shared `main` SDK build; both
  must already carry the wave.
- After merge: tag `v*` to publish into the registry
  (`publish-to-registry.yml`).

```
1. backend                      (contract anchor, self-contained)
2. shared + plugin-registry    (parallel; then publish shared to npm,
                                 registry Pages redeploys itself)
3. frontend + mobile           (parallel; bump @selfhelp/shared if needed)
4. manager
5. plugin repos                (then tag + publish to the registry)
```

## What to do, step by step, for a coordinated wave

1. Create the SAME branch name in every affected repo
   (`feature/<topic>`).
2. Work normally; push; open PRs. Every PR gates against the wave, not
   against a stale `main`.
3. Keep contract copies in sync within the wave:
   - backend `docs/plugins/plugin-manifest.schema.json` is canonical; copy
     it byte-identically into `sh2-plugin-registry/` and every plugin repo's
     `docs/plugins/` in the same wave when it changes.
   - new backend response/request schemas get their TS types in shared and a
     contract entry in `scripts/check-schema-parity.mjs` (shared repo) in the
     same wave.
4. Before merging, dispatch `ecosystem-compat` from the backend feature
   branch for a whole-wave cross-check.
5. Merge in the phase order above. Delete branches as you go — the resolver
   falls back to `main` automatically.
6. Publish in release order: shared to npm, registry Pages (automatic),
   backend/frontend images via their release workflows, plugins via tags.

## Common failure scenarios

**"Schema parity FAIL: response schema missing" in shared CI**
- Cause: backend sibling resolved to a ref without the schemas. Either the
  backend branch is not named like the shared branch, or backend `main` does
  not have them yet while your shared branch is solo-named.
- Fix: name the backend branch identically (preferred), or merge backend
  first.

**"plugin-manifest.schema.json drifted from the canonical host copy"**
- Cause: the vendored copy in the registry (or a plugin repo) does not match
  the backend copy at the resolved ref.
- Fix: re-copy the backend file byte-identically into the same wave branch.

**"incompatible pluginApiVersion: plugin wants X but host is Y"**
- Cause: a plugin job ran against the npm-published shared SDK instead of
  the branch-built one, or the plugin really targets the wrong line.
- Fix: the plugin workflows build shared from same-branch-or-main and
  override the npm copy; make sure the shared branch carrying the SDK change
  uses the wave's branch name. If the resolver correctly picked `main`, the
  plugin manifest itself is wrong — align `pluginApiVersion`.

**"non-QA url/keyword ... must be qa_/qa-/QA prefixed" (backend
`composer test:check-data`)**
- Cause: a test or fixture generator hardcodes a non-QA literal as created
  data (`'url' => '...'`, `'keyword' => '...'`).
- Fix: use QA-prefixed values or derive from a constant/variable
  (concatenations are exempt). Never extend the legacy allowlist.

**Post-merge `push: main` run goes red right after merging repo X**
- Cause: X merged before its provider (wrong phase order).
- Fix: merge the provider; re-run the failed workflow. Adopt the phase order
  next time.

## Branch protection notes

- The PR-gate workflows in the inventory table are the required checks per
  repo; post-merge `push: main` runs of the same workflows are the canary
  for wrong merge order.
- `ecosystem-compat` stays non-blocking by design (nightly + dispatch): it
  is the cross-repo safety net, not a per-PR tax. Promote individual jobs
  into per-repo PR gates only when they are proven stable.

## Related documents

- [`cross-repo-compatibility-matrix.md`](cross-repo-compatibility-matrix.md) — version alignment and release order
- [`15-testing-guidelines.md`](15-testing-guidelines.md) — canonical testing rules and required checks
- [`../operations/platform-and-plugin-ecosystem.md`](../operations/platform-and-plugin-ecosystem.md) — architecture overview
- [`ecosystem-compat.yml`](../../.github/workflows/ecosystem-compat.yml) — cross-repo CI workflow
