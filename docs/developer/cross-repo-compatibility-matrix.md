<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Cross-repo compatibility matrix

SelfHelp ships as **five repositories that must agree on shared contracts**:

| Repo | Role | Versioned by |
|------|------|--------------|
| `sh-selfhelp_backend` | Symfony host (CMS API, schemas, plugin host) | `selfhelp.cms_version` (`config/services.yaml`) + `selfhelp.plugin_api_version` |
| `sh-selfhelp_shared` | TypeScript contracts: API DTOs, style registry, plugin SDK, condition/interpolation engines | npm SemVer (`@selfhelp/shared`) |
| `sh-selfhelp_frontend` | Next.js web client | consumes `@selfhelp/shared` |
| `sh-selfhelp_mobile` | Expo/React-Native client | consumes `@selfhelp/shared` |
| plugins (e.g. `sh2-shp-survey-js`) | host extensions | plugin SemVer + `plugin.json#compatibility` |

This document is the single place that explains **how those versions line up**
and **what a developer must update when a contract changes**. It complements,
and does not replace, [`docs/plugins/versioning-and-compatibility.md`](../plugins/versioning-and-compatibility.md)
(plugin ⇄ host rules) and [`docs/developer/07-versioning-strategy.md`](./07-versioning-strategy.md).

## `@selfhelp/shared` is the compatibility anchor

The backend produces JSON responses; the frontend, mobile app and plugin
runtimes consume them as **typed** data. The bridge is `@selfhelp/shared`:

- Backend response shapes are described by JSON Schemas in
  `config/schemas/api/v1/**`.
- `@selfhelp/shared` mirrors the **consumed** fields as TypeScript types
  (`src/types/api/**`, `src/types/auth.ts`, `src/types/pages.ts`).
- Frontend and mobile depend on a published `@selfhelp/shared` version and
  never read undocumented response fields.
- The drift net is `sh-selfhelp_shared/scripts/check-schema-parity.mjs`
  (run by `npm run check:schemas`): every required JSON-schema field must
  exist in the shared TS mirror, or CI fails.

Because both clients pin `@selfhelp/shared` with a caret range, **the shared
MINOR/MAJOR is the practical compatibility key for the whole ecosystem.**

### SemVer meaning for `@selfhelp/shared`

| Bump | Meaning | Consumer action |
|------|---------|-----------------|
| **patch** (`1.2.2 → 1.2.3`) | bug fix, no contract change | none |
| **minor** (`1.2.x → 1.3.0`) | additive (new optional field/type/registry entry) | optional adopt; safe within caret range |
| **major** (`1.x → 2.0.0`) | breaking (removed/renamed/retyped export or required field) | frontend **and** mobile must bump the dependency and adapt in the same release wave |

## Current matrix (snapshot)

> Keep this table in sync when bumping any anchor version. The authoritative
> values live in each repo; this is a human-readable cross-check.

| Component | Version | Anchored to |
|-----------|---------|-------------|
| Host CMS (`selfhelp.cms_version`) | `8.0.0-dev` | — |
| Host plugin API (`selfhelp.plugin_api_version`) | `1.1` | consumed by plugin `compatibility.pluginApi` |
| `@selfhelp/shared` | `1.2.2` | npm |
| `sh-selfhelp_frontend` → `@selfhelp/shared` | `^1.2.2` | shared minor `1.2` |
| `sh-selfhelp_mobile` → `@selfhelp/shared` | `^1.2.1` | shared minor `1.2` |
| `sh2-shp-survey-js` (`compatibility.selfhelp`) | `>=8.0.0-dev <9.0.0` | host CMS major `8` |
| `sh2-shp-survey-js` (`pluginApiVersion`) | `1.1` | host plugin API `1.1` |
| `sh2-shp-survey-js` runtime targets | `react ^19`, `node ^22`, `reactNative ^0.83`, `expoSdk ^55` | client runtimes |

## What the plugin `compatibility` block means

```jsonc
"compatibility": {
  "selfhelp": ">=8.0.0-dev <9.0.0",   // host CMS SemVer range (major = breaking)
  "php": "^8.4",
  "node": "^22",
  "react": "^19",
  "reactNative": "^0.83",
  "expoSdk": "^55"
}
```

The host enforces `selfhelp` and `pluginApi` ranges at install, at boot, and in
`selfhelp:plugin:doctor`. Full severity rules are in
[`versioning-and-compatibility.md`](../plugins/versioning-and-compatibility.md).
The remaining runtime targets (`node`, `react`, `reactNative`, `expoSdk`) tell
the frontend/mobile build whether the plugin's ESM/native bundle is loadable in
the current client.

## What `ecosystem-compat` checks

`.github/workflows/ecosystem-compat.yml` (nightly + manual) is the only gate
that runs the repos **together**:

1. **shared-contract** — builds `@selfhelp/shared` and runs schema parity
   against the backend JSON schemas (binding, not skipped).
2. **backend-smoke** — boots the Symfony host against MySQL/Redis, resets the
   QA baseline, runs the smoke + golden suites.
3. **frontend-vs-shared** — builds shared, installs it into the frontend as a
   local tarball, and runs the frontend type-check + Vitest against the
   **unreleased** shared.
4. **mobile-vs-shared** — same, for the Expo app (`tsc` + `node --test`).

This catches the "green alone, broken together" class of bug: a backend schema
change or a shared DTO change that compiles in its own repo but breaks a
consumer.

## What to update when you change a contract

| You change… | You MUST also update… | Verify with |
|-------------|-----------------------|-------------|
| **Backend response schema** (`config/schemas/api/v1/responses/**`) | the shared TS mirror (`@selfhelp/shared`), then any frontend/mobile/plugin reading the field; add a JSON-schema contract test | `composer test` + `npm run check:schemas` |
| **Shared DTO / exported type** | bump `@selfhelp/shared` (minor if additive, major if breaking); adapt frontend + mobile; update the CHANGELOG | `npm run typecheck && npm test` (shared) + `ecosystem-compat` |
| **Frontend renderer contract** (style impl map vs registry) | the shared style registry if a style was added/removed | frontend `npm run tsc` + Vitest |
| **Mobile renderer contract** (`components/renderer/**`) | the shared registry/types it reads; keep `frontendOnly` styles renderable | mobile `npm run typecheck && npm test` |
| **Plugin manifest compatibility** (`plugin.json`) | bump plugin `version`; align `compatibility.selfhelp` / `pluginApiVersion`; ship a migration on a MINOR | `selfhelp:plugin:doctor` + plugin certification |
| **Host CMS major or plugin API** (`config/services.yaml`) | every plugin's `compatibility` range; this matrix; the shared SDK if the SDK surface changed | `selfhelp:plugin:doctor` |

## Release process expectations

- **Publish order:** `@selfhelp/shared` first → then frontend/mobile that
  depend on the new version → then plugins that target the new host/SDK.
- **Never publish a backend response change without** (a) the matching shared
  type, (b) a passing `check:schemas`, and (c) green `ecosystem-compat`.
- **A breaking change** (shared major, host CMS major, or plugin API major)
  must land as a coordinated wave across the affected repos, each with its own
  changelog entry.
- Required per-repo gates still apply (see
  [`23-ci-quality-gate.md`](./23-ci-quality-gate.md) → "Required GitHub branch
  protection checks").

## Examples

**Compatible (additive) change**

- Backend adds an **optional** field to `user_data.json`.
- `@selfhelp/shared` adds the optional field to `IUserData` → **minor** `1.3.0`.
- Frontend/mobile keep working on `^1.2`; they adopt the field when convenient.
- `check:schemas` stays green (the new field is optional, not `required`).

**Incompatible (breaking) change**

- Backend renames `record_id → recordId` in `form_submitted.json` (`required`).
- `check:schemas` fails: `IFormSubmitData` no longer covers `recordId`.
- Resolution: shared **major** `2.0.0` with the rename, frontend + mobile
  adapt their form-submission code, plugins that read the field bump their
  `compatibility`, and all land together. `ecosystem-compat` must be green
  before publishing.

> Real precedent: the `form_submitted` contract drift (the shared type modeled
> `success`/`message` the backend never sends) was reconciled in shared by
> making `IFormSubmitData` mirror the real `FormController` response. The legacy
> fields are retained as deprecated optionals; `useFormSubmission.ts` in the
> frontend still reads `data.success`/`data.message`, which are always
> undefined and should be migrated.
