<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Cross-repo compatibility matrix

Audience: Developers and technical operators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-07-09.
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

> **Live Preview preference bridge (`@selfhelp/shared 1.15.3`, frontend
> `0.1.40` ⇄ mobile `0.1.19`):** the shared contract still carries colour
> scheme + optional locale, but the stable runtime deliberately splits their
> application. **Theme** syncs live in both directions with no reload.
> **Language** is web-driven and URL-bound: the frontend re-mints/remounts the
> mobile frame with the selected locale and the mobile app boots directly into
> it. This is a **frontend ⇄ mobile behavior fix, NOT a `/cms-api` change**:
> `@selfhelp/shared 1.15.3` provides the
> preview-bridge protocol (`src/types/preview-bridge.ts`) with two additive
> messages — `selfhelp-preview:set-preferences` (shell → frame) and
> `selfhelp-preview:preferences-changed` (frame → shell) — plus the
> `IPreviewPreferences` / `TPreviewColorScheme` payload types and their runtime
> guard. The frontend and mobile bridges normalize live preference messages to
> `{ colorScheme, locale: null }`; language never calls mobile `setLanguage()`,
> avoiding the scoped-token rotation + global query invalidation loop that
> stranded startup and emptied menu/tab data. **No backend, schema, route, or
> permission change**, so both `release-manifest.json` floors are **unchanged**
> (`supports.core >=0.1.21`, `supports.frontend >=0.1.30`);
> `@selfhelp/shared 1.15.3` remains the additive protocol anchor within both
> consumers' caret ranges.

> **Core 0.1.23 (immutable data-column `field_key` ⇄ frontend 0.1.48):** core
> `0.1.23` resolves host issue #56 — `data_cols` now stores an **immutable**
> `field_key` (the storage key, derived per data source — `section_<input id>`
> for core forms, `question.name` for SurveyJS — inheriting the table default
> collation) plus a **mutable** `display_name`, with a label-provenance FK
> `id_display_name_source` → `lookups` (`auto` | `manual`; NULL = `auto`) that
> prevents the next submission from overwriting an admin-curated label, all
> behind `UNIQUE (id_data_tables, field_key)`. This is a **breaking
> response-shape change the frontend consumes**: the admin columns endpoint
> returns `{ id, fieldKey, displayName }` (was `{ id, name }`), and the
> interpolation `data_variables` `token => label` map (the CMS variable picker
> shows and inserts the readable input name, never the opaque key) moved out of
> the cached `getSection` payload into its own `GET
> /cms-api/v1/admin/sections/{section_id}/data-variables` endpoint (permission
> `admin.page.read`, migration `Version20260629063147`) so a column added by a
> later submission appears without re-saving the section. A new
> `PATCH /cms-api/v1/admin/data/tables/{tableName}/columns/display-name`
> (permission `admin.data.update_columns`, migration `Version20260626121351`)
> curates labels without touching the storage key. **Frontend 0.1.48** reads the
> new column shape and fetches the token/label picker endpoint, so **both floors move in
> lockstep**: frontend `supports.core` `0.1.21 → 0.1.23` and backend
> `supports.frontend` `0.1.30 → 0.1.48`. The live pairing is now **frontend
> `>=0.1.48` ⇄ core `>=0.1.23`** (both `<0.2.0`). **No `@selfhelp/shared`
> change** — the affected admin-column + `data_variables` types live in the
> frontend, not the shared package, and **mobile does not consume them** (the
> mobile renderer submits by the field input key, which is unchanged), so neither
> is bumped. The matching SurveyJS plugin guard (block renaming/removing an
> answered `question.name` once responses exist) ships in plugin `0.3.4`.

> **Core 0.1.24 (display-name propagation + admin lock):** additive issue #56
> follow-up — renaming a form input propagates to its `section_<id>` column's
> auto label on save, `data_tables` gains the same `auto|manual` provenance FK
> (migration `Version20260629074004`) with a new
> `PATCH /cms-api/v1/admin/data/tables/{tableName}/display-name` (permission
> `admin.data.update_tables`, migration `Version20260629074552`), and a `locked`
> flag + form-section `data_table` inspector block are exposed. All additive, so
> backend `supports.frontend` stayed `>=0.1.48`; **frontend 0.1.49** adopted the
> lock UI and raised its `supports.core` `0.1.23 → 0.1.24`.
>
> **Core 0.1.25 (Interpolation v2: field_key tokens ⇄ frontend 0.1.52):** the
> `{{ }}` editor and the render-time `retrieved_data` scope now resolve data
> variables by the **immutable `field_key`** instead of the mutable input name.
> `DataVariableResolver` emits `scope.field_key => display_name` (the picker shows
> the label, inserts the key token), `SectionUtilityService` keeps the scope keyed
> by `field_key` (no name remap), and a `show-user-input` section's payload gains
> a `field_labels` (`field_key => display_name`) header map with `entries` kept
> keyed by `field_key`. This is a **breaking render/contract change the frontend
> consumes** (label chips that store `{{field_key}}` + show-user-input headers
> that default to `display_name`), so **both floors move in lockstep**: frontend
> `supports.core` `0.1.24 → 0.1.25` and backend `supports.frontend`
> `0.1.48 → 0.1.52`. The live pairing is now **frontend `>=0.1.52` ⇄ core
> `>=0.1.25`** (both `<0.2.0`). **No `@selfhelp/shared` change** — the chip/label
> round-trip + show-user-input header map live in the frontend; mobile submits by
> the input key (unchanged). No data migration ships; pre-release `{{scope.name}}`
> content is recreated against the new field_key picker.

> **Core 0.1.26 (unified interpolation picker + mail namespacing ⇄ frontend
> 0.1.53):** additive Interpolation v2 follow-up. A single context-aware endpoint
> `GET /cms-api/v1/admin/interpolation/variables` (route
> `admin_interpolation_variables_get`, permission `admin.page.read`, migration
> `Version20260629110606`) serves the `{{ }}` picker catalog for any context
> (`section` | `page` | `action` | `global`) via `InterpolationVariableService`
> over `DataVariableResolver`'s per-context catalogs; the seeded auth mail
> templates move onto the shared `system.*` namespace
> (`{{system.user_name}}`/`{{system.user_code}}` + one-time
> `{{system.special.activation_link|reset_link|platform_link}}`, with
> `MailTemplateService` mapping the flat caller vars onto that nested context and
> legacy flat tokens still resolving); the action picker offers
> `recipient.*`/`record.<field_key>`/`system.*`. **Frontend 0.1.53** adopts the
> unified endpoint across the page/config, action, data-config and custom-CSS/JSON
> surfaces (and the section hook delegates to it), so it requires the new route and
> raises its `supports.core` `0.1.25 → 0.1.26`. The route is additive and the
> legacy section data-variables route still exists (removed later in core
> `0.1.29` — see below), so backend `supports.frontend` stays `>=0.1.52`. The
> live pairing is now **frontend `>=0.1.53` ⇄ core
> `>=0.1.26`** (both `<0.2.0`). **No `@selfhelp/shared` change** — the picker
> wiring lives in the frontend. No data migration ships.

> **Core 0.1.27 (type-driven editors + standard columns ⇄ frontend 0.1.54):**
> the admin columns endpoint now **prepends** the always-present projection
> columns (`id`/`id_users`/`user_name`/`record_id`/`entry_date`/`id_users_deleted`,
> flagged `standard:true`/`id:null`/`locked:true`), and migration
> `Version20260629143116` retypes overloaded fields so the frontend editor is
> chosen purely from the field type (7 structured-config blobs `textarea → json`;
> `html_tag_content` `textarea → code`; the short message fields
> `error_text`/`empty_text`/`loading_text`/`confirm_message`/`delete_modal_body`
> `text → textarea`). Mail bodies also become WYSIWYG fragments rendered at send
> time by `MailHtmlRenderer` (internal; no API contract change). **Frontend
> 0.1.54** renders the standard columns + the type-driven editors (incl. the new
> `code` type) and raises its `supports.core` `0.1.26 → 0.1.27`; because a
> pre-0.1.54 frontend would mis-render the standard columns and the `code` type,
> backend `supports.frontend` is raised **in lockstep** `0.1.52 → 0.1.54`. The
> live pairing is now **frontend `>=0.1.54` ⇄ core `>=0.1.27`** (both `<0.2.0`).
> **No `@selfhelp/shared` change** — the field-type → editor mapping lives in the
> frontend.

> **Core 0.1.28 (rich-text content fields + field-catalog cleanup ⇄ frontend
> 0.1.55):** the free-form display fields `text` (shared by the `text` +
> `highlight` styles) and `blockquote_content` are retyped `markdown-inline →
> textarea` (migration `Version20260629153921`) so admins author them in the full
> rich-text editor (Enter, headings, lists, links, alignment); the field catalog
> is cleaned up (migration `Version20260629150730` unlinks the `multi_select_data`
> duplicate from `select` and deletes the dead `web_combobox_data`/`items`/`labels`
> fields, plus enriches structured-field help). **Frontend 0.1.55** renders the
> **block** structure of those fields (`TextStyle`/`BlockquoteStyle` render the
> authored headings/lists instead of flattening to inline) and ships the closable
> field-help popover with copyable JSON examples, so **both floors move in
> lockstep**: frontend `supports.core` `0.1.27 → 0.1.28` and backend
> `supports.frontend` `0.1.54 → 0.1.55`. The live pairing is now **frontend
> `>=0.1.55` ⇄ core `>=0.1.28`** (both `<0.2.0`). `@selfhelp/shared` `1.17.1` adds
> the optional `IShowUserInputStyle.field_labels` type (additive; `^1.17.x`
> consumers unaffected). The seeded auth mails also drop the duplicated raw-URL
> callout + raw-token link text (templates only).

> **Core 0.1.29 (remove the superseded per-section picker route — floor-neutral):**
> the legacy `GET /cms-api/v1/admin/sections/{section_id}/data-variables` route
> (added 0.1.23, permission `admin.page.read`) is removed — controller action,
> `AdminSectionService` wrapper, and `section_data_variables` schema deleted;
> migration `Version20260629170535` drops the `api_routes` row + its permission
> link (reversible). It was fully superseded by the unified
> `GET /cms-api/v1/admin/interpolation/variables` (0.1.26), which every frontend
> `>=0.1.53` already uses, so **no floor moves**: backend `supports.frontend`
> stays `>=0.1.55` and frontend `supports.core` stays `>=0.1.28`. The live pairing
> remains **frontend `>=0.1.55` ⇄ core `>=0.1.29`** (both `<0.2.0`). Separately,
> **mobile 0.1.30** adopts the show-user-input `field_labels` header contract
> (display names instead of the raw `field_key`); it degrades gracefully on older
> cores, so the `selfhelp-mobile-preview` `supports.core` stays `>=0.1.19`. **No
> `@selfhelp/shared` change.**

> **Core 0.1.30 (interpolation robustness — floor-neutral):** an internal fix so a
> non-scalar `{{ }}` token (e.g. `{{system.user_group}}`) renders as empty instead
> of fataling, and `\Throwable` is caught around the Mustache render. No API/route/
> schema change, so both floors are unchanged (`supports.frontend >=0.1.55`,
> `supports.core >=0.1.28`).

> **Core 0.1.31 (DB-driven public routing + CMS-in-CMS app builder ⇄ frontend
> 0.1.57):** issue #30 makes public page URLs **data**. A new `page_routes` table
> backs the open-access resolver `GET /cms-api/v1/pages/resolve` (full page ACL/
> publish/preview/data-access still applies — 404 on unauthorized), the page-content
> response gains `route_params` / `matched_url_pattern` / `canonical_url`, dynamic
> URL segments are exposed to interpolation as `{{route.<snake_case>}}` and folded
> into the page cache key, the reset/validate flows become parameterized seeded
> routes (`/reset` + `/reset/{user_id}/{token}`, `/validate/{user_id}/{token}`),
> pages gain a `page_surface` (`public`|`cms`) split, and the admin API adds the
> portable page-bundle **export/import** (`POST /admin/pages/export`,
> `GET /admin/pages/{id}/export/suggest`, `POST /admin/pages/import/validate`,
> `POST /admin/pages/import`) plus the list+detail **wizard**
> (`POST /admin/pages/cms-app`). **Frontend 0.1.57** switches its entire public
> routing to `/pages/resolve` (path → page) and reads `route_params` in the
> reset/validate styles, and ships the export/import + wizard admin UI, so **both
> floors move in lockstep**: frontend `supports.core` `0.1.28 → 0.1.31` and backend
> `supports.frontend` `0.1.55 → 0.1.57`. The live pairing is now **frontend
> `>=0.1.57` ⇄ core `>=0.1.31`** (both `<0.2.0`). `@selfhelp/shared` `1.18.0` adds
> the route-metadata types (`route_params`/`matched_url_pattern`/`canonical_url` on
> `IPageContent`, `IResolvePageResponse`) + the `PAGES.RESOLVE` endpoint (additive,
> `^1.x` consumers unaffected). **Mobile 0.1.31** resolves deep links by path
> (`pageService.resolvePageByPath`) and the validate screen reads `route_params`;
> it is not in the registry resolver's pairing, so its core coupling is enforced by
> shipping in the same wave (see "Mobile ⇄ core coupling").

> **Menu-builder navigation (breaking, core `0.1.32` / frontend `0.1.58` / mobile
> `0.1.32` wave):** replaces page-level `nav_position` / `footer_position` and
> `web_nav_render` / `mobile_nav_render` with first-class `navigation_menus` +
> `GET /cms-api/v1/navigation`, admin `/admin/navigation/*`, search routes, and
> last-visited startup keys. Floors move in lockstep: backend `supports.frontend`
> `>=0.1.58`, frontend `supports.core` `>=0.1.32`, mobile `supports.core`
> `>=0.1.32`. The cross-layer anchor is `@selfhelp/shared` **`1.21.0`** (menu
> payload types, `TWebHeaderPreset`, `searchMenuPagesInPayload`,
> `resolveMobileSegmentGroup` self-segment, `isOnAnyMobileMenuFromPayload`). The
> legacy `TWebNavRender` / `TMobileNavRender` types are no longer part of the
> active contract.

> **Navigation pages & page icons (superseded by menu-builder wave):** the earlier
> page-property model is absent from the final consolidated history. Existing
> page `icon` values are migrated to `navigation_menu_items.icon`; menu item
> `icon` / `mobile_icon` and membership are then owned entirely by the menu
> builder (`Version20260710093045`).

> **Core 0.1.33 (navigation overhaul: strict contract v2 ⇄ frontend 0.1.59 —
> breaking):** destructive pre-release cleanup of the menu-builder model into
> one strict final contract. Migrations `Version20260710092337` and
> `Version20260710093045` create and seed the final shape directly, including
> `navigation_menu_items.layer` (`'top'`/NULL — top header row for `web_header`
> root items on the double presets), drops the dead `id_child_source` /
> `auto_include_depth` columns and the `navigationChildSources` lookup type,
> carries `config.footer_layout` into `id_preset` (`columns` / `inline` join
> `navigationMenuPresets` — footer layout becomes a preset like the header) and
> has no `navigation_menus.config` column (the translation columns `description` /
> `aria_label` are created in the final table shape; the legacy `n` key
> only ever existed in bundle v1.0, not as a DB column). `GET /cms-api/v1/navigation` now emits the
> strict always-present item shape (`description`, `aria_label`, `layer`
> included; `null`, never a missing key) plus menu-level
> `preset`/`max_depth`/`item_limit`; the admin payloads mirror it (`layer` on
> item create/update/reorder, footer preset on menu update, translations with
> `label`/`description`/`aria_label`). The navigation bundle becomes
> `selfhelp/navigation-bundle` **v2.0**, the only accepted version — a v1.0
> bundle fails validation with a "re-export with the current version" error.
> **Frontend 0.1.59** adopts the strict payload (layered double-header
> rendering via `splitHeaderLayers`/`mergeHeaderLayers`, preset-keyed footer via
> the shared footer helpers, burger top-row section, admin layer sections +
> presentation fields + bundle v2.0 panel), so **both floors move in lockstep**:
> frontend `supports.core` `0.1.32 → 0.1.33` and backend `supports.frontend`
> `0.1.58 → 0.1.59`. The live pairing is now **frontend `>=0.1.59` ⇄ core
> `>=0.1.33`** (both `<0.2.0`). The cross-layer anchor is `@selfhelp/shared`
> **`1.21.5`** (breaking wave previously staged as shared **`2.0.0`**: strict `INavigationMenu`/`INavigationMenuItem`
> with `layer`/`description`/`aria_label` and no `config`, `TWebFooterPreset`,
> `headerLayers` + `footerPreset` + `activeTrail` helper modules, strict bundle
> v2.0 types); frontend and mobile must adopt `1.21.5` in the same wave.
> **Mobile 0.1.33** ships the matching adoption in the same wave (collapsible
> drawer with active-trail auto-expand via `expandedIdsForActiveTrail`, shared
> `isMenuItemActiveOnMobile` active states, tab `item_limit` slice, local
> duplicate helpers deleted); its preview-image `supports.core` floor moves
> `0.1.32 → 0.1.33` (see "Mobile ⇄ core coupling").
> The same 0.1.33 wave also ships the **child-page navigation presentation**
> contract (migration `Version20260710093045`): `navigationChildrenNavModes`
> lookups (`sidebar`/`pills`/`none`), menu-level `children_nav` default +
> `show_breadcrumbs` toggle, per-item `children_nav` override, emitted on web
> menus in `GET /navigation` and mirrored in the admin payloads + bundle v2.0.
> Frontend 0.1.59 renders it (sticky branch sidebar / pill strip / breadcrumbs
> / prev-next pager) via the `@selfhelp/shared` 1.21.5 `branchNav` resolver
> (`resolveWebBranchNavContext`; wave previously staged as shared 2.0.0), and the all-language content search + admin
> pages `title`/`titles` fields land in the same versions — no extra floor
> moves beyond the 0.1.33 ⇄ 0.1.59 pairing above.
> The wave's remaining additions are **additive inside the same pairing**:
> anonymous page-view analytics + the admin dashboard API (migrations
> `Version20260710092337` / `Version20260710093046`: `page_views`/`page_view_referrers` tables, the
> `admin.analytics.read` permission, `GET /admin/analytics/summary` +
> `GET /admin/analytics/today` consumed by the frontend 0.1.59 `/admin`
> dashboard, which hides the widgets without the permission), the branding
> `logo_size`/`logo_variant` columns on `navigation_settings` (emitted in the
> `branding` block, resolved by `@selfhelp/shared`
> `resolveBrandingPresentation` on web + mobile), and the
> `reset-password`/`validate`/`maintenance` system pages turning headless.
> An older frontend simply ignores all of it, so no further floor moves.

> **CMS-in-CMS polish wave (same core 0.1.33 ⇄ frontend 0.1.59 pairing —
> breaking style contract):** the list/detail feature ships polished in the same
> release pair. The **`show-user-input` style is renamed `entry-table`**
> (migration `Version20260710093048`; sections keep their `id_styles` FK) — a
> breaking render-contract change, so the cross-layer anchor moves to
> `@selfhelp/shared` **`1.21.5`** (breaking wave previously staged as shared 3.0.0: `IEntryTableStyle` /
> `IEntryTableEntry` replace the `IShowUserInput*` types, `STYLE_REGISTRY` key
> `entry-table`, `IEntryTableEntry._can_edit`, `IFormRecordStyle` gains
> `load_record_from` + `own_entries_only`); frontend and mobile adopt `1.21.5`
> in the same wave (renderers renamed `EntryTableStyle.tsx` / `EntryTable.tsx`).
> The same wave ships **record edit mode** (`form-record` `load_record_from`;
> `PUT /cms-api/v1/forms/update` accepts an explicit `record_id` under the
> shared `canUpdateOwnedRecord` rule with three ownership modes), the
> per-row `_can_edit` flag in the `entry-table` payload, **portable bundles**
> (`entry-table` `data_table` exported as `@section:<owner>` and relinked on
> import; prefixed imports rewrite in-bundle content links so demo copies stay
> clickable), and the **template gallery** (six curated CMS-in-CMS bundles;
> `GET /admin/pages/examples` emits `tags` for the frontend's one-click "Start
> from template" tab). Because the rename + edit mode land inside the SAME
> unreleased 0.1.33 ⇄ 0.1.59 pair, the `release-manifest.json` floors do not
> move again: frontend `supports.core` stays `>=0.1.33` and backend
> `supports.frontend` stays `>=0.1.59` (both `<0.2.0`). Mobile 0.1.33 adopts
> the rename in the same wave via `@selfhelp/shared` `1.21.5`.

> **Core 0.1.34 (first-class `cms_app` product unit ⇄ frontend 0.1.60 —
> breaking):** CMS-in-CMS apps become a first-class entity. Migrations
> `Version20260710092337` / `Version20260710093044` add the `cms_apps` table (hub FKs for form section +
> list/detail pages), `pages.id_cms_app` / `pages.cms_app_role` (strict roles
> `form` / `cms_list` / `cms_detail` / `public_list` / `public_detail` / `other`;
> primary roles unique per app), separate `admin.cms_app.*` permissions, and the
> `/admin/cms-apps` CRUD + assign/unassign + scaffold API. The legacy
> `POST /admin/pages/cms-app` wizard route is **removed** (pre-1.0, no alias).
> Hub FKs are written only by `CmsAppHubSyncService`; deleting an app shell
> unassigns pages without deleting pages/sections/tables/records. Portable
> bundles emit/consume a `cms_app` block + per-page `cms_app_role`.
> **Frontend 0.1.60** ships `/admin/cms-apps` index/detail, CMS Apps navbar
> accordion (Content Pages exclude assigned pages), empty-shell create + scaffold
> modals, and Manage content → `/cms/<app>`, so **both floors move in lockstep**:
> frontend `supports.core` `0.1.33 → 0.1.34` and backend `supports.frontend`
> `0.1.59 → 0.1.60`. The live pairing is now **frontend `>=0.1.60` ⇄ core
> `>=0.1.34`** (both `<0.2.0`). Host `selfhelp.cms_version` becomes `0.1.34`.
> `@selfhelp/shared` `1.21.5` adds the `ICmsApp*` / `TCmsAppRole` +
> `admin.cms_app.*` permission constants (additive within the same major).
>
> **User-owned enum options (additive inside the same unreleased pair):**
> core adds the `option_labels` field and runtime `_{field}_label(s)` hydration;
> frontend 0.1.60 and mobile 0.1.33 consume the canonical shared resolver, and
> the six CMS-in-CMS templates adopt stable codes. This lands before the
> 0.1.34 / 0.1.60 pair is tagged, so the manifest floors remain
> **frontend `>=0.1.60` ⇄ core `>=0.1.34`**. `@selfhelp/shared` remains 1.21.5
> with additive helpers in the same release wave.

> **Open-in-modal sizing + import viewer-groups (additive, same 0.1.31 / 0.1.57
> wave):** further additive issue #30 follow-up. Core adds two page-property
> fields `modal_width` + `modal_height` (migration `Version20260710093044`, page
> types `core` + `experiment`), projects them onto the single-page content
> response (`get_page.json` `page.modal_width|modal_height`, free CSS length /
> `auto` / null = default 80%), and accepts an additive `accessGroups` option on
> `POST /admin/pages/import` (`import_pages.json`) so imported pages are granted
> surface-appropriate viewer/editor ACL (admin always gets full access). The
> importer also realigns an imported page's `pages.url` to its (prefixed)
> canonical route so menu/admin links resolve through the DB router. All additions
> are backward-compatible (extra optional response fields + a new request option +
> an internal correctness fix), so **no `release-manifest.json` floor moves**:
> backend `supports.frontend` stays `>=0.1.57` and frontend `supports.core` stays
> `>=0.1.31`. The cross-layer anchor is `@selfhelp/shared` **`1.20.0`** (the
> optional `IPageContent.modal_width|modal_height` + the documented form/list
> modal-action contracts): frontend `0.1.57` (standardized `PageModal` with
> width/height/`auto` sizing + the global header menu rendered through the shared
> nav-render registry + the import viewer-groups multiselect) moves its
> `@selfhelp/shared` caret to `^1.20.0`; mobile `0.1.31` also moves to `^1.20.0`
> (the new fields are web-only, so mobile ignores them — no behavior change).

> **Core 0.1.35 / frontend 0.1.62 (CMS app entry binding + filter preview —
> breaking):** restores field-based `entry-list` / `entry-record` hydration
> (`data_table`, `filter`, `scope`, `own_entries_only`; explicit
> `{{route.record_id}}` in `filter` — `url_param` removed). Core adds
> `DataTableFilterService`, `POST /admin/data/query-preview` (`admin.data.read`),
> bundle import relink for `entry-table` `fields_map`, and
> `SectionAccessibleRouteService` so interpolation + query-preview expose route
> placeholders only from pages the caller can read. **Frontend 0.1.62** ships
> column mapper / fields-map editors, filter preview UI, and entry-record edit
> support. Floors move in lockstep: frontend `supports.core` `0.1.34 → 0.1.35`
> and backend `supports.frontend` `0.1.60 → 0.1.62`. *(Superseded for
> entry-record scoping by core 0.1.36 / frontend 0.1.63.)*

> **Core 0.1.36 / frontend 0.1.63 (entry-record `load_record_from` — breaking):**
> `entry-record` drops author SQL `filter` and uses the same visible
> **Load record from route parameter** field as `entry-record-form`. Floors:
> frontend `supports.core` `0.1.35 → 0.1.36`, backend `supports.frontend`
> `0.1.62 → 0.1.63`. Live pairing: **frontend `>=0.1.63` ⇄ core `>=0.1.36`**.
> `@selfhelp/shared` `1.21.5`.

## Current matrix (snapshot)

> Keep this table in sync when bumping any anchor version. The authoritative
> values live in each repo; this is a human-readable cross-check.

| Component | Version | Anchored to |
|-----------|---------|-------------|
| Host CMS (`selfhelp.cms_version`) | `0.1.36` | — |
| Host plugin API (`selfhelp.plugin_api_version`) | `0.1.0` | consumed by plugin `compatibility.pluginApi` |
| `@selfhelp/shared` | `1.21.5` | npm (DB-routing / CMS-apps / navigation / entry-binding wave: `entry-table` rename, `load_record_from`, `ICmsApp*`, option labels, strict nav v2 — see shared CHANGELOG v1.21.5) |
| `sh-selfhelp_frontend` | `0.1.63` | entry-record load_record_from (same as form); entry-table column mapper, filter preview UI |
| `sh-selfhelp_frontend` → `@selfhelp/shared` | `1.21.5` | strict menu payload + header layer + footer preset contract + entry-table style types + cms-app types |
| `sh-selfhelp_frontend` → core (`release-manifest.json` `supports.core`) | `>=0.1.36 <0.2.0` | query-preview endpoint, field-based entry binding, fields_map import relink |
| `sh-selfhelp_backend` → frontend (`release-manifest.json` `supports.frontend`) | `>=0.1.63 <0.2.0` | frontend adopts filter preview UI + column/fields-map editors |
| `selfhelp-mobile-preview` image (`sh-selfhelp_mobile`) | `0.1.20` | `0.1.20` pins the web-preview bottom tab bar + hides the desktop scrollbar in the embedded pane; floor-neutral |
| `selfhelp-mobile-preview` → core (`release-manifest.json` `supports.core`) | `>=0.1.36 <0.2.0` | entry-record load_record_from + field-based entry binding |
| `selfhelp-mobile-preview` `mobileRendererVersion` | `0.1.0` | the mobile renderer contract the image advertises; plugin `compatibility.mobile` ranges gate against it |
| `sh-selfhelp_mobile` | `0.1.33` | collapsible drawer with active-trail auto-expand, tab `item_limit` slice, shared active-state helpers; entry-table renderer rename |
| `sh-selfhelp_mobile` → `@selfhelp/shared` | `1.21.5` | strict menu payload + active-trail helpers + entry-table style types (pinned via `overrides` until the SurveyJS mobile package raises its peer range) |
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

## Navigation menu-builder wave (shipped 2026-07-01)

Coordinated refactor replaces page-level `nav_position` / `footer_position` and
per-page render modes with first-class navigation menus (`GET /cms-api/v1/navigation`,
admin `/admin/navigation/*`, search routes, last-visited).

| Repo | Version | Floor |
|------|---------|-------|
| `sh-selfhelp_backend` | `0.1.32` | `supports.frontend` `>=0.1.58` |
| `sh-selfhelp_frontend` | `0.1.58` | `supports.core` `>=0.1.32` |
| `sh-selfhelp_mobile` | `0.1.32` | `supports.core` `>=0.1.32` |
| `@selfhelp/shared` | `1.21.0` | `INavigationPayload`, `TWebHeaderPreset`, menu helpers |

## Navigation overhaul wave (strict contract v2, 2026-07-06)

Destructive follow-up: one strict final menu model (header layers, footer
presets, complete item payload with `description`/`aria_label`/`layer`, no
`config`) and the `selfhelp/navigation-bundle` v2.0 as the only accepted
import format.

| Repo | Version | Floor |
|------|---------|-------|
| `sh-selfhelp_backend` | `0.1.33` | `supports.frontend` `>=0.1.59` |
| `sh-selfhelp_frontend` | `0.1.59` | `supports.core` `>=0.1.33` |
| `sh-selfhelp_mobile` | `0.1.33` | `supports.core` `>=0.1.36` (package version stayed 0.1.33 across later core minors; pair via `supports.core` + shared 1.21.5) |
| `@selfhelp/shared` | `1.21.5` | strict `INavigationMenu`/`INavigationMenuItem`, `headerLayers`/`footerPreset`/`activeTrail`, bundle v2.0 (wave previously staged as 2.0.0-3.0.1) |

Working-tree implementations across all four repos are aligned to these floors.
Tag and publish together — partial deploy breaks navigation for migrated installs.

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
