<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Recipe: build a list/detail "application" (CMS-in-CMS)

Audience: CMS administrators and developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-30.
Source of truth: Runtime backend code (issue #30) and the linked architecture doc.

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
| Admin list | `/cms/team-members` | cms | with `create_admin` | `show-user-input` **data table** |
| Admin detail | `/cms/team-members/{record_id}` | cms | with `create_admin` | opens in a **modal** (`open_in_modal`) |

The two list surfaces are presented differently on purpose:

- **Public list** is an `entry-list` of **cards** (one per row) with an "Open"
  link to the shareable, full-page public detail (`/team-members/{{record_id}}`).
- **Admin list** is a `show-user-input` **data table** with search, sorting,
  pagination, CSV export and an inline **delete**, plus an **"Add new"** button
  (`add_url` → the create form) and a per-row **open** action (`edit_url` → the
  admin detail). The create form and the admin detail carry the `open_in_modal`
  page property, so on the website they open as **modal overlays** on top of the
  list; the create form closes its modal on save (`close_modal_on_save`) and the
  list refreshes automatically.

The detail page filters the table on `record_id = {{route.record_id}}`. With
`create_form` the form page **owns the data table** (it is named by the form
section id) and the other pages bind to that owned table automatically.

> Editing a *specific* existing record in the same form (load-by-`record_id`
> "upsert") needs the frontend form to honour a record route param; it is a
> documented future enhancement, and the natural home for a SurveyJS-style smart
> form. The scaffold today is **create** (append, in a modal) + **read**
> (cards / data table / detail modal) + **delete** (inline on the admin table).
> The admin per-row action **opens the read-only detail modal**.

## Option A — the wizard (recommended)

1. Open **Admin → Pages**.
2. Click the **Create list + detail pages** button (wand icon) above the page list.
3. Fill in:
   - **Base name** — lowercase, hyphenated (e.g. `team-members`). Drives the
     keywords and URLs.
   - **Create form** — when on, the wizard scaffolds an append form page that
     **creates and owns** the data table (plus one default input named by
     **Form field name**, default `title`); leave **Data table** empty. When off,
     supply an existing **Data table** to bind to.
   - **Detail route parameter** — defaults to `record_id` (snake_case).
   - **Additional access groups** — optional groups to grant access (on top of the
     surface defaults: `cms` pages are admin/editor-only, `public` pages public).
   - Toggle **public** / **admin** pairs as needed.
4. Review the **Pages that will be created** preview table, then **Generate pages**.
5. On success, use the quick links to open the public list and a detail page for a
   test record id.

The generated pages are ordinary CMS pages — open any of them in the page editor
to restyle, add fields, or change the binding. The wizard only scaffolds; it does
not lock anything.

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
admin list, prefer a **show-user-input** section (a real data table) instead of
an `entry-list`: point its `data_table` at the table, turn on `delete_entry`, set
`add_url` to the create form and `edit_url` to `/cms/team-members/{record_id}`
(single-brace `{record_id}` is substituted per row).

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

## Import the ready-made example

The ready-made example bundle now lives with all other curated examples in the
**frontend** repo at `sh-selfhelp_frontend/examples/cms-in-cms/team-members.bundle.json`
(see that repo's `examples/README.md`). It
is a **self-contained** importable Team-Members app: a create form that owns the
table, public list/detail, and an admin list (inline delete) + detail. The entry
styles bind to the form section via the portable `"table":"@section:team-members-form"`
token, and the bundle carries sample rows in `data_tables[]`. It is **not**
auto-seeded.

1. Admin → Pages → **Export / import** (transfer icon) → **Import** tab.
2. Upload the bundle file. The dialog runs a dry-run validation and shows any
   issues. Enable **import data** to also seed the sample rows.
3. If `/team-members` is already taken, set a **keyword prefix** and **route
   prefix** (e.g. `demo-` / `/demo`) to import a non-colliding copy, or enable
   **skip conflicting routes**.
4. Confirm import.

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
