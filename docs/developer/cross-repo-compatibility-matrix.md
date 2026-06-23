<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Cross-repo compatibility matrix

Audience: Developers and technical operators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-23.
Source of truth: Runtime code, configuration, migrations, and tests in this repository.

> For the end-to-end install/update/maintain picture (the Manager Docker path and
> the CMS plugin path that these versions feed), see
> [`../operations/platform-and-plugin-ecosystem.md`](../operations/platform-and-plugin-ecosystem.md).

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

## Frontend ⇄ core version pinning (`release-manifest.json`)

`@selfhelp/shared` catches *type/contract drift*, but it does **not** by itself stop
someone from running a frontend build against a backend that is too old (or too new)
for the `/cms-api` endpoints it calls. That version pairing is the **second
compatibility axis**, gated by a `release-manifest.json` at the root of each of the
two deployable repos:

| Repo | Field | Meaning |
|------|-------|---------|
| `sh-selfhelp_backend` | `supports.frontend` | the frontend SemVer range this core serves |
| `sh-selfhelp_frontend` | `supports.core` | the core (backend) SemVer range this frontend needs |

The unified registry's auto-release resolver
(`sh2-plugin-registry/scripts/resolve-core-candidate.mjs`) reads both manifests at the
git tag being released and runs a **bidirectional** `semver.satisfies` check before it
will assemble a signed core+frontend release candidate. A pair that fails either
direction is reported `incompatible` and never published, so a mismatched
frontend+backend can't be shipped to an instance.

During a **coordinated breaking wave** (both sides bump together) the published
counterpart is briefly the old, incompatible version. To avoid a chicken-and-egg
deadlock, the resolver then falls back to the newest *mutually compatible* counterpart
**git tag** (read from the counterpart repo's `release-manifest.json`), so tagging
backend and frontend in **either order** auto-stages both release PRs instead of
blocking. It still fails when the compatible counterpart has not been tagged at all
yet. Net effect for a coupled change: bump both `supports.*` floors, tag both repos,
merge the two staged PRs — no manual publish needed.

**These ranges are a hand-maintained contract — keep them honest.** Whenever a
frontend feature starts depending on a backend feature (a new / changed / removed
`/cms-api` endpoint, response field, permission, or behavior), update BOTH sides in
the same change wave:

- raise `sh-selfhelp_frontend` → `supports.core` to the first core version that ships
  the feature, **and**
- raise `sh-selfhelp_backend` → `supports.frontend` to the first frontend version that
  adopts it.

> **Current floor (2026-06-23):** the **core 0.1.17** style-schema contract
> (built up across the 0.1.15 → 0.1.17 style waves; `selfhelp.cms_version` was
> caught up from `0.1.15` to the changelog's latest `0.1.17` in this change) — the
> mobile-rendering taxonomy (style `renderTarget`, a required field `scope` on
> every style-schema field that drives the frontend inspector grouping, and the
> shared_/web_ field split with the duplicate `pages.id_platform` removed in
> favour of the existing page access type), the alert/badge/avatar/button/login
> authoring-polish wave (migration `Version20260619131830`), **and** the
> field-naming unification (migration `Version20260622165615`): the `shared_`
> prefix is dropped from 47 style props (`shared_color` → `color`,
> `shared_variant` → `variant`, `shared_radius` → `radius`, …;
> `shared_height`/`shared_width`/`shared_icon` are kept as reserved-name
> exceptions). All of this is part of the current **core 0.1.17** and was first
> adopted by **frontend 0.1.29**. Because this core emits the unprefixed names, a
> 0.1.23–0.1.28 frontend (which still reads `shared_*`) is an incompatible mix —
> an **old frontend that still reads `shared_*` must not be paired with this
> backend** — so the frontend floor moved up from 0.1.23 to 0.1.29, then to
> **0.1.30** for the core 0.1.18 anonymous-preview adaptation (see the next
> note). The live pairing is now **frontend `>=0.1.30` ⇄ core `>=0.1.17`** (both
> still `<0.2.0`); anything older on the frontend side is an incompatible mix.
> `@selfhelp/shared` (the prefix drop landed in `1.14.22`; this branch resolves
> the caret to `1.14.24`, carrying the unprefixed `I*Style` field contracts)
> stays the type anchor on top of this version gate.
>
> **Core 0.1.18 (anonymous-access hardening + page-route cleanup):** core `0.1.18`
> fixes the anonymous-as-admin ACL bug (guest sentinel id 0), **requires auth for
> `preview=true`** (an anonymous draft request is now `401`), stores anonymous
> form submissions un-owned, and **removes the duplicate
> `GET /cms-api/v1/pages/{page_id}` route** (single page content is resolved only
> by `GET /cms-api/v1/pages/by-keyword/{keyword}`).
>
> The page-route cleanup is client-transparent (no frontend/mobile/shared code
> referenced the by-id route — the frontend `api.config.ts` only defines
> `/pages`, `/pages/language/{id}`, `/pages/by-keyword/{keyword}`). **The
> anonymous-preview 401 is NOT client-transparent**, contrary to an earlier note
> here: the long-lived, admin-set preview flag (`sh_preview` on web, the dev
> preview toggle on mobile) can outlive a session, so an anonymous render kept
> requesting the draft and 401-looped the public site. Both clients adapt —
> **frontend 0.1.30** gates SSR preview on a live session cookie and clears
> `sh_preview` on logout/expiry (`resolvePreviewSSR` + `clearAuthCookies`), and
> **mobile 0.1.10** ships the matching `services/previewPolicy.ts` gate. The
> backend therefore raised **`supports.frontend` from `>=0.1.29` to `>=0.1.30`**
> (frontend `supports.core` stays `>=0.1.17`: the guard is backward-safe on older
> cores). Mobile is not in the registry resolver's pairing (it has no
> `release-manifest.json`), so its coupling to core `0.1.18` is enforced by
> shipping `0.1.10` together, not by an automated gate — see "Mobile ⇄ core
> coupling" below.
>
> **Core 0.1.19 (mobile preview service):** core `0.1.19` adds the mobile-preview
> session API — admin mint `POST /cms-api/v1/admin/mobile-preview/session`
> (permission `admin.mobile_preview.create`) + public exchange
> `POST /cms-api/v1/mobile-preview/session/exchange` + the
> `MobilePreviewAccessGuard` read allowlist for `purpose: 'mobile_preview'`
> tokens — plus an additive `compatibility.mobile` axis in the plugin manifest
> schema. **Frontend 0.1.31** adopts it: the page-editor Mobile Preview panel
> mints a one-time code through the protected BFF route and embeds the
> `selfhelp-mobile-preview` image, so the frontend `supports.core` floor rises
> `0.1.17` → `0.1.19`. The backend `supports.frontend` floor is **unchanged**
> (`>=0.1.30`): the endpoints are additive and the panel is optional, so the core
> does not require the panel-bearing frontend. The `selfhelp-mobile-preview`
> image (`sh-selfhelp_mobile` `0.1.11`) carries its own `release-manifest.json`
> with `supports.core >=0.1.19` and advertises a `mobileRendererVersion` (`0.1.0`)
> that the manager's dual-axis plugin gate checks each installed plugin's
> `compatibility.mobile` range against. Like mobile↔core, the preview image is not
> yet in the registry resolver's frontend⇄core pairing, so its core coupling is
> enforced by shipping it in the same wave (and by the manager's own resolver),
> not by the auto-release gate. **Manager `1.6.5` provisions the preview by
> default on every install** (resolving the newest core-compatible image; the
> install still succeeds without it when none is published yet) and treats
> `update-mobile-preview` as the enable/bootstrap path for instances that predate
> a compatible image. The dual-axis gate's RN/Expo axis reads the descriptor's
> **top-level** `reactNativeVersion` / `expoSdkVersion`; `@selfhelp/shared`
> `1.14.26` promotes those to top-level (with `PluginRelease.compatibility.reactNative`
> / `expoSdk`), the mobile CI emits them top-level, and the registry assembler
> falls back to `builtFrom.*` so the manual publish path can never drop them.

> **Core 0.1.20 (CMS-driven mobile-preview install/update):** core `0.1.20` makes
> the optional `selfhelp-mobile-preview` image installable/updatable **from the
> CMS**, mirroring the frontend-only update lane. It adds the
> `mobile_preview_version` field to `GET /admin/system/version` and three additive
> admin routes — `GET …/update/mobile-preview/releases`,
> `GET …/update/mobile-preview/preflight`, `POST …/update/mobile-preview/request`
> (reads reuse `admin.system.read`, the request reuses `admin.system.update`) —
> plus the `mobile-preview` `SystemUpdateOperation` kind and a
> `target_mobile_preview_version` column (migration `Version20260623180726`). The
> preflight applies the preview ⇄ core gate (the target preview's
> `backendCompatibility.requiredCoreRange` must admit the running core; an
> unreadable signed release degrades to a warning, never a fabricated block) and
> treats a not-installed instance (`unknown` current) as the enable/bootstrap
> path. **Frontend 0.1.32** adopts the lane and reads the new field, so the
> frontend `supports.core` floor rises `0.1.19` → `0.1.20`. The backend
> `supports.frontend` floor is **unchanged** (`>=0.1.30`): the endpoints + field
> are additive and the preview-update UI is optional, so the core does not require
> the newer frontend. `@selfhelp/shared` `1.14.26` carries the matching contract
> (`TUpdateKind` adds `mobile-preview`; `IMobilePreviewUpdate*`;
> `ISystemVersion.mobile_preview_version`; `IUpdateStatus.target_mobile_preview_version`).
> **The SelfHelp Manager** dispatches the `mobile-preview` operation kind through
> the same CMS operation loop it already uses for `core`/`frontend` (reusing
> `instanceMobilePreviewUpdate`), so a CMS-requested preview install/update is
> executed and rolled-back (on a failed health check) exactly like the CLI path.

> **Core 0.1.21 (CMS Live Preview entitlement):** core `0.1.21` seeds the
> `admin.mobile_preview.view` permission (migration `Version20260623193630`,
> granted to the `admin` role). It is the dedicated entitlement for the
> frontend's full-screen **Live Preview** surface — a new-tab, free-navigation
> mobile/web preview to test the real flow — kept SEPARATE from
> `admin.mobile_preview.create` (the one-time-code mint). The change is
> **additive**: no new `api_route` and no schema change (the live preview reuses
> the existing admin mint + public exchange routes); the permission only surfaces
> in the admin user-data `permissions[]`. The free-navigation behaviour needs no
> backend change either — the mint request schema has no required fields and
> `MobilePreviewAccessGuard` only pins to one keyword when a `keyword`/`page_id`
> scope is bound, so a keyword-less mint already renders any page (GET-only,
> read-only allowlist). **Frontend 0.1.33** adopts the live preview and gates it
> on the new permission, so the frontend `supports.core` floor rises
> `0.1.20` → `0.1.21`. The backend `supports.frontend` floor is **unchanged**
> (`>=0.1.30`): the permission is additive and the UI is optional, so the core
> does not require the newer frontend. No `@selfhelp/shared` change (the
> permission string is a frontend constant; the mobile off-menu modal flag is a
> local embed-contract param).

## Current matrix (snapshot)

> Keep this table in sync when bumping any anchor version. The authoritative
> values live in each repo; this is a human-readable cross-check.

| Component | Version | Anchored to |
|-----------|---------|-------------|
| Host CMS (`selfhelp.cms_version`) | `0.1.21` | — |
| Host plugin API (`selfhelp.plugin_api_version`) | `0.1.0` | consumed by plugin `compatibility.pluginApi` |
| `@selfhelp/shared` | `1.14.26` | npm (1.14.26 adds the CMS-driven mobile-preview update contract — `TUpdateKind` `mobile-preview`, `IMobilePreviewUpdate*` types, `ISystemVersion.mobile_preview_version`, `IUpdateStatus.target_mobile_preview_version` — and promotes `reactNativeVersion`/`expoSdkVersion` to **top-level** on `MobilePreviewRelease` + `PluginRelease.compatibility.reactNative`/`expoSdk`; all additive, `^1.14.25` consumers unaffected; 1.14.25 added the mobile preview-session contracts + `MOBILE_RENDERER_VERSION` / `isMobileRendererCompatible()` + the `mobile-preview-release` types; 1.14.22 dropped the `shared_` field-name prefix paired with migration `Version20260622165615`; 1.14.24 removed `frontendOnly` from registry entries) |
| `sh-selfhelp_frontend` | `0.1.33` | — |
| `sh-selfhelp_frontend` → `@selfhelp/shared` | `^1.15.1` | shared `1.x` line (mobile preview-session + preview-update types) |
| `sh-selfhelp_frontend` → core (`release-manifest.json` `supports.core`) | `>=0.1.21 <0.2.0` | raised `0.1.20` → `0.1.21`: the full-screen Live Preview surface gates on the `admin.mobile_preview.view` permission first seeded in core `0.1.21` (the UI hides for users without it, but the version contract tracks the dependency) |
| `sh-selfhelp_backend` → frontend (`release-manifest.json` `supports.frontend`) | `>=0.1.30 <0.2.0` | unchanged: the mobile-preview session, update endpoints **and** the `admin.mobile_preview.view` permission are additive and do NOT require the frontend panel/UI; still tracks the 0.1.18 anonymous-preview adaptation |
| `selfhelp-mobile-preview` image (`sh-selfhelp_mobile`) | `0.1.12` | — |
| `selfhelp-mobile-preview` → core (`release-manifest.json` `supports.core`) | `>=0.1.19 <0.2.0` | requires the core mobile-preview session endpoints + `MobilePreviewAccessGuard` read allowlist (`0.1.19`); the off-menu modal preview is a local embed-contract param needing no core change |
| `selfhelp-mobile-preview` `mobileRendererVersion` | `0.1.0` | the mobile renderer contract the image advertises; plugin `compatibility.mobile` ranges gate against it |
| `sh-selfhelp_mobile` → `@selfhelp/shared` | `^1.14.25` | shared `1.x` line (mobile UI adapter + preview contracts) |
| `sh-manager` (tool) | `1.6.6` | installs/routes/updates the mobile-preview service; **provisions it by default on every install** (auxiliary — a registry with no compatible preview does not fail the install) and bootstraps it via `update-mobile-preview`; runs the dual-axis plugin mobile gate (RN/Expo read from the descriptor's top-level `reactNativeVersion`/`expoSdkVersion`) |
| `sh2-shp-survey-js` (`compatibility.selfhelp`) | `>=0.1.0 <0.2.0` | host CMS minor `0.1` |
| `sh2-shp-survey-js` (`pluginApiVersion`) | `0.1.0` | host plugin API `0.1.0` |
| `sh2-shp-survey-js` (`compatibility.mobile`) | `^0.1.0` | mobile renderer contract axis (gated vs the preview image's `mobileRendererVersion`) |
| `sh2-shp-survey-js` runtime targets | `react ^19`, `node ^22`, `reactNative ^0.83`, `expoSdk ^55` | client runtimes |

> **Pre-1.0 SemVer.** SelfHelp core, the plugin API, and plugins are in the `0.x`
> series, where **every MINOR bump is breaking**. A `compatibility.selfhelp` range
> therefore tracks one core MINOR (`>=0.1.0 <0.2.0`), not one MAJOR. `@selfhelp/shared`
> is the exception: it is a published SDK already on the `1.x` line, so the standard
> "MAJOR = breaking" SemVer applies to it.

> **Mobile ⇄ core coupling (no automated gate).** The registry resolver's
> bidirectional `release-manifest.json` gate pairs **only frontend ⇄ core** — the
> mobile app ships no `release-manifest.json` and is not assembled by the resolver,
> so the `@selfhelp/shared` caret range is currently mobile's only machine-checked
> compatibility anchor. That anchor catches *typed response-shape* drift, **not
> version pairing or behavioral coupling**: e.g. the core 0.1.18 anonymous-preview
> 401 needed mobile 0.1.10 (`services/previewPolicy.ts`) even though no shared type
> changed. Until a mobile manifest + resolver support exist, **a mobile↔core
> behavioral coupling MUST be shipped as a coordinated release** (bump mobile in the
> same wave as the core change and note it here), and an old mobile build against a
> newer core is not blocked automatically. Adding `supports.core` to a mobile
> `release-manifest.json` (and teaching the resolver to read it) is the tracked
> follow-up that would turn this convention into a gate.

## What the plugin `compatibility` block means

```jsonc
"compatibility": {
  "selfhelp": ">=0.1.0 <0.2.0",   // host CMS SemVer range (pre-1.0: each MINOR is breaking)
  "php": "^8.4",
  "node": "^22",
  "react": "^19",
  "reactNative": "^0.83",         // RN runtime the native bundle targets
  "expoSdk": "^55",               // Expo SDK the native bundle targets
  "mobile": "^0.1.0"              // mobile RENDERER contract (vs the preview image's mobileRendererVersion)
}
```

The host enforces `selfhelp` and `pluginApi` ranges at install, at boot, and in
`selfhelp:plugin:doctor`. Full severity rules are in
[`versioning-and-compatibility.md`](../plugins/versioning-and-compatibility.md).
The remaining runtime targets (`node`, `react`, `reactNative`, `expoSdk`) tell
the frontend/mobile build whether the plugin's ESM/native bundle is loadable in
the current client. **`compatibility.mobile`** is the **dual-axis mobile gate**:
`reactNative` / `expoSdk` describe the *runtime* the native bundle needs, while
`mobile` is the SelfHelp *renderer contract* the plugin's native components
target. The manager checks an installed plugin's `compatibility.mobile` range
against the `selfhelp-mobile-preview` image's advertised `mobileRendererVersion`
and **blocks** an incompatible plugin, **warns** when a plugin is not in the
image's bundled set (it then falls back to the in-preview "open on web"
deep-link), and **informs** for web-only plugins that declare no `mobile` axis.

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
| **A `/cms-api` endpoint or behavior a client depends on** (added / changed / removed route, permission, or contract) | raise the `release-manifest.json` floors on BOTH sides — `supports.core` (frontend) and `supports.frontend` (backend) — to the new compatible pair, in the same change wave; update this matrix's "Current floor" note | `resolve-core-candidate.mjs` reports the intended pair as compatible (not `incompatible`) |
| **Shared DTO / exported type** | bump `@selfhelp/shared` (minor if additive, major if breaking); adapt frontend + mobile; update the CHANGELOG | `npm run typecheck && npm test` (shared) + `ecosystem-compat` |
| **Frontend renderer contract** (style impl map vs registry) | the shared style registry if a style was added/removed | frontend `npm run tsc` + Vitest |
| **Mobile renderer contract** (`components/renderer/**`) | the shared registry/types it reads; keep every registry style renderable | mobile `npm run typecheck && npm test` |
| **Mobile plugin-rendering contract** (a plugin's native components / the bundled-plugin set in `selfhelp-mobile-preview`) | bump `@selfhelp/shared` `MOBILE_RENDERER_VERSION` (minor if additive, major if breaking); re-snapshot `web-preview/preview-plugins.json` + the image's `bundledPlugins` / `mobileRendererVersion`; bump each affected plugin's `compatibility.mobile` | manager dual-axis plugin gate dry-run + `npm run typecheck && npm test` (shared) |
| **Plugin manifest compatibility** (`plugin.json`) | bump plugin `version`; align `compatibility.selfhelp` / `pluginApiVersion` (and `compatibility.mobile` if its native bundle changed); ship a migration on a MINOR | `selfhelp:plugin:doctor` + plugin certification |
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
