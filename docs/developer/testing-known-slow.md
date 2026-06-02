# Known Slow Tests Register

Any test slower than **10 seconds** must be listed here with its location, the reason it is slow, its expected wall-clock, and the tier it runs in (PR / release / nightly). A test that grows beyond its expected wall-clock by 1.5× without justification must be split or moved to a higher tier in the same PR.

This keeps the PR-tier suite under control (canonical rule 19: PR-tier suites complete in under 10 minutes per repo).

| Test | Location | Reason | Expected | Tier | Status |
| --- | --- | --- | --- | --- | --- |
| `FormActionJobChainTest` | `tests/Golden/FormActionJobChainTest.php` | Boots the kernel and runs the full form → action → scheduled-job chain twice. | ~0.5 s locally (well under the 10 s threshold) | PR | Implemented — listed for visibility; **not currently slow** |
| `PageVersioningWorkflowTest` | `tests/Golden/` | Page create → version → publish → cache-invalidation chain. | ~7 s | PR | Planned (Slice 3) |
| `PluginInstallLifecycleTest` | `tests/Certification/` | Real plugin install → update → rollback → purge against the host. | ~12 s | Release (`@group golden`) | Planned (Slices 8B–8C) |
| `form-action-job.spec.ts` | frontend `e2e/golden/` | Playwright browser run of the golden workflow + perf budgets. | ~25 s | Release | Planned (Slice 7) |
| `form-action-job.yaml` | mobile `e2e/` | Maestro device flow of the golden workflow. | ~45 s | Release (self-hosted macOS only) | Planned (Slice 9) |

## Migration round-trip coverage

The migration suite (`composer test:migration`, `#[Group('migration')]`, run in
`migration-test.yml`) has two layers:

- **Chain round-trip** — `MigrationChainRoundTripTest` migrates the whole chain
  to `latest`, runs `doctrine:schema:validate`, then round-trips the head
  migration. This proves *every* migration applies cleanly in order and the
  final schema matches the ORM mapping, so the lowest-risk migrations
  (content/CSS/page-copy seeds and stored-procedure tweaks) are covered here and
  do **not** get a dedicated class.
- **Dedicated per-migration round-trip** — one `Version*RoundTripTest` per
  high-risk migration. Each reverts just that migration mid-chain and re-applies
  it, proving its own `down()`/`up()` is exactly reversible (the thing the chain
  test cannot isolate).

Dedicated coverage targets the highest-risk migrations (schema structure,
api_routes, forms, plugin install/runtime, users, roles/permissions, pages):

| Migration | Dedicated test | Why high-risk |
| --- | --- | --- |
| `Version20260501000100` | `Version20260501000100RoundTripTest` | Reference data: lookups, languages, field_types, permissions, roles, page_types |
| `Version20260501000200` | `Version20260501000200RoundTripTest` | Fields/styles catalogue + relation rows |
| `Version20260501000300` | `Version20260501000300RoundTripTest` | api_routes + rel_api_routes_permissions seed |
| `Version20260501000400` | `Version20260501000400RoundTripTest` | System pages, sections, page ACLs |
| `Version20260521123813` | `Version20260521123813RoundTripTest` | users.last_login DATE→DATETIME (mapping alignment) |
| `Version20260522062453` | `Version20260522062453RoundTripTest` | Plugin layer tables + id_plugins FKs |
| `Version20260522062459` | `Version20260522062459RoundTripTest` | Plugin permissions + admin/public routes |
| `Version20260523141331` | `Version20260523141331RoundTripTest` | Plugin runtime ESM + Messenger + unified routes |
| `Version20260602081706` | `Version20260602081706RoundTripTest` | Authoritative `/cms-api/v1/forms/*` routes |
| `Version20260602091045` | `Version20260602091045RoundTripTest` | Public `/cms-api/v1/health` route |

Migrations **intentionally covered only by the chain round-trip** (low risk —
content/layout/copy seeds, display-flag toggles, duplicate-section cleanups,
stored-procedure refreshes, and single column-comment/column-add tweaks whose
mapping is validated at the chain head): `Version20260501000000` (baseline — its
correctness *is* the chain), `…000500`–`…000900`, `Version20260520093222`,
`Version20260520120000`, `Version20260521083727`, `Version20260522091651`,
`Version20260522100014`, `Version20260522102136`, `Version20260522110723`,
`Version20260522124403`, `Version20260523150443`, `Version20260525091440`,
`Version20260526113558`, `Version20260529080730`. Promote one of these to a
dedicated test if a future change makes its `down()` non-trivial.

Each dedicated migration round-trip runs ~6 s locally (full migrate-from-scratch
+ revert/re-apply); the whole group is release-tier, never on the PR gate.

## How to measure

```bash
# Per-test timing for a suite
php bin/phpunit --testsuite=Golden --testdox

# PHPUnit can rank the slowest tests
php bin/phpunit --testsuite=Golden --log-junit build/phpunit/golden.junit.xml
```

When adding a test that crosses 10 s:

1. Add a row above (name, location, reason, expected, tier).
2. Tag it `@group golden` (or move it to the release/nightly tier) so it does not bloat the PR gate.
3. If it is genuinely a workflow test, put it under `tests/Golden/`.
