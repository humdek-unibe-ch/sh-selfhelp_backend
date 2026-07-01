<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# DB-driven public routing and the CMS-in-CMS app builder

Audience: Developers and technical operators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-30.
Source of truth: Runtime code, configuration, migrations, and tests in this repository (issue #30).

This document describes how **public page URLs** are resolved from the database,
how dynamic URL segments become interpolation variables, and how the CMS-in-CMS
"application builder" (page surfaces, the list/detail wizard, and page
export/import) is layered on top.

> Not to be confused with [`02-dynamic-routing.md`](02-dynamic-routing.md),
> which covers the **`/cms-api` API route** system (`api_routes` loaded by
> `ApiRouteLoader`). This document is about the **public content URLs** a
> visitor types (`/reset`, `/team-members/42`), resolved to CMS pages.

## Why

Public URLs used to be parsed in the frontend (slug → keyword), and parameterized
flows (password reset, account validation) hardcoded their `{user_id}/{token}`
shape on both the backend and the client. That coupled URL structure to client
code and made authored, parameterized public URLs impossible.

Routing is now **data**: every public page owns one or more rows in `page_routes`.
The backend resolves an incoming path to a page plus a map of `route_params`, and
those params flow into the interpolation context as `{{route.*}}`. Clients just
ask the backend "what page is at this path?".

## Data model: `page_routes`

| Column | Meaning |
|--------|---------|
| `id` | PK |
| `id_pages` | FK → `pages.id` (the owning page) |
| `path_pattern` | the public path with `{param}` placeholders, e.g. `/team-members/{record_id}` |
| `requirements` | JSON map `{param: regex}` constraining placeholders, e.g. `{"record_id":"\\d+"}` |
| `is_canonical` | exactly one canonical route per page; used to build the page's canonical URL |
| `is_active` | inactive routes are ignored at resolve time (kept for authoring) |
| `priority` | tie-break ordering when more than one pattern could match |

- Entity: `App\Entity\PageRoute`. Repository: `App\Repository\PageRouteRepository`
  (`findByPageId`, `findAllActivePatterns`, `findCanonicalForPage`).
- Placeholder token shape is `{name}`; the matcher accepts the flexible token
  charset `[A-Za-z0-9._~-]+` unless a tighter `requirements` regex is given.
- Route parameter names are **`snake_case`** (`user_id`, `token`, `record_id`)
  and are a cross-layer contract — they are never remapped on import/export and
  they are exactly the keys exposed as `{{route.<name>}}`.

## Resolution: `PageRouteResolverService`

`App\Routing\PageRouteResolverService` builds a Symfony `RouteCollection` from
every active `page_routes` row (ordered by `priority`, then specificity) and runs
the standard `UrlMatcher` against the incoming path. A match yields the target
`page` and the extracted `route_params`. The compiled collection is cached and
invalidated whenever routes change (`invalidate()`), so resolution is O(1) on the
hot path.

## Global uniqueness: `RouteConflictValidator`

`App\Routing\RouteConflictValidator` keeps the active route set unambiguous. For
a proposed set of patterns it reports:

- **duplicate** — the exact same `path_pattern` already exists (in the set or on
  another page);
- **ambiguous** — a different pattern with the **same path shape** (same number
  of static/placeholder segments) that the matcher could not disambiguate.

It is called by `PageRouteService::syncRoutes` (admin edits), by the import
validator, and by the wizard before any page is created. `findAllConflicts()`
powers a CLI/guard scan of the whole table.

## Resolve API: `GET /cms-api/v1/pages/resolve`

- Controller: `PageController::resolvePublicPath`. Route row `pages_resolve_path`.
- **Open-access API route** (no route permission, like `pages_get_by_keyword`).
- Query params: `path` (required), `language_id` (optional), `preview` (optional;
  `preview=true` requires authentication).
- Response: the standard `responses/frontend/get_page` envelope plus route
  metadata:
  - `route_params` — map of resolved params (omitted when empty);
  - `matched_url_pattern` — the `path_pattern` that matched;
  - `canonical_url` — the page's canonical path with params substituted.

**ACL is NOT bypassed.** The resolve route being permission-less only means
*anyone may ask*. The resolved content still applies the full page ACL,
published/draft + preview rules, platform/language gating, and data-access
security in `PageService::getPageByPublicPath()`. Unauthorized access returns
**404** (not 403) so the endpoint never leaks page existence.

## Dynamic params in content: `{{route.*}}`

`PageService::getPageByPublicPath()` threads the resolved `route_params` into the
interpolation context under the `route` scope, so:

- any field content can reference `{{route.record_id}}`, `{{route.token}}`, …;
- a section's `data_config` filter can bind to a param, e.g.
  `"filter": "record_id = {{route.record_id}}"`;
- the `route_params` are folded into the **page cache key hash**, so two requests
  to the same page with different params cache independently.

The `route` scope is **read-only context**: recursive section processing (loops,
nested holders) must not overwrite it. `processSectionsRecursively` guards the
`route` key so a `loop`/`entry-list` data scope can never shadow the URL params.

## Page surfaces: `public` vs `cms`

A lookup group `pageSurface` (`public`, `cms`) plus a nullable `id_page_surface`
on `pages` (default `public`) separates **public site** pages from **CMS-in-CMS
application** pages in the admin UI.

- `Page::getPageSurfaceCode()` / `setPageSurface*`; lookup constants
  `LookupService::PAGE_SURFACE_PUBLIC` / `PAGE_SURFACE_CMS`.
- `AdminPageService::createPage($keyword, …, $surfaceCode, $accessGroups)`:
  `cms` pages default to **admin/editor-only** ACL; `accessGroups` grants extra
  groups. `public` pages keep the public default ACL.
- The admin pages list groups top-level pages by surface so a builder's internal
  `/cms/...` pages don't clutter the public tree.
- `page_type` stays an internal classification (not surfaced as an authoring
  choice); `is_system` is untouched.

## Seeded parameterized auth routes

The password-reset and account-validation flows are now authored routes, not
hardcoded client logic:

- `/reset` → reset-password page; params `user_id`, `token` arrive via the route.
- `/validate/{user_id}/{token}` → validation page.

The `reset-password` / `validate` styles read `{{route.user_id}}` and
`{{route.token}}` instead of parsing the URL on the client.

## Entry styles as data/context holders

`entry-list`, `entry-record`, and `entry-record-delete` are **holders**: their
job is to bind a data scope (via `data_config`) and expose row/record fields to
their children. They carry no presentational fields of their own.

- **List** (`entry-list`): `data_config` scope `entries` over a table; each item
  links to the detail page with `/<base>/{{record_id}}`.
- **Detail** (`entry-record`): `data_config` scope `record`, `retrieve: first`,
  `filter: record_id = {{route.record_id}}`.
- `loop` is also a holder and must not overwrite the `route` context (see above).

See [`../reference/styles/composite.md`](../reference/styles/composite.md).

## CMS-in-CMS wizard: `POST /admin/pages/cms-app`

`CmsAppWizardService::createCmsApp()` (controller
`AdminPageController::createCmsApp`, permission `admin.page.create`) atomically
scaffolds a working list/detail pattern bound to a data table:

- an optional **create form** — `/cms/<base>/form` (with `create_form`);
- a **public** pair — `/<base>` (list) + `/<base>/{record_id}` (detail);
- an **admin** pair — `/cms/<base>` + `/cms/<base>/{record_id}`.

For each page it creates the page (with the right surface + ACL), its canonical
`page_routes` row, and the `entry-list` / `entry-record` holder + child template
(title, link, text) wired to the table. With `create_form` the wizard first
scaffolds an append (`form-log`) form page with one default `text-input`
(`form_field_name`, default `title`) and immediately materialises the table it
**owns** (via `DataTableService::createDataTableForFormSection` on the new form
section id); the list/detail pages then bind to that owned table, and the admin
list gets an inline `entry-record-delete`. Without `create_form`, `data_table` is
required and must already exist. Everything is conflict-checked up front; any
failure rolls back the pages created so far. The generated pages are ordinary CMS
pages, fully editable afterwards. Request fields: `base_name` (required),
`data_table` (required unless `create_form`), `create_form`, `form_field_name`,
`form_field_label`, `create_public`, `create_admin`, `record_id_param`,
`list_title`, `detail_title`, `access_groups`.

> **Future (frontend/plugin):** editing a *specific* record from the form
> (load-by-`record_id` upsert) and a SurveyJS smart-form variant — insert via
> SurveyJS, edit a loaded response, read the collected data unchanged on the
> public side — are intended evolutions of this scaffold; no backend contract
> change is expected beyond what the form styles already store.

## Page export / import (portable CMS-in-CMS bundles)

`PageExportImportService` serializes one or more pages — metadata, surface,
`page_routes[]`, content fields, and the full nested section/style tree (via
`SectionExportImportService`) — into a portable JSON **bundle**
(`format: selfhelp/page-bundle`).

Endpoints (admin):

| Route | Permission | Purpose |
|-------|------------|---------|
| `POST /admin/pages/export` | `admin.page.export` | build a bundle for the given page ids |
| `GET /admin/pages/{id}/export/suggest` | `admin.page.export` | suggest the related list/detail + `/cms` pages to bundle together |
| `POST /admin/pages/import/validate` | `admin.page.create` | dry-run validation (no writes) |
| `POST /admin/pages/import` | `admin.page.create` | recreate the bundle transactionally |

- **Validation** reports `error`/`warning` issues: empty bundle, missing/duplicate
  keyword, missing parent, invalid pattern/placeholder, route conflict, missing
  style, missing/unresolvable data-owner section, **translatable content in an
  uninstalled locale** (errors); missing canonical, undefined `{{route.*}}` param
  (warnings). Import refuses to run while any error remains.
- **Import options**: `keywordPrefix`, `routePrefix` (import a second copy without
  collisions), `skipConflictingRoutes`, `activateRoutes`, `importData`.
- Route **parameter names are never remapped**; ids/parent links/route targets are
  remapped to the new install.

### Portable data-table links (`@section:` owner token)

Core CMS forms write to a data table **named by the form section id**, so an
`entry-list`/`entry-record` `data_config.table` that points at an in-bundle form
section would break on import (the id changes). The exporter solves this without a
naming convention:

- **Export** rewrites any `data_config.table` that is a numeric id of a form
  section *inside the bundle* to the portable token `"@section:<owner section
  name>"`, and records that owner. Owner section **names must be unique** within
  the bundle (export fails loudly otherwise) so the token resolves
  deterministically. The page structure is always made portable this way,
  independent of whether data is exported.
- **Data is opt-in.** `options.includeDataTables` emits a `data_tables[]` block
  (one entry per owned table: `owner_section_name` + human `columns`);
  `includeDataRows` additionally exports `rows` keyed by human field name (read
  via `DataService` and remapped from the immutable `section_<id>` keys).
- **Import** builds a bundle-wide `source section name -> new section id` map as it
  recreates sections (`SectionExportImportService` now returns each section's
  original `source_name`), then relinks every `@section:` token to the new
  form-owned table id. With `options.importData` it (re)creates the owned table and
  re-inserts the rows through the **normal form-save path** (`DataService::saveData`
  remaps the human keys to the new `section_<id>` columns). An owner token /
  `data_tables[]` entry that does not resolve to an in-bundle section aborts the
  import.

### Out-of-band conflict guard

`app:page-routes:check-conflicts` runs `RouteConflictValidator::findAllConflicts()`
over every active route and exits non-zero on any duplicate/ambiguous set. It is
wired into `composer validate-db` so a raw SQL edit or restored backup can't leave
a self-inconsistent route table that the resolver would later choke on (the
resolver also defensively rejects same-shape dynamic ambiguity at resolve time).

### Example bundle

The example bundle now lives with all curated examples in the **frontend** repo
at `sh-selfhelp_frontend/examples/cms-in-cms/team-members.bundle.json` (see that
repo's `examples/README.md`). It
is a **self-contained** importable Team-Members app: a create form that owns the
table (referenced as `@section:team-members-form`), public list/detail, an admin
list with inline delete, and an admin detail — plus sample rows in `data_tables[]`.
It is **not auto-seeded** — import it via **Admin → Pages → Export / import**
(enable *import data* to seed the rows). See the recipe in
[`../cookbook/cms-in-cms-list-detail.md`](../cookbook/cms-in-cms-list-detail.md).

## Frontend & mobile

- **Frontend**: the catch-all route turns the slug into a path (`pathFromSlug`)
  and resolves it server-side via `GET /pages/resolve`
  (`resolvePageByPathSSRCached`), replacing the old keyword parsing. The
  `reset-password` / `validate` styles read `route_params`.
- **Mobile**: `pageService.resolvePageByPath()` resolves deep links by path;
  `native/deepLinks.ts` maps reset/validate links to the parameterized routes.

## Cross-repo compatibility

A new/changed `/cms-api` route or response field couples the clients to this core.
When the resolve endpoint, route metadata fields, or bundle contract change, bump
the `release-manifest.json` `supports.*` floors on both sides and update
[`cross-repo-compatibility-matrix.md`](cross-repo-compatibility-matrix.md).
