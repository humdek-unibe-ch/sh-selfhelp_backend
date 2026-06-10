# Plugin Compatibility Rules

Audience: Developers and technical operators.
Status: active.
Applies to: SelfHelp2 Symfony backend and the plugin/registry ecosystem.
Last verified: 2026-06-09.
Source of truth: `src/Plugin/Versioning/PluginCompatibility.php`, `src/Plugin/Versioning/SemverHelper.php`, `src/Plugin/Versioning/PluginCompatibilityValidator.php`, `src/Plugin/Registry/Unified/PluginReleaseResolver.php`, `src/Service/System/SystemVersionService.php`, `src/Service/System/SystemUpdateService.php`, `src/Service/System/SystemAdvisoryService.php`.

Every backend decision of the form "can this plugin run on this host / can this
core update proceed?" uses **one** helper —
`App\Plugin\Versioning\PluginCompatibility` — so the rules can never drift
between the four call sites that used to each implement their own variant. This
page is the canonical definition of those rules.

## The two compatibility axes

A plugin declares compatibility on two independent axes. Each is a
`SemverHelper` range check (the narrow range subset backend and
`@selfhelp/shared` agree on: exact, `^`, `~`, `>= <= > <`, and-joined,
`||`-joined, `*`/empty).

| Axis | Author field (`plugin.json`) | Registry release field | Host value checked against |
| --- | --- | --- | --- |
| **Core** | `compatibility.selfhelp` | `compatibility.core` | the SelfHelp **core** (backend) version |
| **Plugin API** | top-level `pluginApiVersion` | `compatibility.pluginApi` | the host **plugin-API (SDK)** version |

The author-facing manifest and the registry release document use different field
names for the **same** meaning; the publisher maps `selfhelp` → `core` at build
time (see `PluginRelease`). `PluginCompatibility::manifestCoreRange()` /
`manifestPluginApiRange()` resolve either shape, preferring the author field and
falling back to the registry field.

### Empty / absent range = unconstrained

A missing or empty range means **no constraint** on that axis (the plugin opts
out of that gate). `coreSatisfied('0.1.0', null)` and
`coreSatisfied('0.1.0', '')` both return `true`. This matches the long-standing
behaviour of the version summary and is now applied uniformly everywhere.

### Pre-1.0 boundary

The whole ecosystem is pre-release `0.x` (see
[the version contract](#the-version-contract)). Under semver, **every `0.x`
minor is breaking**, so a plugin pinned to `>=0.1.0 <0.2.0`:

- stays compatible across a core **patch** (`0.1.0 → 0.1.1`);
- becomes incompatible at the next core **minor** (`0.1.0 → 0.2.0`).

## `blocked` and advisories are NOT range checks

Two more inputs can make a release un-installable independently of the version
ranges:

- **`blocked`** — a per-release flag in the registry. `PluginReleaseResolver`
  treats a `blocked` release as never-resolvable (it is skipped before the range
  checks). Used to yank a bad release without deleting it.
- **Security advisories** — the registry advisory feed
  (`advisoriesUrl`). `SystemAdvisoryService` is the backend's authoritative
  advisory surface: it filters the feed to advisories that match an installed
  component's **version range** and reports `blocked`, `severity`,
  `recommended_action`, and `fixed_versions` to the maintenance UI. Advisory
  matching reuses `SemverHelper::satisfies()` so "affected" semantics match the
  compatibility axes.

**Decision (audit fix #5):** advisory evaluation lives in `SystemAdvisoryService`
(surfacing) and at install/execution time, **not** inside the pure
`PluginReleaseResolver` range logic. The resolver answers "is this version in
range and not yanked?"; the advisory service answers "is a version I run subject
to a published security advisory?". Keeping them separate is intentional: it
avoids threading the advisory feed through every range check while still giving
the operator one honest advisory view.

## Where each rule is applied

| Call site | Axis used | Helper call |
| --- | --- | --- |
| `SystemVersionService` (version summary `compatible` flag) | core, vs **current** core | `isManifestCoreCompatible()` |
| `SystemUpdateService` (core-update preflight) | core, vs **target** core | `manifestCoreRange()` + `coreSatisfied()` |
| `PluginReleaseResolver` (registry install/update resolution) | core **and** plugin-API, vs host | `coreSatisfied()` + `pluginApiSatisfied()` (after the `blocked` gate) |
| `PluginCompatibilityValidator` (manifest install badge) | core **and** plugin-API, vs host | `coreSatisfied()` + `pluginApiSatisfied()` |

The version summary and core-update preflight gate on the **core** axis (the
plugin-API axis is enforced at install/update time by the resolver + validator,
which is where the host's plugin-API version is the relevant comparand). The
core-update preflight compares installed plugins against the **target** core
version, so an installed plugin that does not admit the target blocks the update
with a standardized `CompatibilityError` (pinned plugins block with an "unpin
first" hint — they are never auto-updated).

## The version contract

The canonical ecosystem version is pre-release **`0.1.0`** with plugin-API
**`0.1.0`**, applied consistently across the backend defaults, the registry
data, fixtures, tests, and the SelfHelp Manager. There is no `8.x` distribution;
any historical reference to "SelfHelp 8" predates the pre-release reset and is
not a current version. See
[the registry & channels reference](../../docs/plugins/registry-and-channels.md)
and the operations guide
[platform-and-plugin-ecosystem.md](../operations/platform-and-plugin-ecosystem.md).

## Testing

- Helper semantics: `tests/Plugin/Versioning/PluginCompatibilityTest.php`.
- Manifest-badge validator: `tests/Plugin/Versioning/PluginCompatibilityValidatorTest.php`.
- Registry resolver (core + plugin-API + blocked): `tests/Plugin/Registry/Unified/PluginReleaseResolverTest.php`.
- Core-update preflight (signed release, target-core gate, pinned block): `tests/Unit/Service/System/SystemUpdateServicePreflightTest.php`.
- Advisory filtering: `tests/Unit/Service/System/SystemAdvisoryServiceTest.php`.
