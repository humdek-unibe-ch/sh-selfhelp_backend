<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Core PHPStan gate (`core-backend-check`)

Audience: Developers and technical operators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Runtime code, configuration, migrations, and tests in this repository.

The workflow at `.github/workflows/core-backend-check.yml` is the
**strict, zero-tolerance** static-analysis gate for the Symfony backend.
It runs PHPStan at **level max** over the whole core code base and **fails
on the first error** — there is no baseline and no advisory fallback.

It is the **single** PHPStan gate: it analyses the entire core, including
the plugin code (`src/Plugin`, the plugin controllers). The
[`plugin-host-check`](./23-ci-quality-gate.md) workflow does **not** run
PHPStan; it covers schemas, migrations, DI wiring and the plugin doctor.

| Gate | Scope | Baseline? | Needs DB? | Purpose |
|------|-------|-----------|-----------|---------|
| `core-backend-check` (this doc) | `bin/`, `config/`, `public/`, `src/` | **no** | no | Whole core stays a genuine zero |
| `plugin-host-check` | whole host (schemas, migrations, doctor) | n/a | yes | Schemas, migrations, DI, doctor |

Both must pass before a merge to `main`.

## There is one config, and it is the default

PHPStan auto-discovers `phpstan.dist.neon`, so the gate runs with **no
`--configuration` flag**:

```bash
composer phpstan          # -> phpstan analyse --memory-limit=3G
# or directly:
vendor/bin/phpstan analyse
```

There is exactly **one** PHPStan config in the repo now: `phpstan.dist.neon`.
(The earlier `phpstan.core.neon`, `phpstan.ci.neon` and the 131 KB
`phpstan-baseline.neon` were removed — the first was folded into the
default, and the latter two were a baselined plugin-scope duplicate that
the whole-core gate supersedes.)

`phpstan.neon` is git-ignored; create one locally only if you need a
personal override — it will not be committed and will not affect CI.

## How to run it locally

The single source of truth is `phpstan.dist.neon`. Run exactly what CI
runs with the Composer script:

```bash
composer phpstan
```

That expands to:

```bash
phpstan analyse --memory-limit=3G
```

Or invoke PHPStan directly (e.g. to add `--no-progress` like CI does):

```bash
php -d memory_limit=3G vendor/bin/phpstan analyse --no-progress
```

To analyse a single file or directory while iterating (the config is still
auto-discovered):

```bash
php -d memory_limit=3G vendor/bin/phpstan analyse src/Service/CMS --no-progress
```

No database, Redis, or kernel boot is required: PHPStan analyses
statically. The Symfony PHPStan extension runs without a compiled
container XML, and the Doctrine extension reads the mapping from the
entity attributes via reflection.

## What the config does (`phpstan.dist.neon`)

- **`level: max`** — the strictest rule set.
- **`doctrine.allowNullablePropertyForRequiredField: true`** — models the
  standard Doctrine entity lifecycle (a `private ?string $email = null;`
  property for a NOT-NULL column is null only transiently between
  `new Entity()` and the setter/flush). This is not a level/scope
  reduction; a real mismatch (e.g. `DateTimeInterface` vs
  `DateTimeImmutable`) is still reported.
- **`paths`** — `bin/`, `config/`, `public/`, `src/` (the whole core).
- **`excludePaths`** — `tests/*` and the generated, git-ignored
  `config/selfhelp_plugin_bundles.php` (an environment-specific artifact
  whose bundle classes live under the per-install `var/plugin-composer/`
  tree, outside the host autoloader).
- **shipmonk dead-code detector OFF** — see below.
- **a few narrow `ignoreErrors`** — the plain-text `@method GET|POST` verb
  annotation on every Api/V1 controller (a deliberate convention PHPStan's
  phpDoc parser rejects), and three Doctrine scalar foreign-key mirror
  columns that are written by hydration + read by DQL (invisible to
  PHPStan's PHP-level read/write tracking). Each is pinned to an exact
  identifier/path and carries no byte offsets, so it is stable across
  CRLF/LF checkouts (unlike a baseline entry).

### Why the dead-code detector is off

This is a dependency-injection + database-driven-routing + reflection
codebase. Controllers, services and commands are wired and invoked at
runtime (autowiring, tagged-service iterators, the `api_routes` table,
Messenger handlers, Doctrine hydration) — they are almost never
referenced from static call sites PHPStan can see. With the dead-code
detector on, those produce false positives ("unused" methods that the
framework calls reflectively). Disabling it keeps the signal real. It is
**not** a level or scope reduction: every type rule in level max still
runs over the entire core tree.

## Why there is no baseline

A baseline (`phpstan-baseline.neon`) freezes the *current* set of errors
and only fails on *new* ones. That is the right tool for paying down a
large pre-existing debt incrementally.

The core scope has been driven to **zero real errors** by fixing the
underlying types (precise PHPDoc, generics, array shapes, narrowing, and
removing confirmed-dead code), **not** by suppressing them. Once you are
at a genuine zero, a baseline only adds risk: it can silently re-absorb a
regression if someone regenerates it, and it embeds byte-offset message
text that drifts across CRLF/LF checkouts. Keeping the gate baseline-free
means it enforces the real invariant: *the core source produces zero
PHPStan errors at level max.*

## How CI enforces zero regressions

The workflow runs on every pull request and push to `main`, plus manual
dispatch. The PHPStan step is **strict**:

- no baseline,
- no `continue-on-error`,
- no `|| true` / no advisory mode,
- the step is the job's last action and its exit code is the job result.

If PHPStan finds **one** error, the step exits non-zero, the job fails,
and the merge is blocked. The `--memory-limit=3G` (in the `composer
phpstan` script) is generous so a level-max run over the whole core never
OOMs on the runner.

## How to handle a future PHPStan failure

When the gate (or `composer phpstan` locally) reports an error:

1. **Read the identifier.** Every error has one (e.g. `argument.type`,
   `return.type`, `missingType.iterableValue`). Its docs are at
   `https://phpstan.org/error-identifiers/<identifier>`.
2. **Fix the underlying cause, behaviour-preserving.** Prefer, in order:
   - precise PHPDoc — `array<string, mixed>`, `list<T>`, array shapes,
     `non-empty-string`;
   - generics — `@extends ServiceEntityRepository<Entity>`,
     `@template` where a method is genuinely generic;
   - local narrowing — `is_string()`, `is_int()`, `is_array()`,
     `instanceof`, or a small typed helper (`asString()`, `asInt()`,
     `asArray()`);
   - improving a return/param type when it is safe and accurate;
   - removing a dependency or branch **only** when it is confirmed
     unused / unreachable (say *why* it is safe in the PR).
3. **Do not silence it.** No new `@phpstan-ignore`, no `@var`/`assert()`
   used purely to override an inferred type, no type casts added only to
   quiet the analyser, no baseline, no level/scope reduction. (`assert()`
   is acceptable only when it states a real, always-true runtime
   invariant PHPStan cannot infer — not as a silencer.)
4. **If the error reveals a real bug**, stop and fix the bug (or raise
   it) rather than papering over the type.
5. **Re-run** `composer phpstan` until it is clean, then run the
   relevant PHPUnit tests for the code you touched.

### Editing Doctrine entities

The most common core gotcha. Two patterns recur:

- **Collections must be non-nullable.** A `#[ORM\OneToMany]` property is
  always a `Collection` (Doctrine populates it on hydration). Type it
  `private Collection $items;` with `/** @var Collection<int, T> */` and
  initialise it in the constructor — never `?Collection ... = null`.
- **Don't keep scalar mirrors of foreign keys.** A column owned by a
  `#[ORM\ManyToOne]` association does not also need a
  `private int $idThing;` field. Such mirrors are read-only (Doctrine
  never writes them) and surface as `property.onlyRead`. Read the value
  through the association (`$this->thing->getId()`), or via a `findOneBy`
  on the association field (`findOneBy(['thing' => $entity])`), not a
  scalar column field.

After changing any mapping, validate it still loads:

```bash
php bin/console doctrine:mapping:info
```
