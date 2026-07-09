<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Recipe: build a list/detail "application" (CMS-in-CMS)

Audience: CMS administrators and developers.
Status: active.
Applies to: SelfHelp2 Symfony backend + frontend Host Admin.
Last verified: 2026-07-08.
Source of truth: Runtime `cms_apps` APIs, `CmsAppWizardService::scaffoldCmsApp`, and the linked architecture doc.

This recipe builds a working **list → detail** pattern bound to a data table —
the core "CMS-in-CMS" building block — using DB-driven public routes. For the
underlying architecture see
[`../developer/27-db-driven-public-routing.md`](../developer/27-db-driven-public-routing.md).

## What you get

For a base name `team-members`:

| Page | URL | Surface | When | Presentation |
|------|-----|---------|------|--------------|
| Admin create form | `/cms/team-members/form` | cms | with `create_form` | opens in a **modal** (`open_in_modal`) |
| Public list | `/team-members` | public | with `create_public` | `entry-list` of cards |
| Public detail | `/team-members/{record_id}` | public | with `create_public` | normal shareable page |
| Admin list | `/cms/team-members` | cms | with `create_admin` | `entry-table` **data table** |
| Admin detail | `/cms/team-members/{record_id}` | cms | with `create_admin` | **edit form** in a **modal** (`open_in_modal`) |

The two list surfaces are presented differently on purpose:

- **Public list** is an `entry-list` of **cards** (one per row) with an "Open"
  link to the shareable, full-page public detail (`/team-members/{{record_id}}`).
- **Admin list** is an `entry-table` **data table** with search, sorting,
  pagination, CSV export and an inline **delete**, plus an **"Add new"** button
  (`add_url` → the create form) and a per-row **edit** action (`edit_url` → the
  admin detail). The create form and the admin detail carry the `open_in_modal`
  page property, so on the website they open as **modal overlays** on top of the
  list; the create form closes its modal on save (`close_modal_on_save`) and the
  list refreshes automatically.

The detail page filters the table on `record_id = {{route.record_id}}`. With
`create_form` the form page **owns the data table** (it is named by the form
section id) and the other pages bind to that owned table automatically.

**Record editing** works out of the box: the wizard reuses the *same*
`form-record` section on the admin detail page, configured with
`load_record_from = record_id` and *Own entries only* **off**. Opening
`/cms/team-members/42` prefills the form with record 42 (all languages —
translatable inputs get their per-language values) and saving **updates** that
record instead of appending a new one. Updating another user's record requires
UPDATE permission on the form's data table; admins pass automatically. See
[forms.md#form-record](../reference/styles/forms.md#form-record).

## Option A — CMS Apps (recommended)

1. Open **Admin → CMS Apps** (sidebar accordion).
2. Click **Create CMS app** and enter a display **name** + **slug** (empty shell
   only — no pages yet). You land on the app detail page.
3. Click **Scaffold pages** (or open scaffold from the empty-state action) and fill
   in:
   - **Base name** — lowercase, hyphenated (e.g. `team-members`). Drives the
     keywords and URLs.
   - **Create form** — when on, scaffolds a form page that **creates and owns**
     the data table (plus inputs from the multi-field builder or a default
     **Form field name**); leave **Data table** empty. When off, supply an
     existing **Data table** to bind to.
   - **Detail route parameter** — defaults to `record_id` (snake_case).
   - **Additional access groups** — optional groups on top of surface defaults
     (`cms` = admin/editor-only, `public` = public).
   - Toggle **public** / **CMS (admin)** pairs as needed.
4. Review the pages preview, then run scaffold.
5. Use **Manage content** on the app (or open `/cms/<base>`) to work with
   records via the `entry-table` + modal form. Host Admin does **not** offer a
   second record CRUD UI.

Assigned pages appear under **CMS Apps**, not under Content Pages. Roles are
strict (`form`, `cms_list`, `cms_detail`, `public_list`, `public_detail`,
`other`); primary roles are unique per app. Deleting the app shell unassigns
pages and clears hubs — it does not delete pages or data.

API: `POST /admin/cms-apps` then `POST /admin/cms-apps/{id}/scaffold`.
Permissions: `admin.cms_app.*` (separate from `admin.page.*`).

## Option B — by hand

1. **Create the list page**: Admin → Pages → New, surface = *public*, URL
   `/team-members`.
2. Add an **entry-list** section. Set its `data_config`:
   ```json
   [{ "scope": "entries", "table": "team_members", "retrieve": "all", "current_user": false }]
   ```
3. Inside it add a child template (e.g. a `card`) with a `title` (`{{name}}`) and a
   `link` whose URL is `/team-members/{{record_id}}`.
4. **Create the detail page**: surface = *public*, URL `/team-members/{record_id}`.
   In the page editor's **Routes** panel confirm the canonical route
   `/team-members/{record_id}` with requirement `record_id = \d+`.
5. Add an **entry-record** section. Set its `data_config`:
   ```json
   [{ "scope": "record", "table": "team_members", "retrieve": "first", "current_user": false, "filter": "record_id = {{route.record_id}}" }]
   ```
6. Add child fields that reference the record (`{{name}}`, `{{role}}`, …).

Repeat with `surface = cms` and `/cms/...` URLs for an admin-only pair. For the
admin list, prefer an **entry-table** section (a real data table) instead of
an `entry-list`: point its `data_table` at the table, turn on `delete_entry`, set
`add_url` to the create form and `edit_url` to `/cms/team-members/{record_id}`
(single-brace `{record_id}` is substituted per row). For the admin detail, reuse
the create form's `form-record` section and set its **Load record from route
parameter** field (`load_record_from`) to `record_id` with *Own entries only*
off — the form then prefills and updates the addressed record.

## Open in modal (web)

`open_in_modal` is a **page property** (Page editor → Properties → *Open in modal
(web)*). When enabled, the website renders that page's content inside a **modal
overlay** — the page title becomes the modal header, with a close button — instead
of a full page. It is ideal for create/edit/detail pages opened from a list. It is
**web-only**: the mobile app opens the page as a normal screen, and visiting the
URL directly still works (the modal renders over the app shell; closing returns to
the previous page).

Pair it with the form fields on the create/edit form:

- **Close modal on save** (`close_modal_on_save`) — a successful submit closes the
  surrounding modal and refreshes the parent list.
- **Redirect on save** (`redirect_on_save`) — optional URL to navigate to after a
  successful submit instead (the parent list is still refreshed).

### Modal size (web)

Every page modal uses the **same chrome** (page title as the header, one close
button, a scrollable body), so all modals look identical. Authors only control
the **box size** with two optional page properties:

- **Modal width (web)** (`modal_width`)
- **Modal height (web)** (`modal_height`)

Each accepts:

- *empty* (the default) ⇒ **80%** of the viewport — so every modal is the same
  size unless you opt out;
- a **CSS length** ⇒ `80%`, `640px`, `48rem`, `90vw`, …;
- `auto` ⇒ the box **grows to fit its content**.

Whatever the value, the box is **capped at 90%** of the viewport (`90vw` / `90vh`)
so it never overflows the screen, and the body scrolls when the content is taller
than the (capped) box. These are **web-only**; the mobile app opens the page as a
normal screen and ignores them. Empty/unset means the frontend default, so
existing modal pages need no change.

The wizard sets all of this up automatically; by hand, just toggle the page
property and the two form fields.

## Start from a template (recommended for a first app)

Six ready-made CMS-in-CMS templates ship with the CMS — complete apps with
translated content and sample rows:

| Template | Pages | Pattern |
|----------|-------|---------|
| **Team members** (flagship) | form + public list + public detail + admin grid | card grid with avatar initials, role badges, contact links |
| **News & updates** | form + public list + public detail + admin grid | posts with category badge, date, translatable body |
| **FAQ accordion** | form + public list + admin grid | accordion, one item per data row |
| **Events** | form + public list + public detail + admin grid | date badge, location, teaser |
| **Contact directory** | form + public list + admin grid | compact cards with tap-to-call / tap-to-mail links |
| **Testimonials** | form + public list + admin grid | quote wall with avatars |

1. Admin → Pages → **Export / import** (transfer icon) → **Start from template**
   tab (CMS Apps scaffold can also browse templates).
2. Pick a template — **Use this template** jumps to the Import tab with the
   bundle loaded and safe demo keyword/route prefixes pre-filled, so the copy
   never collides with existing pages and its internal links are rewritten to
   the prefixed routes automatically. Bundles that include a `cms_app` block
   recreate the app shell + roles on import.
3. Enable **import data** to seed the sample rows, validate, and import. The
   app is clickable immediately; open it under **CMS Apps** after import.

The bundle files live in the **frontend** repo under
`sh-selfhelp_frontend/examples/cms-in-cms/` (see that repo's
`examples/README.md`); each is self-contained — a create/edit form that owns the
table, the public pages, and an `entry-table` admin grid, with entry styles
bound via the portable `"table":"@section:<form>"` token and sample rows in
`data_tables[]`. Nothing is auto-seeded.

To import a bundle **file** instead (e.g. one exported from another install):
Import tab → upload the file → the dialog runs a dry-run validation and shows
any issues → set prefixes if the routes are taken (or enable **skip conflicting
routes**) → confirm.

The owner token is relinked to the freshly-created form section automatically, so
the list/detail show the seeded rows immediately. Content fields use the
default-install locales `de-CH` and `en-GB`; if your install lacks one, the import
**fails naming the missing locale** — add the language (or re-export without it).

## Export your own bundle

1. Admin → Pages → **Export / import** → **Export** tab.
2. Select the pages to include. Use **Suggest related** to auto-add a page's
   list/detail counterpart and its `/cms` pair.
3. Optionally enable **include data tables** (column definitions) and **include
   sample rows** to bundle the data owned by in-bundle form sections.
4. Download the bundle. It contains page metadata, surface, `page_routes`, content
   fields, and the full section/style tree — portable to another install. Any
   entry-style binding to an in-bundle form section is rewritten to the portable
   `@section:<owner>` token so it relinks on import even though section ids differ.

## Notes

- **Route parameter names are a contract**: `record_id`, `user_id`, `token` are
  `snake_case` and identical across backend, frontend, and mobile. Export/import
  never renames them.
- **Access control still applies**: the public detail URL is reachable by anyone,
  but the page's ACL, publish state, and data-access rules are enforced; an
  unauthorized request returns 404.
- **Conflicts are rejected**: two active routes may not share the same path or the
  same path *shape*. The wizard and the importer check this up front.
