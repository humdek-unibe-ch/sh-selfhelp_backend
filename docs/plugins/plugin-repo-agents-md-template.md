<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Plugin Repository `AGENTS.md` Template

Every SelfHelp plugin repository must contain an `AGENTS.md` at the root. Subdirectories (`backend/`, `frontend/`, `mobile/`) may add their own optional `AGENTS.md` for overrides. Copy the template below verbatim into the new repo, then fill in the plugin-specific sections marked with `<…>`.

The template assumes the plugin repo layout described in `docs/plugins/architecture.md`:

```
sh2-shp-<name>/
  AGENTS.md                       (this file)
  plugin.json
  README.md
  CHANGELOG.md
  LICENSE
  .github/workflows/validate-plugin.yml
  backend/                        Composer Symfony bundle
  frontend/                       npm package (web)
  mobile/                         optional npm package (Expo)
  docs/
```

## Template

````markdown
# AGENTS.md

Before returning anything print in chat `❤️AGENTS.md` so that we know the rules are used.

## Project Overview

This is the SelfHelp plugin `<plugin-id>` (e.g. `sh2-shp-survey-js`). It extends the SelfHelp CMS through the documented plugin ecosystem.

## Critical execution rule

This project lives inside the multi-repository SelfHelp ecosystem. Always obey the `AGENTS.md` of the repository whose files you are editing.

When editing files in this plugin repo, also re-read:

- the host backend `AGENTS.md` (`sh-selfhelp_backend/AGENTS.md`) before changing anything in `backend/`;
- the host frontend `AGENTS.md` (`sh-selfhelp_frontend/AGENTS.md`) before changing anything in `frontend/`;
- the shared package `AGENTS.md` (`sh-selfhelp_shared/AGENTS.md`) before changing anything that imports from `@selfhelp/shared`;
- the mobile `AGENTS.md` (`sh-selfhelp_mobile/AGENTS.md`) before changing anything in `mobile/`.

The canonical Multi-Repository AGENTS.md Rule lives at `sh-selfhelp_backend/docs/plugins/multi-repo-agents-md.md`. All paths are repository-relative inside the operator's workspace; never hard-code absolute paths.

## Extension points only

Plugins may only extend SelfHelp through documented extension points:

- Symfony events
- tagged services
- manifest-declared API routes
- style registry entries
- admin pages
- permissions
- scheduled jobs
- declared assets
- lookup entries (via the lookup registry policy)
- realtime Mercure topics (via `PluginRealtimePublisher`)

Plugins must not:

- directly patch core services;
- modify core routes at runtime;
- write to config files outside the installer;
- access DB tables they do not own unless the access is explicitly declared in the manifest under `dataAccess.read` / `dataAccess.write`;
- poll any core endpoint;
- touch protected core tables.

## Versioning semantics

This plugin follows SelfHelp version semantics:

- **patch** (`1.0.0 → 1.0.1`) — code change without DB change. No migration.
- **minor** (`1.0.x → 1.1.0`) — always carries a DB change. Always ships a migration.
- **major** (`1.x → 2.0`) — breaking change. Manifest's `pluginApiVersion` / `compatibility.selfhelp.*` ranges must be updated.

## Lookup registry policy

Enum-like values must live in the central `lookups` table, never as hardcoded constants in the plugin code. Lookup contributions:

- are declared in `plugin.json` under the `lookups.extends` block;
- are inserted/updated/deleted only by plugin migrations, never at runtime;
- every plugin-owned row must carry `id_plugins`.

## Realtime, no polling

Plugin progress, dashboards, chat, collaborative editing, file upload status, and job progress flow over Mercure through `App\Plugin\Realtime\PluginRealtimePublisherInterface`. Polling is only allowed for bootstrap, offline fallback, and emergency compatibility mode.

## Manifest is the source of truth

- All permissions, capabilities, dependencies, conflicts, lookups, feature flags, realtime topics, and security declarations live in `plugin.json`.
- The validator + installer derive their behavior from the manifest. Do not duplicate this metadata in code.

## Repository structure (this repo)

- `plugin.json` — manifest validated by `docs/plugins/plugin-manifest.schema.json` in the backend repo.
- `backend/` — Composer package (Symfony bundle). Type `symfony-bundle`. Package name `humdek/<plugin-id>`.
- `frontend/` — npm package. Package name `@humdek/<plugin-id>`.
- `mobile/` — optional npm package. Package name `@humdek/<plugin-id>-mobile`.
- `docs/` — plugin-specific docs.
- `.github/workflows/validate-plugin.yml` — CI validation.
- `.github/workflows/publish-to-registry.yml` — tag-triggered registry publish.
- `scripts/build-shplugin.mjs` — Node-based, cross-platform `.shplugin` builder + signer. Writes `artifacts/SHA256SUMS` with archive-root-relative paths (`<hash>  artifacts/<file>`).
- `scripts/publish-to-registry.mjs` — Node-based, cross-platform publisher. Updates `<registry>/manifests/`, upserts `<registry>/registry.json`, optionally `git commit && git push`, and (with `--release`) creates a GH Release that attaches the `.shplugin`.
- `scripts/install-local.mjs` — Node-based, cross-platform local dev installer. Default mode builds the `.shplugin` and uploads it to `POST /admin/plugins/install`; `--symlink` switches to a Composer path repo + Vite dev-server fast path.

**No `.sh` / `.ps1` wrappers** — every script under `scripts/` is a
single `.mjs` file that runs identically on PowerShell, Git Bash,
WSL, macOS, and Linux. Each script auto-loads `<plugin>/.env` via
Node 22's `process.loadEnvFile`, so `SELFHELP_PLUGIN_SIGNING_KEY`,
`SELFHELP_ADMIN_TOKEN`, `SELFHELP_API_BASE`, `SELFHELP_BACKEND_PATH`,
`SELFHELP_REGISTRY_PATH`, etc. can live next to `plugin.json` without
being exported in every shell. Real `process.env` values still win
over `.env`, so CI secrets dominate. Each plugin must ship a
gitignored `.env` and a checked-in `.env.example` documenting the
full set.

## `.gitignore` (mandatory)

Every plugin repo MUST gitignore: `node_modules/`, `backend/vendor/`,
`frontend/dist/`, `mobile/dist/`, `dist/`, `*.shplugin`,
`.signing-keys/`, `*.ed25519`, `*.priv`, `*.pem`, `.env`, `.env.local`,
`coverage/`, `.phpunit.result.cache`. See
`docs/plugins/publishing-workflow.md` §1.2 for the canonical template.

NEVER commit private Ed25519 signing keys. Use GitHub Actions
repository secrets:

- `SELFHELP_PLUGIN_SIGNING_KEY` — Ed25519 secret key (base64).
- `SELFHELP_PLUGIN_SIGNING_KEY_ID` — must match a host
  `SELFHELP_PLUGIN_TRUSTED_KEYS` entry.
- `REGISTRY_PUSH_TOKEN` — PAT with `contents:write` on
  `humdek-unibe-ch/sh2-plugin-registry`.

## Coding style

- PHP files use the project SPDX header (`SPDX-FileCopyrightText: 2026 Humdek, University of Bern` + `SPDX-License-Identifier: MPL-2.0`).
- TS/TSX files use the matching two-line SPDX comment header.
- Symfony backend code follows the host backend `AGENTS.md`.
- Frontend code follows the host frontend `AGENTS.md`.
- Mobile code follows the host mobile `AGENTS.md`.

## Trust level

This plugin's `security.trustLevel` is `<official | reviewed | untrusted>`. The host installer enforces the cartesian product `(trust_level × capability)`. Do not request capabilities the trust level cannot grant.

## Validation commands

- Manifest: `npx ajv-cli validate -s ../../sh-selfhelp_backend/docs/plugins/plugin-manifest.schema.json -d plugin.json`
- Backend: `composer install -d backend`, `composer phpstan -d backend`, `composer test -d backend`
- Frontend: `npm ci --prefix frontend`, `npm run typecheck --prefix frontend`, `npm run build --prefix frontend`
- Mobile (if present): `npm ci --prefix mobile`, `npm run typecheck --prefix mobile`, `npm run build --prefix mobile`

## Testing Rules

### Canonical Testing Rules (all SelfHelp repos)

These are the canonical SelfHelp testing policy, shared verbatim across the backend, frontend, shared package, mobile app, and every plugin repo. A rule applies as soon as the tooling it references exists in this plugin repo.

1. Every new feature ships with at least one automated test at the appropriate layer (unit / integration / contract / E2E).
2. Every bug fix ships with a regression test that fails before the fix and passes after.
3. Every new API endpoint ships with a JSON-schema contract test **and** a permission-matrix test (admin/editor/user/guest + at least one negative cross-scope case).
4. Every new CMS style, action type, scheduled-job type, plugin event subscriber, or plugin realtime topic ships with an integration test for registration → use → cleanup.
5. Every new business workflow extends a golden-workflow test in `tests/Golden/` (backend) and, where a UI is involved, `e2e/golden/` (frontend / mobile).
6. Before writing or changing a test, perform a short test impact analysis: which workflow can break, which services/controllers/screens/plugin contracts are touched, which existing tests should fail, which new regression test is needed. Tests existing only to inflate coverage are rejected.
7. Tests do not depend on developer credentials. Use the seeded `qa.admin/editor/user/guest@selfhelp.test` personas.
8. QA fixtures use the production permission model. Seed test users through the same `Lookup userStatus/userTypes`, `Group`, `Role`, and `rel_groups_users` entities production uses. Special permissions go through normal admin/domain services, never raw SQL.
9. All test data writes use the `qa.` / `qa-` / `qa_` prefix. Tests never create/update/delete non-QA business records. Read-only access to system baselines is allowed.
10. Tests self-clean (DAMA transaction rollback or an explicit `afterEach`). Integration/golden tests pass the `QaCleanupVerifier` (or the per-repo equivalent).
11. Do not mock domain behaviour in integration/golden tests. Unit tests may use deterministic test doubles but must not hide real business logic. Mock external dependencies at the boundary only.
12. Date/time tests use `Symfony\Bridge\PhpUnit\ClockMock` (PHP), `vi.useFakeTimers()` (Vitest), or `page.clock.install()` (Playwright).
13. Mercure events are verified via the host `MercureTestRecorder` / shared `mockMercureHub`; never by polling.
14. Anti-flakiness: no `sleep()`, no external internet, no random IDs in fixtures or assertions, no order-dependent tests, no developer-machine absolute paths.
15. The full suite passes in random order.
16. Test names describe business behaviour, not the method under test.
17. Prefer asserting public/domain-visible effects before internal implementation details.
18. Snapshot updates must be intentional: expected change, explained in the PR, reviewable before/after. Never update snapshots just to make CI green.
19. Any test slower than 10s is a golden/release-tier test. PR-tier suites complete in under 10 minutes.
20. Coverage gate: ≥ 60% on new files. PRs dropping coverage by > 1% on changed files are blocked.
21. Use the standard test commands defined in this repo. Never invent new test command names.
22. Tests assert meaningful behaviour, not just status codes (status + envelope shape + key fields + one public side effect).
23. Do not change production logic to make tests pass. Fix the production code (and explain) or fix the wrong test.
24. Smallest runnable proof: after every 1–3 file changes, run the single new test file.
25. Every API response field a consumer relies on must exist in a host JSON Schema plus a `@selfhelp/shared` TS type. Schema drift fails CI.
26. Negative-permission tests are mandatory for every permission-sensitive endpoint (allowed → success, lower-privileged → 403, unauthenticated → 401, cross-scope → 403/404).
27. Security regression tests are required for any change to auth, capabilities, or trust level. They assert failure behaviour, not only success.
28. API backward compatibility: do not remove/rename a response field without a schema version bump, shared type update, consumer adaptation, and changelog entry.
29. Performance budgets for critical APIs are asserted in smoke/golden tests.
30. No real outbound in tests (email/SMS/push/webhooks/HTTP). Use recorders/mocks and assert captured content.
31. Test reset commands refuse to run unless `APP_ENV=test`, the db name contains `_test`, the host is allow-listed, and `--force` is provided.
32. Smoke tests print and assert the QA fixture version.
33. CI uploads failure artifacts (logs, traces, screenshots, container logs, sanitized DB dump).
34. Playwright golden specs run axe-core accessibility checks on key pages.

### Plugin-specific testing additions

- Every plugin passes the certification suite generated from `@selfhelp/shared/testing` (`definePluginCertification`).
- `plugin.json` declares a compatibility matrix: compatible backend version range, compatible `@selfhelp/shared` version range, required capabilities, required migrations, supported frontend/mobile surfaces. A mismatched matrix fails install.
- Backend bundle: at least one PHPUnit test per exposed service plus a subclass of the host-side `InstallLifecycleCertificationTestCase` that returns the plugin's real `plugin.json`.
- Frontend bundle: a Playwright spec for the plugin's admin page tree.
- Mobile bundle: a renderer-parity entry plus a snapshot if any styles are declared.
- Plugin version bumps include the matching migration test (minor + major). `patch` releases carry no DB change and need no migration test.

## Do not

- Do not patch any core service or core route at runtime.
- Do not insert/update/delete lookup rows at runtime.
- Do not poll core endpoints; use Mercure realtime topics.
- Do not access protected core tables.
- Do not ship CDN-loaded assets unless declared in `security.externalHosts`.
- Do not commit or push without explicit instruction.
````
