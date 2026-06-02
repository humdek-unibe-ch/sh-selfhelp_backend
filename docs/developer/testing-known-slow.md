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
