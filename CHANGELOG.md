# v0.1.30

## Fix: page render crash on array-valued interpolation tokens (issue #56 v2)

Rendering a page whose content referenced a `{{ }}` token that resolves to a
non-scalar value crashed the entire request with a fatal
`TypeError: htmlspecialchars(): Argument #1 ($string) must be of type string,
array given`. The most common trigger is `{{system.user_group}}` — the current
user's groups are exposed under the `system` namespace as a list. Mustache
compiles templates to `eval()`'d PHP that calls `htmlspecialchars()` on the
resolved value, and that `TypeError` is an `\Error` (not an `\Exception`), so it
bypassed `InterpolationService`'s catch and returned a 500.

- **Array-safe escaper.** `InterpolationService` now configures the Mustache
  engine with a guarded `escape` callback: scalar values keep the default HTML
  escaping (`ENT_COMPAT` / UTF-8), while non-scalar values render as an empty
  string instead of fataling. Arrays remain usable as Mustache sections
  (`{{#system.user_group}}…{{/system.user_group}}`); only their scalar `{{ }}`
  output is neutralised.
- **Catch `\Throwable`.** `performInterpolation()` and `renderTemplate()` now
  catch `\Throwable` (not just `\Exception`), so any future error in the eval'd
  template degrades to the original content instead of a 500.
- Internal robustness fix only — no API route, schema, or response-shape change.
  `supports.frontend` is unchanged (`>=0.1.55`).

# v0.1.29

## Remove the superseded per-section interpolation-picker endpoint (issue #56 v2)

Interpolation v2 (core `0.1.26`) replaced every per-surface variable picker with
the single context-aware endpoint
`GET /cms-api/v1/admin/interpolation/variables`. The frontend's
`useSectionDataVariables` has delegated to it since frontend `0.1.53`, so the
older per-section route had no remaining consumer.

- **Removed `GET /cms-api/v1/admin/sections/{section_id}/data-variables`.** The
  controller action (`AdminSectionController::getSectionDataVariables`), the thin
  service wrapper (`AdminSectionService::getSectionDataVariables`, plus its now
  unused `DataVariableResolver` dependency), and the
  `responses/admin/sections/section_data_variables` schema are gone. Migration
  `Version20260629170535` drops the `admin_sections_data_variables_get`
  `api_routes` row and its `admin.page.read` link (reversible: `down()` restores
  the route + permission as `Version20260629063147` seeded them). The unified
  endpoint still serves the section catalog via
  `InterpolationVariableService` → `DataVariableResolver::getSectionContextVariables`.
- **Floor-neutral.** `supports.frontend` stays `>=0.1.55`: every frontend in the
  supported window already fetches the unified endpoint, so no supported client
  breaks. No `@selfhelp/shared` change.

# v0.1.28

## Rich-text content fields, field-catalog cleanup, and cleaner auth mails (issue #56)

A follow-up on the type-driven editor wave that turns the free-form display-copy
fields into full rich text, removes duplicate/dead field definitions, and tidies
the seeded transactional mails. Coordinated with frontend `0.1.55`;
`supports.frontend` is raised to `>=0.1.55` because that frontend renders the
block structure of the retyped fields.

- **Free-form content fields become rich text.** Migration
  `Version20260629153921` retypes the `text` field (shared by the `text` and
  `highlight` styles) and `blockquote_content` from `markdown-inline` →
  `textarea`, so they open in the full WYSIWYG editor (Enter for multiline plus
  headings, lists, links, alignment) with `{{ }}` interpolation. Data-only change
  (no content touched, fully reversible); `highlight` renders its content as
  plain text so the richer editor degrades gracefully there.
- **Field-catalog cleanup.** Migration `Version20260629150730` unlinks the
  duplicate `multi_select_data` field from the `select` style (the renderer reads
  `options`) and deletes it plus the unused `web_combobox_data`, `items` and
  `labels` fields, and enriches the help/examples for `fields_map`, `loop` and
  `web_carousel_embla_options`.
- **Cleaner seeded auth mails.** The confirmation and recovery bodies drop the
  duplicated "copy this URL" callout that exposed the raw
  `{{system.special.*_link}}` token, and the password-changed body now links via a
  labelled button instead of showing the raw token as the link text — the action
  link is carried by the styled button only. Templates-only change
  (`templates/emails/*.html`), reapplied on the next reseed; no API contract
  change. See `docs/reference/email-styles.md`.

# v0.1.27

## Type-driven CMS editors, WYSIWYG email rendering, and data-config standard columns (issue #56)

A follow-up wave on top of Interpolation v2 that makes the CMS editor choice
**type-driven**, renders transactional mail from rich-text fragments, and surfaces
every projection column in the data-config builder. Coordinated with frontend
`0.1.54`; `supports.frontend` is raised to `>=0.1.54` because the columns response
and the new field types are consumed by that frontend.

- **WYSIWYG email rendering.** Transactional mail bodies (`sh-mail-config`) are now
  authored as rich-text **fragments**, not full HTML documents. At send time
  `JobSchedulerService::sendEmail()` passes the HTML body through the new
  `App\Service\Auth\MailHtmlRenderer`, which inlines email-safe CSS (base tag
  styles + the named `email-*` style presets, forcing link colour inside
  button/strong-link presets) and wraps the content in the shared branded shell.
  Plain-text mails and legacy full-HTML documents are passed through untouched.
  Migration `Version20260629131426` backfills the seeded full-document bodies to
  the fragment form. See `docs/reference/email-styles.md`.
- **Field-type cleanup for the type-driven editor.** Migration
  `Version20260629143116` retypes overloaded fields so the editor (chosen purely
  from the field type) matches how each field is authored: 7 structured-config
  blobs (`web_combobox_data`, `multi_select_data`, `segmented_control_data`,
  `slider_marks_values`, `range_slider_marks_values`, `web_color_picker_swatches`,
  `web_datepicker_time_grid_config`) `textarea → json`; `html_tag_content`
  `textarea → code` (raw-HTML Monaco editor); and the short message fields
  (`error_text`, `empty_text`, `loading_text`, `confirm_message`,
  `delete_modal_body`) `text → textarea` so they get the rich multiline editor.
- **Data-config surfaces the standard projection columns.** The admin columns
  endpoint now prepends the always-present row columns (`id`, `id_users`,
  `user_name`, `record_id`, `entry_date`, `id_users_deleted`) flagged
  `standard: true` / `id: null` / `locked: true`, so the data-config builder lists
  them like any other column (filter/order by `record_id`, `entry_date`, …) while
  the Data browser editor excludes them from relabel/delete.
- **Scheduled action emails interpolate `record.*` and `system.*`.** Action email
  subject/body now resolve `recipient.*`, `record.<field_key>` (from the action's
  data table) and `system.*` tokens at execution time.
- **Mobile-preview sessions can submit core forms.** Form submission is no longer
  blocked for an authenticated mobile-preview session, so previewing an admin can
  exercise forms (record + log) end to end.
- **Page interpolation picker is honest.** The page/config picker only offers
  variables for fields that actually render interpolation.
- **Tests / docs.** Added migration round-trip tests for the recent migrations and
  documented the type-driven editor mapping, email styles, interpolation, and data
  naming (developer + non-technical user guides).

# v0.1.26

## Interpolation v2: unified context-aware variable picker + mail namespacing (issue #56)

A single, modular interpolation `{{ }}` system now powers **every** CMS text
surface. One context-aware endpoint serves the variable catalog, the seeded mail
templates move onto the shared `system.*` namespace, and the picker reaches the
page/config, action, data-config and custom-CSS/JSON editors. This is an
**additive** core change (a new read-only route + internal mail-content rewrite),
coordinated with frontend `0.1.53`; older frontends keep working via the existing
section data-variables route.

- **One unified picker endpoint.** New `GET /cms-api/v1/admin/interpolation/variables`
  (route `admin_interpolation_variables_get`, permission `admin.page.read`,
  migration `Version20260629110606`) returns the `token => display_name` catalog
  for a `context` (`section` | `page` | `action` | `global`) + optional `id`.
  `InterpolationVariableService` orchestrates it over `DataVariableResolver`, which
  gained context catalogs: `getSectionContextVariables` (data_config columns +
  system + globals), `getPageContextVariables` (system + globals — and the
  mail-config page gets `getMailContextVariables`), `getActionContextVariables`
  (`recipient.*` + `record.<field_key>` from the action's data table + `system.*`),
  and `getGlobalContextVariables`.
- **Mail templates use the shared `system.*` namespace.** The seeded auth mail
  bodies now interpolate `{{system.user_name}}`, `{{system.user_code}}` and the
  one-time links `{{system.special.activation_link}}` /
  `{{system.special.reset_link}}` / `{{system.special.platform_link}}` so the
  mail-config picker offers exactly what renders. `MailTemplateService` maps the
  flat caller vars (`user_name`, `code`, `validation_url`, …) onto that nested
  context (and exposes `globals.*`), keeping the auth flow working; the legacy
  flat tokens still resolve as a safety net. `MailTemplateDefaults` placeholders +
  help text and the `templates/emails/*.html` files were rewritten to match.
- **Action picker mirrors the action template scopes.** The action subject/body
  editors offer `recipient.*`, `record.<field_key>` (from the action's selected
  data table) and `system.*` — the exact namespaces `ActionTemplateContextBuilder`
  documents — and intentionally not globals.

No data migration ships; pre-release content is recreated against the new picker.

# v0.1.25

## Interpolation v2: field_key tokens + display_name labels (issue #56)

The platform's `{{ }}` interpolation now resolves data variables by the
immutable `field_key` instead of the mutable input *name*, so renaming a form
input (or curating a column's `display_name`) never breaks authored content. The
editor shows the human `display_name`; the stored token is the rename-safe
`{{scope.<field_key>}}`. This is a **breaking render/contract change** consumed
by the frontend (token map + show-user-input headers), coordinated with frontend
`0.1.52`.

- **Picker tokens are the immutable `field_key`.** `DataVariableResolver` now
  emits `scope.<field_key>` tokens (core forms: `section_<input id>`; SurveyJS:
  `question.name`) with the curated `display_name` as the label (falling back to
  the field_key). It no longer remaps tokens to the current input name, so the
  `data-variables` map a section returns is `{{scope.field_key}} => display_name`.
- **Render scope keys by `field_key`.** `SectionUtilityService::fetchData` keeps
  the interpolation `retrieved_data` scope keyed by the storage `field_key`
  (the name remap was removed). `{{scope.<field_key>}}` in `content`, `css`,
  conditions and data-config projections now resolves directly — a later rename
  only moves the label. data_config `fields[].field_name` is the `field_key`.
- **show-user-input ships a header map.** A show-user-input section's payload now
  carries `field_labels` (`field_key => display_name`) alongside `entries`
  (kept keyed by `field_key`). The renderer defaults column headers to the
  curated `display_name` while reading cells by the stable key. Backed by the new
  `DataService::getColumnDisplayLabels()` (cached per table, busted on column
  changes). Standard projection columns (`record_id`, `entry_date`, …) keep
  their own key as the header.
- **Form submit/prefill and the Data browser are unchanged.** Form save still
  maps submitted input names to `section_<id>` storage keys; prefill still maps
  back to input names (the renderer binds inputs by name); the admin Data browser
  keeps its existing display. Only the interpolation scope, picker tokens and
  show-user-input headers switched to the `field_key` contract.

No data migration is shipped — pre-release content that used `{{scope.<name>}}`
tokens is recreated against the new `{{scope.<field_key>}}` picker.

# v0.1.24

## Data-column / data-table display-name propagation + admin lock (issue #56)

Follow-up to the immutable `field_key` work (v0.1.23). Renaming a CMS form input
now keeps its stored column label in sync, an admin can manually rename + lock
both columns and whole data tables so submissions/saves never overwrite the
curated label, and the lock state is surfaced everywhere it matters.

- **Renaming a form input updates its column label on Save.** When a form input
  section is saved, its `name` field now propagates to the auto `display_name`
  of the immutable `section_<id>` column — the same way renaming a form
  propagates to its data table. Wired through `SectionFieldService` →
  `DataColumnService::renameAutoColumnByFieldKey()`; a no-op when no column
  exists yet (no submission) or the label is manually locked. The affected
  table's `DATA_TABLES` caches (column list + variable picker) are busted so the
  new label shows immediately.
- **Data tables get the same auto/manual provenance as columns.** New nullable
  FK `data_tables.id_display_name_source` → `lookups` (reusing the
  `dataColDisplayNameSource` `auto` | `manual` group; NULL = `auto`), added by
  migration `Version20260629074004`. While `auto`, the form section's `name`
  keeps syncing the table label; once an admin renames the table it flips to
  `manual` and section saves never overwrite it again
  (`DataTableService::updateDataTableDisplayName()` is now gated on the source).
- **Manual table rename + reset-to-auto.** New
  `PATCH /cms-api/v1/admin/data/tables/{tableName}/display-name` (permission
  `admin.data.update_tables`, seeded by migration `Version20260629074552`):
  a non-empty `displayName` sets the label and locks it (`manual`); `null`/empty
  resets to `auto` and re-derives the label from the owning form section's
  `name`. Columns gained the symmetric behaviour: clearing a column label now
  re-derives the auto label from the input section's `name` instead of leaving
  the opaque `section_<id>` key.
- **Lock state is exposed everywhere.** `locked` is added to the admin tables
  list (`GET /admin/data/tables`), the columns list
  (`GET /admin/data/tables/{tableName}/columns`) and `getDataTableStats()`; and
  a form section's `getSection` payload now carries a `data_table`
  `{ id, name, display_name, locked }` block (only for form sections, only when
  a table exists) so the CMS section inspector can show the effective table
  name, a "locked" badge, and a deep link to the Data browser.

# v0.1.23

## Immutable data-column `field_key` + mutable `display_name` (issue #56)

- **Core form/SurveyJS data is now stored by an immutable key, not a mutable
  label.** `data_cols.name` was both the storage identity and the admin label,
  so renaming a column forked one logical field into two `data_cols` rows and
  split historical from new submissions. `data_cols` now has an immutable
  `field_key` (the storage key, an opaque ASCII identifier **derived per data
  source** — `section_<input section id>` for core CMS forms, `question.name`
  for SurveyJS — so a renamed input keeps writing into the SAME column; it
  inherits the table default collation, no per-column override), a mutable
  `display_name` (the human label), and a label-provenance FK
  `id_display_name_source` → `lookups` (group `dataColDisplayNameSource`, codes
  `auto` | `manual`; a NULL FK is the default `auto`) so an admin-curated label
  is never silently overwritten by the next submission. A `UNIQUE
  (id_data_tables, field_key)` makes the write-time lookup safe and fast.
  Migration `Version20260626120120` renames `name -> field_key` preserving data,
  **pre-merges any pre-existing duplicate columns** (re-pointing their
  `data_cells` onto a canonical column before adding the unique key), adds the
  new columns, and rebuilds `build_dynamic_columns` to pivot on `field_key`
  with backtick-safe aliases (dotted survey keys are treated as opaque
  literals); migration `Version20260626143127` then converts the provenance
  flag from a VARCHAR to the `id_display_name_source` lookups FK.
- **`DataService::saveData()` resolves columns by `field_key` via the new
  `DataColumnService`** — core form submissions (keyed by the human input name)
  are first remapped to their `section_<input id>` key by the new
  `FormFieldKeyResolver` (the human name travels along as the auto
  `display_name`), then a batch `field_key IN (...)` fetch + concurrency-safe
  `INSERT IGNORE` for missing columns, strict key validation
  (`^[A-Za-z][A-Za-z0-9_.]{0,254}$`, dotted survey segments allowed), and an
  expanded reserved-key guard (`id`, `id_users`, `trigger_type`, `record_id`,
  `__*`, …) so metadata never becomes a dynamic column. Row metadata
  (`id_users`, `trigger_type`) is split out from field values.
- **Reads, exports, interpolation variables and action contexts use the stable
  key but show readable names.** Core-form reads (prefill, `showUserInput`,
  `retrieve_data` interpolation scope, CSV/JSON export) remap each
  `section_<id>` key back to the current human input name, and
  `DataVariableResolver` returns a `token => label` map whose **token is the
  current input name** (never the opaque key) so the CMS variable picker both
  shows and inserts readable names.
- **The interpolation variable picker has its own endpoint** `GET
  /cms-api/v1/admin/sections/{section_id}/data-variables` (permission
  `admin.page.read`, seeded by migration `Version20260629063147`), returning the
  `token => label` map. It is **no longer part of the cached `getSection`
  payload** (that response is now `{ section, fields, languages }`): the
  variables depend on the referenced data tables' live columns, and a column
  added by a later form submission only invalidates the DATA_TABLE scope, not
  the section's SECTION scope. `DataVariableResolver` assembles the map from its
  granular caches (section hierarchy/data under SECTION scope, table columns
  under DATA_TABLE scope), so adding a column **or** editing `data_config` both
  refresh it; the CMS section inspector fetches it fresh when it opens, so a new
  column/rename appears in the picker without re-saving the section.
- **New admin endpoint** `PATCH /cms-api/v1/admin/data/tables/{tableName}/columns/display-name`
  (permission `admin.data.update_columns`, seeded by migration
  `Version20260626121351`) curates a column label without touching its storage
  key. The admin columns endpoint response is now
  `{ id, fieldKey, displayName }` (was `{ id, name }`).
- **API contract change consumed by the frontend** (column response shape, the
  `data_variables` move to its own `GET /admin/sections/{id}/data-variables`
  endpoint, and the slimmer `getSection` payload), so `supports.frontend` is
  raised `>=0.1.30 -> >=0.1.48` and the core default version bumps to `0.1.23`.
  The matching
  SurveyJS plugin guard (block renaming/removing an answered `question.name`)
  ships in plugin `0.3.4`. Deploying requires running the new migrations and a
  Symfony cache clear.

# v0.1.22

## Mobile live preview renders plugin styles (e.g. SurveyJS)

- **Fix: a plugin style embedded in a page failed to load in the CMS mobile live
  preview while working in the standalone app.** The live preview authenticates
  with a scoped `purpose: mobile_preview` JWT, and `MobilePreviewAccessGuard`
  hard-blocked every route outside its core read-only allowlist — including the
  plugin PUBLIC runtime routes (`/cms-api/v{n}/plugins/...`) the SurveyJS runtime
  calls. The guard threw *"This action is not permitted for a mobile preview
  session."*, so the survey showed "Survey not available". The standalone app
  uses a normal user JWT (the guard ignores it), which is why it worked.
- **The guard now also permits plugin PUBLIC runtime routes for preview tokens,
  with any method** (`isPluginPublicRoute()` — path prefix
  `^/cms-api/v\d+/plugins/`), so an embedded plugin style loads, autosaves and
  submits in the preview exactly as on the live page (a preview submit creates a
  real run as the previewed admin, mirroring the web preview). The
  permission-gated plugin ADMIN surface (`/cms-api/v{n}/admin/plugins/...`) and
  all non-listed core routes stay blocked. Plugin public routes carry no route
  permission and enforce their own per-response ownership checks, so the preview
  token stays confined to the same public surface the iframe already shows.
- **Additive only — no API contract change.** No route, response field, schema
  or permission was added/changed; only an authorization guard was relaxed, so
  `supports.frontend` stays `>=0.1.30` and no frontend/mobile change is required.
  Regression coverage extended in
  `tests/Unit/EventListener/MobilePreviewAccessGuardTest.php` (preview token
  reaches a plugin public route with GET/POST/PUT; still denied on the plugin
  admin route). Bumps the core default version to `0.1.22`. Deploying requires a
  Symfony cache clear so the recompiled listener takes effect.

# v0.1.21

## CMS Live Preview entitlement (`admin.mobile_preview.view`)

- **New `admin.mobile_preview.view` permission** (migration
  `Version20260623193630`, granted to the `admin` role, with an up/down
  round-trip test). It is the dedicated entitlement for the frontend's new
  full-screen **Live Preview** surface (a new-tab, free-navigation mobile/web
  preview to test the real flow), kept SEPARATE from
  `admin.mobile_preview.create` (which gates minting one-time preview codes) so
  a role can be granted the two independently. No new `api_route` — the live
  preview reuses the existing admin mint + public exchange routes; the
  permission only surfaces in the admin user-data `permissions[]` so the
  frontend can gate the `/admin/preview` route and the editor "Open live
  preview" entry on it.
- **Free-navigation mint is already supported by the existing contract.** The
  mint request schema (`requests/admin/mobile_preview_session`) has no required
  fields, and `MobilePreviewAccessGuard` only pins navigation to one keyword
  when the mint binds a `keyword`/`page_id` scope — so a keyword-less mint
  yields a scoped token that may render ANY page (still GET-only, still the
  read-only render allowlist). The full-screen preview relies on this; no guard
  change was needed.
- Bumps the core default version to `0.1.21`. Additive only: `supports.frontend`
  stays `>=0.1.30` (the core does not require the live-preview UI; frontend
  `0.1.33` adopts it and raises ITS `supports.core` floor to `0.1.21`).

# v0.1.20

## CMS-driven mobile-preview install / update

- **Mobile-preview version in the system summary.** `GET /admin/system/version`
  now reports `mobile_preview_version` (from the manager-injected
  `SELFHELP_MOBILE_PREVIEW_VERSION` env, mirroring `SELFHELP_FRONTEND_VERSION`,
  with `unknown` when the optional preview image is not installed).
  `SystemInstanceService` carries the value; `system_version.json` requires it and
  it is mirrored in `@selfhelp/shared` `ISystemVersion`.
- **Mobile-preview update endpoints.** Three new admin routes mirror the
  frontend-only flow: `GET /admin/system/update/mobile-preview/releases`
  (registry versions, fail-soft), `GET /admin/system/update/mobile-preview/preflight`
  (stateless compatibility verdict — never destructive), and
  `POST /admin/system/update/mobile-preview/request` (records an instance-scoped
  install/update, `202`). Reads reuse `admin.system.read`, the request reuses
  `admin.system.update`; the request body carries **no `instance_id`** (`403` if
  sent). Routes + permission links are added by migration
  `Version20260623180726.php` (with an up/down round-trip test).
- **`mobile-preview` operation kind.** `SystemUpdateOperation` gains
  `kind = mobile-preview` and a `target_mobile_preview_version` column (same
  migration). The status + manager-claim payloads expose
  `target_mobile_preview_version`, and a preview claim — like a frontend claim —
  is treated as a stateless swap that skips core registry recomputation.
- **Preview ⇄ core compatibility gate.** The preflight blocks
  (`mobile_preview_compatibility`, standardized `CompatibilityError` with
  `component: mobile-preview`) when the target preview's
  `backendCompatibility.requiredCoreRange` does not admit the running core, so the
  CMS verdict matches what the SelfHelp Manager enforces. An unreadable signed
  release degrades to a warning (no fabricated block). A **not-installed** instance
  reports current `unknown`, which is the **enable/bootstrap** path (stays `ok`,
  never a false downgrade) so the manager can provision the container.
- Service + controller + permission-matrix tests cover the releases picker,
  preflight (ok / downgrade / invalid / compatibility / bootstrap), the
  instance-scoped request, the claim DTO, and the status payload.
  `composer phpstan` stays at 0 errors.

# v0.1.19

## Mobile preview session API + plugin mobile-compatibility axis

- **Mobile preview session endpoints.** New admin-only mint endpoint
  `POST /cms-api/v1/admin/mobile-preview/session` (`AdminMobilePreviewController`,
  gated by the `admin.mobile_preview.create` permission) returns a SHORT-LIVED,
  single-use code bound to the calling admin (derived server-side from the JWT)
  and an optional preview scope (keyword / page / language / draft). The public
  `POST /cms-api/v1/mobile-preview/session/exchange`
  (`MobilePreviewController`) consumes the one-time code and mints a short-lived,
  scoped `purpose: 'mobile_preview'` JWT for read-only `/cms-api` use by the
  `selfhelp-mobile-preview` image. Logic lives in
  `Service/MobilePreview/MobilePreviewSessionService`; request/response contracts
  are JSON-schema validated (`config/schemas/api/v1/{requests,responses}/...
  mobile_preview_*.json`) and mirror `@selfhelp/shared` >= 1.14.25.
- **`MobilePreviewAccessGuard`.** A request listener inspects the JWT payload and
  confines a `purpose: 'mobile_preview'` token to a read-only `/cms-api`
  allowlist (page/section render + lookups), so a leaked preview token cannot
  mutate data or reach admin endpoints.
- **API routes migration.** A generated Doctrine migration inserts the two routes
  into `api_routes` + links the admin mint route in `rel_api_routes_permissions`
  (with an up/down round-trip test); the public exchange route is permission-less
  (the one-time code is the credential).
- **Plugin manifest `compatibility.mobile`.** `docs/plugins/plugin-manifest.schema.json`
  gains an optional `compatibility.mobile` semver range (the mobile-renderer
  contract axis, mirroring `@selfhelp/shared` `MOBILE_RENDERER_VERSION`) — the
  second axis of the manager's dual-axis mobile plugin gate. Kept byte-identical
  with the registry's copy.
- Security tests cover the admin/permission matrix on mint, the one-time/scoped
  nature of exchange, and the access-guard allowlist. `composer phpstan` stays at
  0 errors.

# v0.1.18

## Security — anonymous access hardening + frontend page route cleanup

- **Anonymous callers are no longer treated as user id 1 (admin).** The guest
  fallback in `ACLService`, `UserContextAwareService`, and `PageService` resolved
  an unauthenticated request to user id `1` — which belongs to the admin group —
  so anonymous visitors inherited admin page ACLs and could read restricted
  pages (and collided with the admin's page/section cache namespace). A dedicated
  guest sentinel `UserContextService::GUEST_USER_ID = 0` now represents
  anonymous callers; it is a member of no group, so branch 1 of the `get_user_acl`
  stored procedure returns nothing and only `is_open_access = 1` pages (branch 2)
  are reachable without authentication.
- **Anonymous form submissions store a `NULL` owner**, not admin id 1
  (`DataService::saveData` + `FormController`). `data_rows.id_users` is nullable
  and carries no FK, so an unauthenticated submission is correctly un-owned.
- **`preview=true` now requires authentication.** `PageService` rejects an
  anonymous draft request with `401` before any draft is rendered (the page ACL
  check still applies on top of that for authenticated callers).
- **Migration `Version20260623082726`** marks the public global pages
  `sh-global-css` and `sh-global-values` as `is_open_access = 1` so anonymous
  visitors keep loading global CSS/values after the sentinel fix (these were only
  reachable before *because* of the admin-id-1 bug). `sh-cms-preferences` stays
  private. The same migration removes the duplicate `pages_get_one` API route
  (see below).
- **Removed the duplicate `GET /cms-api/v1/pages/{page_id}` route.** Single page
  content is now resolved exclusively by keyword
  (`GET /cms-api/v1/pages/by-keyword/{keyword}`), which the web/mobile BFF already
  used exclusively — the numeric-id route was an unused legacy duplicate that
  shared the identical ACL/serving path. `PageController::getPage` and
  `PageService::getPage` are deleted; misleading `@Route` docblocks on
  `PageController`/`FormController` (routes are DB-defined, loaded by
  `ApiRouteLoader`) were replaced with accurate comments.
- **Tests:** anonymous-can't-inherit-admin-ACL (`403`) and anonymous-reads-open-access
  (`200`) golden cases (`PublicPageRenderingWorkflowTest`), anonymous `preview=true`
  → `401` (`PageControllerModeTest`), anonymous form submission → `NULL` owner
  (`FormControllerTest`), and a round-trip test for `Version20260623082726`.
- **Cross-repo impact:** this is a breaking backend change, but it is
  **client-transparent** — no frontend/mobile/shared code referenced the by-id
  route (all use by-keyword), and the anonymous-access hardening requires no
  client adoption. The frontend ⇄ backend `supports.*` floors are therefore
  unchanged (frontend `>=0.1.29` ⇄ core `>=0.1.17`); core `0.1.18` is within the
  frontend's existing `supports.core` range.

# v0.1.17

## CMS Styles — mobile-only (HeroUI Native) capability pass

- **Migration `Version20260622145334`** adds 7 `mobile_*` fields (all additive /
  id-stable) for HeroUI Native props that have **no web/Mantine equivalent**, so
  authors can tune the native look from the CMS without affecting the web
  renderer (which ignores `mobile_*`):
  - **`select` + `combobox`**: `mobile_select_presentation` (`select`) — how the
    HeroUI Native option list opens: `bottom-sheet` (default), `dialog`, or
    `popover`. Left empty → the renderer's `bottom-sheet` default. Combobox reuses
    the mobile select renderer, so it links the same field.
  - **`button`**: `mobile_button_feedback` (`select`) — native press feedback:
    `scale-highlight` (default), `scale-ripple`, `scale`, `none`.
  - **`slider` / `range-slider`**: `mobile_slider_show_value` /
    `mobile_range_slider_show_value` (`checkbox`, default `1`) — toggle the HeroUI
    Native `Slider.Output` value bubble.
  - **`text-input` / `textarea` / `checkbox`**: `mobile_input_variant` /
    `mobile_textarea_variant` / `mobile_checkbox_variant` (`segment`, default
    `primary`) — HeroUI Native `primary`/`secondary` field variant.
- **Round-trip test** `tests/Integration/Migrations/Version20260622145334RoundTripTest.php`
  (`#[Group('migration')]`) proves `up()`/`down()` reversibility.
- The new author fields are consumed only by the mobile renderer (backend `src/`
  reads none of them; the web renderer ignores `mobile_*`); requires
  `@selfhelp/shared` ≥ 1.14.18. No `/cms-api` route, response-shape, or
  permission change, so the frontend ⇄ backend `supports.*` floors are unchanged.

# v0.1.16

## CMS Styles — form / interactive capability pass

- **Migration `Version20260622132034`** exposes Mantine props these styles could
  already render but had no CMS field for (all additive / id-stable), plus one
  cleanup unlink:
  - **`number-input`**: `web_number_input_prefix`, `web_number_input_suffix`
    (currency / unit affixes), `web_number_input_thousand_separator`,
    `web_number_input_allow_negative`, `web_number_input_hide_controls`.
  - **`color-input`**: `web_color_input_with_eye_dropper`,
    `web_color_input_disallow_input`, `web_color_input_with_preview`.
  - **`tabs`**: `web_tabs_grow`, `web_tabs_justify` (Mantine `JustifyContent`
    lookup), `web_tabs_keep_mounted`, `web_tabs_placement` (vertical list side).
  - **`switch`**: `web_switch_with_thumb_indicator`, `web_switch_thumb_icon`
    (`select-icon` picker).
  - **`text-input` + `textarea`**: `shared_max_length` (web + mobile max chars)
    and the mobile native keyboard knobs `mobile_keyboard_type`,
    `mobile_auto_capitalize`, plus `mobile_secure_entry` (text-input only).
  - **`progress-root`**: links the existing `shared_radius` field (rounder bar).
  - **Cleanup**: unlinks the unused `alt` field from `select` (the field row
    stays — avatar / image still use it); authored `alt` values on `select`
    sections are dropped. `down()` is a best-effort structural inverse.
- **Round-trip test** `tests/Integration/Migrations/Version20260622132034RoundTripTest.php`
  (`#[Group('migration')]`) proves `up()`/`down()` reversibility.
- The new author fields are consumed only by the web / mobile renderers (backend
  `src/` reads none of them); requires `@selfhelp/shared` ≥ 1.14.17.

# v0.1.15

## CMS Styles — typography / media / interactive field pass

- **Migration `Version20260622110041`** rounds out the styles in the
  `typography, media, interactive` groups with author-requested fields (all
  additive / id-stable, so authored content survives):
  - **`list-item`**: `list_item_content` converted `textarea` → `markdown-inline`
    (inline bold/italic/underline/link, same cross-platform contract as `text`).
  - **`blockquote`**: new dedicated `blockquote_content` (`markdown-inline`)
    field; existing `blockquote` content rows are migrated from the shared
    `content` field to it and the generic `content` field is unlinked from
    `blockquote` (so the `code` style keeps a plain `content`). `down()` restores
    the old link and moves the data back.
  - **`image`**: `fallback_src` (Mantine `Image.fallbackSrc`).
  - **`figure`**: optional built-in `img_src` + `alt` (render-only convenience —
    never auto-creates a child section).
  - **`link`**: `shared_color`, `web_link_underline` (always/hover/never),
    `web_left_icon`, `web_right_icon`.
  - **`action-icon`**: `aria_label` (accessible name for the icon-only control).
  - **`spoiler`**: `shared_color` (show/hide control colour).
  - **`video`**: `poster_src`, `has_controls`, `media_loop`, `media_autoplay`,
    `media_muted` (new `'0'|'1'` playback toggles + poster).
  - **`audio`**: `has_controls`, `media_loop`, `media_autoplay`.
  - New reusable field rows: `web_link_underline` (segment), `aria_label` (text),
    `poster_src` / `fallback_src` (select-image), `media_loop` / `media_autoplay`
    / `media_muted` (checkbox); existing `shared_color`, `img_src`, `alt`,
    `has_controls`, `web_left_icon`, `web_right_icon` are reused via
    `rel_fields_styles`.
- **Round-trip test** `tests/Integration/Migrations/Version20260622110041RoundTripTest.php`
  (`#[Group('migration')]`) — `up()`/`down()` verified against an isolated
  throwaway DB.
- **Shared** `@selfhelp/shared@1.14.15` carries the matching `I<Name>Style`
  additions (`IBlockquoteStyle.blockquote_content`, `IImageStyle.fallback_src`,
  `IFigureStyle.img_src/alt`, `ILinkStyle.shared_color/web_link_underline/
  web_left_icon/web_right_icon`, `IActionIconStyle.aria_label`,
  `ISpoilerStyle.shared_color`, `IVideoStyle.*`, `IAudioStyle.*`).
- **Docs** `docs/reference/styles/{typography,media,interactive,composite}.md`
  updated for every changed style (fields, behaviour, web/mobile mapping).
- **Activate:** `php bin/console doctrine:migrations:migrate` then invalidate the
  styles cache (`/cms-api/v1/admin/cache/clear/all` or restart Redis) and
  regenerate the audit with `php scripts/build-style-audit.php`.

## CMS Styles — inline rich-text on the `text` field (text + highlight)

- **Migration `Version20260622100253`** switches the shared `text` content field
  (used by the `text` and `highlight` styles) from the plain multi-line
  `textarea` editor to `markdown-inline`, so an author can select a word and
  apply inline **bold / italic / underline / link** (Ctrl/⌘ + B/I/U). The web
  and mobile `text` renderers preserve that safe inline subset, so a bold label
  authored on the web also renders bold on the mobile app — the cross-platform
  goal. Existing stored values are untouched (only the editor changes);
  `down()` restores the `textarea` editor.
- **Round-trip test** `tests/Integration/Migrations/Version20260622100253RoundTripTest.php`
  (`#[Group('migration')]`) — verified locally against an isolated throwaway DB
  (1 test, 7 assertions).
- **Docs** `docs/reference/styles/typography.md` (`text` + `highlight` sections)
  updated to describe the inline-formatting behaviour and the web/mobile render
  contract.
- **Activate:** run `php bin/console doctrine:migrations:migrate` (not auto-run),
  then regenerate the audit with `php scripts/build-style-audit.php`.

# v0.1.14

## CMS Styles — layout cross-platform pass

- **The 13 layout styles (`box`, `container`, `paper`, `center`, `group`,
  `stack`, `flex`, `grid`, `grid-column`, `simple-grid`, `space`, `divider`,
  `scroll-area`) became configurable on mobile, not just web** (migration
  `Version20260622063129`). The portable sizing/behaviour properties that were
  trapped under `web_*` were promoted to `shared_*` so the same field now drives
  both the Mantine (web) and the React-Native (mobile) renderer through the
  `@selfhelp/shared` semantic mapper:
  - **id-stable renames** (field used only by layout styles): `web_cols`→
    `shared_cols` (grid, simple-grid), `web_divider_variant`→
    `shared_divider_variant`, `web_divider_label_position`→
    `shared_divider_label_position`, `web_grid_span|offset|order|grow`→`shared_*`
    (grid-column), `web_miw|mih|maw|mah`→`shared_*` (center),
    `web_vertical_spacing`→`shared_vertical_spacing` (simple-grid). Authored
    values + relationships survive (the field id is unchanged).
  - **re-links** (field still used by non-layout styles): `web_width`/
    `web_height` → new `shared_width`/`shared_height` on the layout styles only
    (the `web_*` fields stay for the non-layout styles that still need them);
    `paper.web_border`→`shared_border` (the existing field that already powers
    `card`); `space.web_space_direction`→`shared_orientation`. Authored content
    is repointed in `sections_fields_translation` so values are preserved across
    the scope change.
  - **additions:** `paper.title` (optional auto-styled heading — renders a
    heading above the content when filled, a plain surface when empty; never
    creates a child section); `simple-grid.shared_gap` (the horizontal column
    spacing that was missing) plus `web_cols_sm`/`web_cols_md`/`web_cols_lg`
    (web responsive overrides, clearable to inherit `shared_cols`).
  - **removals (FK-safe):** `web_px`/`web_py` (container, paper — padding now
    comes from the portable `shared_spacing`), `web_breakpoints` (simple-grid —
    replaced by the responsive `web_cols_*`), and `web_space_direction` (folded
    into `shared_orientation`).
  - `grid.can_have_children` is intentionally left at `0`: grid stays
    **restricted to `grid-column` children** through
    `rel_styles_allowed_relationships` (the `0 + whitelist` "restricted children"
    model), which is correct, not a missing-children bug.
  - Coupled with `@selfhelp/shared` (the `shared_*` layout types + mapper) and
    the frontend/mobile layout renderers. Reversible `down()`; round-trip test
    `Version20260622063129RoundTripTest`; `AdminStyleEndpointsTest`
    `testLayoutCrossPlatformPassFieldsAndScopes`; docs
    (`docs/reference/styles/layout.md`) + regenerated style-field audit updated
    to match. (migration `Version20260622063129`)
- **The new layout fields show proper labels in the section inspector.** The
  inspector renders a field's `rel_fields_styles.title` and falls back to the
  raw `fields.name` when it is empty, so the freshly-linked layout fields read
  "shared_width"/"shared_height" instead of "Width"/"Height". Backfilled the
  missing per-style labels (`shared_width`→Width, `shared_height`→Height,
  `paper.title`→Title, `paper.shared_border`→Border, `space.shared_orientation`→
  Orientation, `simple-grid.shared_gap`→Gap, `web_cols_sm|md|lg`→Columns
  (SM|MD|LG)) to match the convention used by the established links. Reversible
  `down()`; round-trip test `Version20260622080852RoundTripTest`. (migration
  `Version20260622080852`)

## API — Security & robustness audit

- **Internal server-error details are no longer leaked to API clients in production.** A new `ApiResponseFormatter::formatThrowable()` centralizes the error handling that controller `catch` blocks used to copy-paste (`formatError($e->getMessage(), $e->getCode() ?: 500)`): it clamps a `Throwable`'s code to a valid HTTP status (a raw `0`/`999`/SQLSTATE string can no longer make `JsonResponse` throw), preserves the intended status + user-facing message for domain `ServiceException`s, logs unexpected (non-domain) 5xx so a swallowed 500 is never invisible, and — outside `kernel.debug` — masks 5xx messages behind a generic `Internal Server Error`. `ApiExceptionListener` applies the same masking for bubbled exceptions. All admin/auth/frontend controllers were migrated to `formatThrowable()`. (`ApiResponseFormatter`, `ApiExceptionListener`, `config/services.yaml` `$debug` wiring, `ApiResponseFormatterTest`)
- **The section export/import raw-SQL fallback is parameter-bound.** When the Doctrine relationship-clearing path fails and falls back to raw SQL, section ids are now passed as bound parameters (`ArrayParameterType::INTEGER`) instead of being interpolated into the statement; the only remaining concatenation is the computed `AUTO_INCREMENT` integer (DDL cannot bind parameters), documented as injection-safe. (`SectionExportImportService`)
- **User-supplied date filters are validated instead of crashing the request.** The audit-log and scheduled-job list endpoints fed `date_from` / `date_to` straight into `new \DateTime(...)`, so an unparseable value surfaced as an uncaught `500`; both now reject a bad filter with a `400`. The user block toggle now requires an explicit boolean `blocked` in the body rather than silently defaulting to "block". (`AdminAuditService`, `AdminScheduledJobService`, `AdminUserController`)
- **Interpolation, page-version and data-record errors are written to the PSR logger.** `error_log()` calls were replaced with the injected `LoggerInterface` (with the throwable attached), and the non-autowired `InterpolationService` now receives `$logger` explicitly. (`InterpolationService`, `PageVersionService`, `DataService`, `config/services.yaml`)
- **Smaller correctness fixes:** strict (`true`) `in_array()` permission comparison in `ApiSecurityListener`; `User::isTwoFactorRequired()` is now a pure getter (it no longer mutates `twoFactorRequired` as a side effect of reading it); and the dead, unreferenced `ApiRouteVoter` was removed (route-level permissions are enforced by `ApiSecurityListener`).

## CMS Styles — card padding cleanup

- **The `card` style dropped the redundant web-only `web_card_padding` field**
  (migration `Version20260619205908`). `card` already extends the portable
  spacing contract (`shared_spacing`), whose padding side (`pt`/`pb`/`ps`/`pe`)
  renders on web AND mobile, so a second Mantine-`padding` control was a
  duplicate that confused authors. The migration unlinks the field from `card`
  only (it stays on `validate`, which is intentionally web-only) and removes any
  authored card-section values for it; the web `CardStyle` renderer keeps a fixed
  Mantine `padding="md"` inner default (also the `Card.Section` image-bleed
  reference) and authors now tune padding through the shared **Spacing** control.
  Coupled with `@selfhelp/shared` `1.14.11` (`ICardStyle.web_card_padding`
  removed) and the frontend `card` renderer. Docs (`docs/reference/styles/
  layout.md`, the AI prompt template, the regenerated style-field audit) updated
  to match. (migration `Version20260619205908`)

## CMS Styles — kebab-case style names

- **The remaining camelCase CMS style names were renamed to kebab-case** so the
  style catalog uses one casing across the backend `styles` rows,
  `@selfhelp/shared` 1.8.0 (the `style_name` discriminator), the frontend
  `BasicStyle` dispatcher and the mobile renderers: `resetPassword`→
  `reset-password`, `twoFactorAuth`→`two-factor-auth`, `noAccess`→`no-access`,
  `notFound`→`not-found`, `entryList`→`entry-list`, `entryRecord`→`entry-record`,
  `entryRecordDelete`→`entry-record-delete`, `showUserInput`→`show-user-input`,
  `refContainer`→`ref-container`, `dataContainer`→`data-container`. A
  name-guarded, reversible migration renames the `styles.name` rows; because
  sections reference styles by `id_styles` (FK), this is a metadata rename, not a
  content migration, and the `styles_fields` links are unaffected. The PHP
  look-ups were updated in lockstep (`StyleNames::STYLE_SHOW_USER_INPUT`,
  `PageService::FALLBACK_CHECK_KEYWORDS`, and `AdminSectionUtilityService`'s
  `ref-container` query). This is a **coordinated breaking change**: a CMS running
  this migration requires frontend `>=0.1.21` and `@selfhelp/shared` `>=1.8.0`
  (older clients dispatch the camelCase names and would render these styles as
  Unknown). `AGENTS.md` now mandates kebab-case style names, and the
  `docs/reference/styles/` per-style reference (the catalog `index.md`, the
  renamed `auth/reset-password.md` / `auth/two-factor-auth.md`, and the
  layout/composite/forms pages) plus the affected developer/API docs were
  updated to match. (migration `Version20260618120000`; `StyleNames`,
  `PageService`, `AdminSectionUtilityService`)

## CMS Styles — accordion/accordion-item authoring polish

- **The `accordion` and `accordion-item` styles got a cross-platform field
  clean-up and a mobile rebuild** (migration `Version20260619183601`). The
  accordion variant is no longer web-only: `web_accordion_variant` was renamed
  (id-stable, options + authored values preserved) to the shared
  `shared_accordion_variant`, so it flips from the Web card to the Shared card
  (`StyleRepository::deriveFieldScope`) and is read by both platforms — web maps
  it to the Mantine `variant` (default/contained/filled/separated), mobile maps
  it through `@selfhelp/shared` `mapAccordionVariantToHeroUiVariant` onto the
  HeroUI Native Accordion `variant` (`default`/`surface`). The field is now
  `clearable`. `accordion-item` gains the existing translatable `description`
  field as an optional subtitle under the item label (empty = hidden). The
  mobile renderers were rebuilt on the HeroUI Native `Accordion` compound
  (themed + animated, theme-aware text via `useAppColors`), fixing the previous
  hard-coded `#e9ecef` border + uncoloured `<Text>` dark-mode bugs. This is a
  **coordinated change** paired with `@selfhelp/shared` `>=1.14.8` and the
  coupled web + mobile renderers. (migration `Version20260619183601`;
  `AdminStyleEndpointsTest::testAccordionPolishWaveFieldsAndScopes`,
  `Version20260619183601RoundTripTest`; docs `docs/reference/styles/composite.md`)

## CMS Styles — card/card-segment/checkbox/chip/code/title authoring polish

- **Seven core styles got a cross-platform field clean-up and an authoring-UX
  upgrade** (migration `Version20260619191224`), continuing the style polish
  wave. Highlights:
  - **`card`** gains two optional auto-styled **content** fields — `title` (an
    automatic heading) and `img_src` (an asset-picker top image). They render
    only when filled (empty = a plain card, exactly as before) and **never**
    auto-create a child section — authors keep full manual control with child
    sections. Border becomes cross-platform: `card` drops the web-only
    `web_border` and gains the new shared `shared_border` (Mantine `withBorder`
    on web, a themed border on mobile). The global `web_border` field stays for
    `indicator`/`notification`/`paper`/`validate`, which remain web-only for now.
  - **`card-segment`** gains the new shared `shared_border` (Mantine
    `Card.Section withBorder`; a themed divider on mobile) and the new web-only
    `web_segment_inherit_padding` (Mantine `inheritPadding`).
  - **`checkbox`** promotes `web_checkbox_label_position` → `shared_label_position`
    (label side is honoured on both platforms).
  - **`chip`** promotes `web_chip_variant` → `shared_chip_variant` (id-stable;
    keeps the `filled`/`outline`/`light` enum — distinct from the wider generic
    `shared_variant` — and is now clearable).
  - **`code`** promotes `web_code_block` → `code_block` (cross-platform
    block-vs-inline behaviour, unprefixed) and links `shared_radius`.
  - **`title`** links `shared_color`, and promotes `web_title_order` →
    `title_order` (semantic heading level on both platforms) and
    `web_title_line_clamp` → `shared_line_clamp` (mobile `numberOfLines`);
    `web_title_text_wrap` stays web-only.
  - Field scope is derived from the name prefix
    (`StyleRepository::deriveFieldScope`), so each rename flips the field from the
    Web card to the Shared/Properties card and makes it readable by the mobile
    renderer. Relationships and authored content reference fields by id, so the
    id-stable renames never break a link. This is a **coordinated change** paired
    with `@selfhelp/shared` and the coupled web + mobile renderers.
    (`AdminStyleEndpointsTest::testCardFamilyAndTypographyPolishWaveFieldsAndScopes`,
    `Version20260619191224RoundTripTest`; docs `docs/reference/styles/layout.md`,
    `typography.md`, `forms.md`, `interactive.md`)

## CMS Styles — alert/badge/avatar/button/login authoring polish

- **The `alert`, `badge`, `avatar`, `button` and `login` styles got a field
  clean-up so their cross-platform visual semantics live in `shared_*`/`common`
  fields and the catalog drops dead/mis-scoped fields** (migration
  `Version20260619131830`, FK-safe + reversible):
  - **alert** — removed the dead `shared_size` link (Mantine `Alert` has no
    `size`) and renamed the web-only `web_with_close_button` to the
    cross-platform `closable` (`common` scope) so mobile can honour the dismiss
    control.
  - **badge** — added the cross-platform `shared_variant` (default `filled`;
    existing `web_variant` values migrated onto it) and a `circle` toggle
    (`common`) for round count chips; `web_variant` is kept as a web-only escape
    hatch (default empty) for web-specific variants such as `dot`.
  - **avatar** — linked the existing `name` field (`common`) so authors get
    auto-initials + a stable auto colour without filling `web_avatar_initials`.
  - **button** — promoted the variant to the cross-platform `shared_variant`
    (default `filled`; existing `web_variant` values migrated onto it) and
    removed the button-only `web_variant` link; linked the existing `url` field
    so external links work without an internal `page_keyword`.
  - **login** — linked the optional translatable `subtitle` content field
    (shown under the title; hidden when empty); `shared_color` (already present)
    is now documented as the submit-button colour.
  - The `admin/styles/schema` contract, the regenerated
    `docs/reference/styles/style-field-audit.generated.json`, the
    `interactive.md` / `auth/login.md` reference pages, a migration round-trip
    test and an `AdminStyleEndpointsTest` regression covering the new
    fields/scopes all ship in the same change. The coupled `@selfhelp/shared`
    type + web/mobile renderer reads (incl. correcting the shared type's stale
    `web_avatar_variant`/`type`) land in the renderer wave.
  - **Compatibility:** the field renames finalise the (still-unreleased) `0.1.15`
    style-schema contract, so `release-manifest.json` `supports.frontend` rose to
    `>=0.1.23 <0.2.0` (the frontend that reads the renamed `closable` /
    `shared_variant` names); `selfhelp.cms_version` stays `0.1.15` (unreleased —
    the changes fold into it) and `docs/developer/cross-repo-compatibility-matrix.md`
    was updated to the `frontend >=0.1.23 ⇄ core >=0.1.15`, shared `1.14.6`,
    mobile `0.1.2` snapshot.

# v0.1.13

## System Maintenance — Frontend update compatibility

- **The frontend-only update preflight now reports the SAME frontend ⇄ core compatibility verdict the SelfHelp Manager enforces, so the CMS no longer shows "Preflight OK" for a frontend the running core forbids.** Previously the CMS frontend preflight only checked the version + downgrade and deferred *all* compatibility to the manager at execution time — so requesting, for example, `0.1.17 → 0.1.19` returned `OK` even when the installed core's `requiredFrontendRange` excluded `0.1.19`, and the operator only discovered the incompatibility after the manager rejected it. `SystemUpdateService::getFrontendPreflight()` now reads the signed registry metadata (the same documents the manager resolves) and raises a blocking, standardized `frontend_compatibility` `CompatibilityError` in **both** directions: the running core's `frontendCompatibility.requiredFrontendRange` must admit the target frontend, **and** the target frontend's `backendCompatibility.requiredCoreRange` must admit the running core. The server-side `POST /admin/system/update/frontend/request` guard recomputes the same preflight, so a blocked frontend update is rejected with `422` and cannot be requested with a crafted/stale preflight. (`SystemUpdateService`, `SystemRegistryReader::getFrontendRelease()`, new signed `FrontendRelease` document + `UnifiedRegistryClient::fetchFrontendRelease()`, `CompatibilityError::frontendUpdateBlockedByCore()` / `frontendUpdateRequiresCore()`)
- **Compatibility metadata is read from signed, signature-verified release documents only.** The new `FrontendRelease` advisory document is Ed25519-verified the same way core/plugin releases are (cross-installer canonical parity with the manager's `frontend-release.json` is covered by a test), so a tampered advisory cannot mislead the operator. When a release document is unavailable (registry offline, version unpublished, or signature failure) the affected direction degrades to the existing `registry_unreachable` **warning** rather than a silent pass — the manager remains the final authority and additionally enforces the running core's range from the instance lock even when the core release has left the registry. (`FrontendRelease`, `UnifiedRegistryClient`, `SystemRegistryReader`)

# v0.1.12

## CMS Styles — Error pages

- **The `no-access` (403), `no-access-guest` and `missing` (404) system pages are now styled CMS pages instead of bare screens.** New `noAccess`, `notFound` and `missing` CMS styles (with title/message/button/login-label copy and `mantine_color` / `mantine_radius` / `mantine_shadow` / `mantine_button_variant` / `show_icon` presentation fields) are seeded and wired onto the corresponding system pages, and each page's sections are wrapped in a container so they match the login page's layout. System page keywords were normalized to kebab-case (`reset_password`→`reset-password`, `no_access`→`no-access`, `no_access_guest`→`no-access-guest`) so the CMS keyword matches the public URL segment with no alias map, and the `refContainer` style description was corrected to "structural/transparent container, not a visual wrapper". (migrations `Version20260605134800`, `Version20260608075822`, `Version20260608090032`, `Version20260608124537`)

## CMS Styles — showUserInput

- **A new `showUserInput` style renders a form's collected entries as a table.** It is seeded with `data_table`, `fields_map` (column remapping), `own_entries_only` and `show_timestamp`; the data-table feature-flag fields `dt_sortable` / `dt_searching` / `dt_paginate` / `dt_info` / `dt_default_order_column` / `dt_default_order_dir`; the full `mantine_table_*` styling set; and translatable `delete_modal_title` / `delete_modal_body`. The style always renders as a Mantine table (the temporary `use_mantine_style` link was added and then removed), and the unsupported anchor field was dropped. (migrations `Version20260609130712`, `Version20260611071244`, `Version20260611090106`, `Version20260611115337`, `Version20260611123033`, `Version20260616102744`)
- **`showUserInput` tables now refresh immediately when entries are added or deleted, without a manual cache clear.** A `showUserInput` section references its data table through the `data_table` property field rather than `data_config`, but the page-render cache only declared a dependency on `data_config` tables. The rendered (draft) page was therefore never tagged with the `showUserInput` data table's entity scope, so `DataService`'s write-path invalidation (`invalidateEntityScope(data_table_id, …)` on submit / update / delete) could not bust it and the table kept serving the stale rows until the cache was cleared. `PageService::extractDataTableDependencies()` now also registers each `showUserInput` section's `data_table` (user-scoped when `own_entries_only`, otherwise global), so creating or deleting a record invalidates every page that renders that table. (`PageService`)

## CMS Sections — refContainer & delete

- **Section deletion is split into two distinct operations, with consistent detach-vs-destroy semantics.** "Remove from page" — both the single action *and* the multi-select **bulk** action — only *detaches* a section from that one page (keeping the section record for every other page that references it), while "destroy" (`DELETE /admin/sections/{id}`) performs the page-independent permanent delete. Previously the bulk path destroyed nested sections even though the single path only detached, so the same "remove" verb had opposite outcomes for a shared `refContainer`; bulk remove is now detach-only too. `refContainer` re-usable section references resolve correctly end to end: a reused subtree is detected across the section tree, detaching a shared container on one page never destroys it on the others, and **every** mutating operation (update, detach, destroy) invalidates the page/section cache for *every* page that renders the shared container — not just the one being edited. Crucially the **destroy** path now resolves the set of referencing pages **before** the relationship rows are removed (it previously queried them afterwards, when the rows were already gone, so other pages kept serving the deleted shared container from cache). (migration `Version20260609090611`; `SectionRelationshipService`, `AdminSectionService`, `SectionRepository`, `SectionUtilityService`)

## API — Sections

- **New `GET /admin/sections/pages?ids[]=…` endpoint** (guarded by `admin.page.update`) returns every page that references the given sections — directly through `rel_pages_sections` or nested via the section hierarchy (a recursive ancestor walk) — each as `{ id, keyword, isPublished }`, deduplicated across all requested section ids. The recursive ancestor-walk is a single batched `SectionRepository::getPagesContainingSections()` query (one statement for all ids, replacing the previous per-id N-query) and is the shared source of truth for the section-delete cache-invalidation path too, instead of the CTE being reimplemented in the service. It backs the publish-time "this refContainer is also published on other pages" warning and the delete-impact list, and replaces the earlier single-section `GET /admin/sections/{section_id}/pages` variant. (migrations `Version20260609113717` then `Version20260610123849`; `AdminSectionUtilityService::getPagesBySectionIds()`, `AdminSectionUtilityController`, `SectionRepository`; `responses/admin/sections/section_pages.json` + `section_pages_envelope.json` schemas; `docs/api-usage/README.md`)

## Forms — Delete permissions

- **On a form / `showUserInput` section that shows everyone's records (`own_entries_only=false`), a user may always delete their own record, but deleting another user's record now requires `DELETE` permission on the underlying data table** — otherwise the delete endpoint returns `403 Forbidden`. `FormValidationService::validateFormDeletion()` now also returns the section's `own_entries_only` flag and `data_table` id, and `DataService::getRecordOwnerId()` resolves a record's owner. The own-record-vs-permission rule is centralized in `DataAccessSecurityService::canDeleteOwnedRecord()` and used by **both** the display check (`SectionUtilityService` deciding whether to render a row's delete button) and the API enforcement (`FormController::deleteForm`), so the visible button and the endpoint can never drift out of lockstep. (`FormController`, `FormValidationService`, `DataService`, `DataAccessSecurityService`, `SectionUtilityService`)

## Scheduled Jobs — Actions

- **`clear_existing_jobs_for_record_and_action` now also fires on the `updated` form-submission trigger, not only `finished`.** Re-submitting (updating) a record therefore clears its previously queued action jobs the same way a first/finished submission does, so updated records can't accumulate stale queued jobs. (`ActionOrchestratorService`)

# v0.1.11

## Release pipeline

- **The `docker-release` GitHub Actions release no longer fails at "Download all artifacts".** The `create-release` job downloaded *every* workflow artifact with no filter, which on the 3-image build matrix meant 16 artifacts — including six large `*.dockerbuild` build records auto-uploaded by `docker/build-push-action` and three duplicate SBOMs auto-uploaded by `anchore/sbom-action`. That bloated download intermittently aborted with `Error: Unable to download and extract artifact: Artifact download failed after 5 retries.` and also attached the build records to the public release. The build-record upload is now disabled workflow-wide (`DOCKER_BUILD_RECORD_UPLOAD: 'false'`) and the SBOM action's own upload is turned off (`upload-artifact: false`, since the SBOM is re-uploaded in the `*-supply-chain` artifact), so the release job downloads only the intended supply-chain / digest / license artifacts. No runtime code changed; this is a release-tooling fix (the v0.1.10 images had already been built, pushed and signed before the failing step).

## Authentication

- **Refreshing the session no longer logs the operator out during a plugin install / update / uninstall.** The web client refreshes the access token from two independent runtimes that share no state — the Edge proxy (SSR navigations) and the Node BFF (`/api/*`), possibly across replicas — so when the short-lived access token was near expiry while the backend briefly restarted (exactly what a plugin lifecycle operation does) both could POST the **same** single-use refresh token at once. The first rotated it, the second found nothing, got a `401`, and the BFF wiped a perfectly good session → the operator was bounced to the login page ("uninstalling a plugin logged me out"). `JWTService::processRefreshToken()` now keeps a short (`30 s`) Redis-backed rotation **grace window**: a concurrent refresh of a just-consumed token replays onto the same newly issued refresh token (and mints a fresh access token) instead of being rejected, so all concurrent callers converge on the live token. Single-use semantics are preserved once the window elapses — a genuinely reused token is still rejected — and both the replay and the post-window rejection are pinned by regression + security tests in `JWTServiceTest`.

## System Maintenance

- **Editing the maintenance system message now takes effect immediately.** Toggling maintenance mode interpolates `{{system.maintenance_message}}` into cached page/section payloads, so a changed note kept serving the stale message until the cache TTL elapsed. `MaintenanceModeService::enable()/disable()` now invalidates the `pages` and `sections` cache categories whenever maintenance mode is toggled, so the public maintenance page reflects the current note on the next request. Covered by `MaintenanceModeServiceTest`.
- **The seeded maintenance alert message is stored in the field the renderer reads.** The original seed wrote the operator note into the alert style's `value` field, but the alert renders its `content` field, so the styled message never appeared. A data-only migration (`Version20260616094205`) moves the existing `maintenance-sys-message` translation from `value` to `content`; it is idempotent (`INSERT IGNORE` + scoped `DELETE`) and has a round-trip test.

## Plugins

- **Plugin purge is now an asynchronous, manager-parked operation, like install / uninstall.** `POST /admin/plugins/{plugin}/purge` previously ran synchronously and returned `200`; it now records a `purge` `plugin_operation`, dispatches it onto the `plugin_ops` Messenger transport, and returns `202 Accepted` with the operation envelope so the admin UI can track it on the operations console and the SelfHelp Manager can park it for the operator. `PluginPurger::purge()` is split into `request()` (validate + lock + snapshot the owned tables / manifest / backup flag + dispatch) and `finalize()` (the destructive cleanup: drop plugin-owned tables and tagged rows, foreign keys, migration versions, the plugin row, then regenerate bundles + clean artefacts), mirroring `PluginUninstaller`. The new `PurgePluginMessage` / `PurgePluginHandler` run `finalize()` inline in `development` / `trusted` modes and write a runbook in `managed` mode, where the operator runs `composer remove` and then `selfhelp:plugin:run-operation <id>` (now handles `TYPE_PURGE` via `PluginCliFinalizer::finalizePurge()`). `selfhelp:plugin:purge` reports the parked operation instead of claiming an immediate, synchronous purge.

## System Updates

- **Live update progress is pushed over SSE — the CMS no longer polls for it.** A new Doctrine listener (`App\EventListener\SystemUpdateMercurePublisher`) publishes a `system-update` Mercure event on every insert/update of a `SystemUpdateOperation` row, to the **requester's** per-user topic. It fires both when the CMS creates the `requested` row and on every state / `steps` / `progress_percent` write-back the SelfHelp Manager makes while draining it, so the System Maintenance page repaints its step tracker live over the existing `/auth/events` connection. The topic is minted by a new `MercureTopicResolver::userSystemUpdateTopic()` and multiplexed onto the same single subscriber JWT as the ACL + impersonation topics by `AuthEventsController` (one upstream socket per user). `GET /auth/events` now returns `systemUpdateTopic`; `responses/auth/events.json` requires it. Publish failures are logged and swallowed — the frontend's reconnect-aware fallback poll is the safety net.

## Plugins

- **Plugin operations always reach a terminal status — async worker path included.** When a plugin operation failed **after** marking the row `running` — a missing snapshot payload, an unknown type, a `composer require/remove` non-zero exit, or any thrown orchestrator error — it could be left stuck on `running`. The admin UI then showed progress forever and the per-plugin lock blocked every later install/uninstall until its TTL expired. The terminal guarantee is now centralized in `PluginOperationRecorder::fail()`, which both the manager-driven finalizer (`selfhelp:plugin:run-operation`) **and** the async `plugin_ops` Messenger worker handlers (`InstallPluginHandler` / `UpdatePluginHandler` / `UninstallPluginHandler`) route their catch-all failure through. `fail()` is now terminal-idempotent: it records a terminal `failed` status + the final `plugin-operation-progress` event for a still-running operation, and it never overwrites or re-emits for an operation that already reached a terminal state (so a post-`finalize()` cleanup error can no longer flip a `succeeded` row to `failed`). The recorder's existing raw-DBAL fallback keeps this working even when the EntityManager is poisoned, and the recovery never masks the original error.
- **A single broken plugin no longer 500s the whole plugin list.** `PluginAdminService::listPlugins()` formatted every row eagerly, so one inconsistent bundle (e.g. a half-removed plugin whose `composer remove` ran but whose row/manifest was briefly out of sync during a manager-driven restart) threw and turned the entire admin **Plugins** screen into a dead "Failed to load plugins" error. Rows are now formatted defensively: a bad row is logged and skipped, and the operator still sees — and can repair / uninstall — everything else.

# v0.1.8

## System Maintenance

- **`system.maintenance_message` is offered in the CMS `{{ }}` editor**: the section editor's variable autocomplete (`DataVariableResolver`) now lists `system.maintenance_message` alongside the other `system.*` variables, so an operator designing the maintenance page can insert the live note from the suggestion dropdown instead of typing it from memory. The variable was already resolvable and allow-listed; this surfaces it in the picker and adds guard tests that pin the full chain — `MaintenanceModeService` -> `VariableResolverService` (`maintenance_message`, both the set value and the blank-note default) -> the `{{system.maintenance_message}}` render token — so the seeded maintenance page reliably shows the operator's message.

# v0.1.7

## Plugins

- **Plugin purge no longer returns a 500** (`Class "Symfony\Component\Process\Process" not found`): `symfony/process` was only a transitive **dev** dependency, so a production image built with `--no-dev` had no `Process` class. The synchronous purge / remove-package path (`PackageManagerRunner`, which runs in the web request rather than the Messenger worker) therefore fatal-errored on `POST /admin/plugins/{plugin}/purge`. `symfony/process` is now a direct production `require`, and a regression test asserts it stays in both the production `require` block and the locked `packages` section so the purge/remove flow keeps working on the shipped image.

## System Maintenance

- **Public maintenance page**: a new seeded, open-access `maintenance` CMS page is shown to visitors while the instance is in maintenance, instead of a bare `503`. Its content renders the operator's live note through a new `{{system.maintenance_message}}` interpolation variable (resolved from `MaintenanceModeService`, with a friendly default when the note is blank), so changing the maintenance message from the admin panel updates the page with no content edit. The maintenance `503` gate now exempts the `maintenance` page's own content fetch and the `languages` list the render needs, so the styled page is reachable during the outage. The frontend keeps a hardcoded fallback for when the seeded page is missing or unreachable.

# v0.1.6

## System Health

- **Worker health no longer falsely reports "not configured"**: the aggregated system-health probe (`GET /admin/system/health`, shown on the admin System and maintenance pages) checked a `MESSENGER_TRANSPORT_DSN` env var that the platform never sets, so **every** instance showed the `worker` component as `not_configured` even though the worker runs fine on its real transport. The probe now reads the single, authoritative worker transport env (`MESSENGER_PLUGIN_OPS_DSN`, with the same `doctrine://default` fallback the messenger config uses), so the worker reports `ok`/`configured` as expected. This was always cosmetic — `not_configured` never degraded the overall verdict — but it was alarming on the maintenance page. No parallel transport alias is introduced; the probe simply targets the transport that is actually running.

# v0.1.5

## System Updates

- **Frontend-only updates**: the frontend ships independently of the core, so an instance already on the newest core can now move to a newer compatible frontend without a full-stack update. New admin endpoints (guarded by the existing `admin.system.read` / `admin.system.update` permissions): `GET /admin/system/update/frontend/releases` (registry-published frontend versions, newest first; fails soft to `available: false` offline), `GET /admin/system/update/frontend/preflight?target=…` (a lightweight, stateless verdict — no destructive-migration/backup checks; downgrade + invalid-version are the only blocks, and an `unknown` installed frontend never falsely blocks), and `POST /admin/system/update/frontend/request` (records a `kind = frontend` operation; the request body omits `accepted_migration_risk` — a frontend swap is stateless). `system_update_operations` gains `kind` (`core` default / `frontend`) and `target_frontend_version`; `GET /admin/system/update/status` and the manager-claim payload now carry both fields. The SelfHelp Manager re-resolves the signed frontend release and performs the authoritative compatibility + signature check before swapping only the frontend container (rolling it back on a failed health check).

# v0.1.4

## System Updates

- **Manager-loop visibility**: a CMS-requested update that nobody picks up is no longer a silent black hole. The backend records when an authenticated SelfHelp Manager last polled the manager endpoints (cache key `selfhelp_manager_last_seen_at`), `GET /admin/system/health` gains a `manager_loop` component (`ok` / `not_configured` / `down` / `degraded`), and `GET /admin/system/update/status` gains a `manager` block (`configured`, `last_seen_at`, `requested_stale`) so the UI can warn when an operation sits unclaimed in `requested`.

## Plugins

- **Open-ended core compatibility policy**: plugin manifests should declare a minimum core version without an upper bound (`compatibility.selfhelp: ">=0.1.0"`); `pluginApiVersion` is the breakage contract and registry `blocked` flags/advisories handle retroactive breakage. Documented in `docs/developer/26-plugin-compatibility-rules.md` and the plugin developer guide.

# v0.1.3

## Development Environment

- **Pinned Docker image versions**: All third-party Docker images now use specific version tags instead of `latest` for reproducibility. Mailpit pinned to v1.30.1, Redis to 7-alpine, Mercure to v0.16.
- **Windows line ending fix**: Added `.gitattributes` to enforce LF line endings for shell scripts (`*.sh`), preventing Docker container execution failures on Windows due to CRLF line endings.

# v0.1.2

## Release Automation

- **Automatic core release candidates**: Tagging the backend now hands the new version + the three built image digests to the unified registry's `auto-core-release` workflow, which resolves compatibility against the latest published frontend and stages the signed core release as a reviewed PR. Publishing still requires a human to verify the digests and merge — the candidate is automatic, the publish is not.
- **`release-manifest.json`**: New self-declaration at the repo root with the SemVer ranges of the counterparts this backend supports (`supports.frontend`, `supports.manager`), the direct-upgrade floor, and the plugin API version. The registry resolver reads it at the released tag; widen/bump `supports.frontend` deliberately — every pre-1.0 minor is breaking.
- **Release consistency gate**: `docker-release.yml` now hard-fails when `selfhelp_cms_version_default` in `config/services.yaml` does not equal the tag, or when `release-manifest.json` `pluginApiVersion` drifts from `services.yaml`.

# v0.1.1

## System Maintenance & Updates

- **Registry-fed update picker**: New `GET /cms-api/v1/admin/system/update/releases` endpoint lists the core versions published in the official registry (newest first) so the admin "Request an update" picker offers real versions instead of free-typed guesses. Fails soft to `available: false` when the registry is unreachable.
- **Deployment kind in version summary**: `GET /admin/system/version` now reports `deployment: docker|source` so the admin UI can distinguish a managed Docker image install from a source/dev checkout. The production images bake `SELFHELP_DEPLOYMENT=docker`; everything else reports `source`.
- **Deterministic offline-registry tests**: The test environment now pins the registry base URL to a closed local port (`when@test` in `services.yaml`), so registry-dependent endpoints (advisories, preflight, releases) degrade offline the same way in CI and locally. Fixes the flaky `Advisories is admin only and degrades gracefully offline` security test.

## Security

- **Dependabot alerts resolved** (issue #55): all Symfony/Twig advisories fixed via the 7.4.13 / 3.27.1 patch line (`composer audit` is clean) and `aquasecurity/trivy-action` pinned to the safe `0.35.0` immutable release commit in `docker-release.yml`.

# v0.1.0

## Registration & User Management

- **Multi-group registration**: Users can now be enrolled in multiple groups at once during registration. Admins can select multiple groups in the register section, and new users are automatically added to all selected groups.
- **Open registration**: Admins can enable open registration that allows users to sign up with just their email address, no invitation code required. This is perfect for public-facing registrations.
- **Customizable registration labels**: All registration lifecycle labels (form fields, buttons, status messages) are now fully customizable through the CMS with support for multiple languages.

## Plugin System

- **Plugin registry integration**: Built-in plugin registry browser shows available plugins from configured sources. Browse, discover, and install plugins directly from the admin interface.
- **Official Humdek registry**: Pre-configured with the official Humdek plugin registry for easy access to trusted plugins.
- **System-managed plugin sources**: Core plugin sources are protected and can only be modified by system administrators, preventing accidental changes to critical registry configurations.
- **Improved plugin development**: Better support for local plugin development with automatic stylesheet URL resolution for live-reload during development.

## Cross-Repository CI/CD

- **Coordinated feature branch support**: CI workflows now support coordinated development across multiple repositories using the same branch name. Feature branches automatically validate against matching branches in sibling repos instead of always comparing to main.
- **Same-branch-or-main resolution**: Smart CI resolves sibling repository references to matching feature branches when available, falling back to main for solo branches or after merge.

## User Impersonation

- **Admin user impersonation**: Administrators can now impersonate any user to view the platform from their perspective. Perfect for troubleshooting user issues and providing support.
- **Audit logging**: All actions performed during impersonation are logged with both the original admin and the target user, maintaining a complete audit trail.
- **Real-time impersonation status**: Impersonation status is pushed in real-time via Mercure, so the UI immediately shows when impersonation is active.
- **Stop impersonation**: Dedicated endpoint to stop impersonation with proper JWT blacklisting.

## Real-Time Updates

- **ACL push notifications**: User permission changes are pushed in real-time via Mercure, eliminating the need for polling. When a user's permissions change, the UI updates instantly.
- **Impersonation notifications**: Real-time updates when impersonation starts or stops, providing immediate feedback to administrators and users.

## Security & Authentication

- **OAuth 2.0 compliant tokens**: Impersonation tokens follow RFC 8693 OAuth 2.0 Token Exchange standard for better compatibility and security.
- **Configurable token lifetimes**: All JWT token lifetimes (access, refresh, impersonation) are configurable via environment variables with sensible defaults.
- **Enhanced security**: Updated to latest Symfony security patches and dependency updates for improved security posture.

## Content Management

- **Complete style documentation**: Every CMS style is now fully documented with both administrator and developer perspectives, making it easier to understand and use the style system.
- **SEO improvements**: Page endpoints now return title and description metadata for better search engine optimization.
- **Canonical database schema**: Database tables and columns now follow consistent naming conventions (lowercase_snake_case) for better maintainability.

## Architecture & Performance

- **Doctrine migrations only**: Database bootstrap now uses only Doctrine migrations, eliminating the need for SQL bootstrap scripts and making upgrades more reliable.
- **Improved transaction handling**: All data-changing operations are wrapped in database transactions with comprehensive audit logging.
- **Performance optimizations**: Fixed N+1 query issues, added batch processing, and improved database query efficiency throughout the application.

## API & Integration

- **REST API v1**: Comprehensive REST API with versioning, JWT authentication, and refresh token support for secure third-party integrations.
- **Role-based access control**: Granular permission system with roles and permissions for fine-grained access control.
- **API request logging**: All API requests are logged for security auditing and debugging purposes.

## Documentation

- **Complete developer documentation**: Comprehensive documentation for authentication, authorization, plugin development, and CMS styling.
- **API usage guides**: Detailed guides for API endpoints, authentication flows, and common integration patterns.
- **Cross-repo compatibility**: Documentation for managing version alignment across the SelfHelp ecosystem.
