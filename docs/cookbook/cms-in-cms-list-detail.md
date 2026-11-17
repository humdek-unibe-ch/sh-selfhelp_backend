<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Recipe: build a list/detail "application" (CMS-in-CMS)

Audience: CMS administrators and developers.
Status: active.
Applies to: SelfHelp2 Symfony backend + frontend Host Admin.
Last verified: 2026-11-17.
Source of truth: Runtime `cms_apps` APIs, `CmsAppWizardService::scaffoldCmsApp`, and the linked architecture doc.

This recipe builds a working **list → detail** pattern bound to a data table —
the core "CMS-in-CMS" building block — using DB-driven public routes. For the
underlying architecture see
[`../developer/27-db-driven-public-routing.md`](../developer/27-db-driven-public-routing.md);
for the list styles themselves see [`../developer/form-lists.md`](../developer/form-lists.md).

## What you get

One dataset, two surfaces, **no input defined twice**. For a base name `team-members`:

| Page | URL | Surface | Role | Content |
|------|-----|---------|------|---------|
| CMS list | `/cms/team-members` | cms | `cms_list` | one **`entry-form-list`** that **is** the form: inputs sit directly inside it and it **owns the data table** |
| Public list | `/team-members` | public | `public_list` | a read-only **`data-view`** of the same table, every row links to its detail page |
| Public detail | `/team-members/{record_id}` | public | `public_detail` | an `entry-record`, normal shareable page |

The two surfaces are presented differently on purpose:

- The **CMS list** is where records are managed: **Add**, a per-row **Edit** and **Delete**,
  search, sort and filters on the server, paging, per-record **history** with restore, and
  optional **quick actions** (a one-click change of a field, e.g. *Cancel event*). The form
  opens in a **modal** (or on a page, `form_presentation`). Everything a list does goes
  through the ordinary form endpoints (`POST /forms/submit`, `PUT /forms/update`,
  `DELETE /forms/delete`), so access, conditions, validation, files, jobs and hooks behave
  as for any form; the list adds `GET /lists/{section_id}/state`.
- The **public list** only shows. It has no write control and no form; columns, sort, search
  and filters are declared on the section and run on the server. As a `data-view` it is a table,
  cards, or **a layout you compose**: with *Layout* = *Custom* the sections inside the view are the
  card of one record and the server draws them once per record (`{{title}}`, `{{record_id}}`,
  `{{_status_label}}` ...), so search, filters and paging stay the visitor's.

There is no separate form page and no separate CMS detail page: the list holds the form, and a
record is edited in the list. The roles `form` and `cms_detail` stay valid for apps assembled by
hand or imported from older bundles.

The detail page loads exactly one row: set **Data table** on the `entry-record`
section and **Load record from route parameter** to the route param name (usually
`record_id`). The backend reads that param from the matched URL and loads the row;
there is no author SQL **Filter** on `entry-record`. A blank `load_record_from` takes
the style default, `record_id`; when the matched URL has no parameter of that name the
section stays empty (fail-closed). Because `entry-record` has no filter, a detail page shows
any record whose address is requested: do not offer a detail page for data that has a
visibility state, such as a moderation status (the Testimonials template has none; its public
list is a `data-view` with a fixed filter).

> **Common mistake:** On `entry-list` / `entry-record` sections, authors sometimes
> configure **Data configuration** with a table and expect rows to appear. Since
> core `0.1.35`, row loading uses **only** the property fields (**Data table**,
> **Load record from route parameter** on detail, optional **Filter** on lists,
> **Own entries only**, …). Data configuration on the same section is for
> **helper scopes** on lists (e.g. a `filters` scope referenced as
> `{{filters.category}}` inside an entry-list **Filter**), not for choosing which
> table to hydrate. See [composite.md#entry-list](../reference/styles/composite.md#entry-list).

Select inputs keep a short **code** in the record; a composition shows the label of the visitor's
language through the hydrated token `{{scope._field_label}}` (`{{team_member._role_label}}`), also
under a list `scope`. A `data-view` and a list cell show the stored value as it is.

## Option A — CMS Apps (recommended)

1. Open **Admin → CMS Apps** (sidebar accordion).
2. Click **Create CMS app** and enter a display **name** + **slug** (empty shell
   only — no pages yet). You land on the app detail page.
3. Click **Scaffold** and fill in:
   - **Base name** — lowercase, hyphenated (e.g. `team-members`). Drives the
     keywords and URLs.
   - **Create a new form + data table** (`create_form`) — when on, the CMS list gets
     the fields of the multi-field builder as **direct children** (text, text area, select,
     checkbox, radio, number) and **creates and owns** the data table; leave **Data table**
     empty. It needs the CMS list (`create_admin`). When off, supply an existing **Data table**:
     the CMS list then shows, deletes and runs quick actions on those records but cannot add or edit them.
   - **Detail route parameter** — defaults to `record_id` (snake_case).
   - **Additional access groups** — optional groups on top of surface defaults
     (`cms` = admin/editor-only, `public` = public).
   - **List heading** / **Detail heading** — optional titles.
   - Toggle the **public list and detail pages** / the **CMS list** as needed (at least one).
4. Review the pages preview (roles, surfaces, routes), then generate.
5. Use **Manage content** on the app (or open `/cms/<base>`) to work with records in the
   entry list. Host Admin does **not** offer a second record CRUD UI.

Assigned pages appear under **CMS Apps**, not under Content Pages. Roles are
strict (`form`, `cms_list`, `cms_detail`, `public_list`, `public_detail`,
`other`); primary roles are unique per app. Deleting the app shell unassigns
pages and clears hubs — it does not delete pages or data. The wizard writes every page through
the same section import a bundle goes through, so the list configuration is checked by the same
validator, in one transaction (a failure leaves nothing behind).

API: `POST /admin/cms-apps` then `POST /admin/cms-apps/{id}/scaffold`.
Permissions: `admin.cms_app.*` (separate from `admin.page.*`).

## Option B — by hand

1. **Create the CMS page**: Admin → Pages → New, surface = *cms*, URL `/cms/team-members`.
2. Add an **entry-form-list** section (**List key** `team`, layout *table*, **Form presentation**
   *modal*, **List columns**, **History** on). Put the inputs **directly inside** the list (no nested
   form style): the list owns the data table on first submit.
3. **Create the public list page**: surface = *public*, URL `/team-members`. Add a **data-view**
   (or an **entry-list** for a composition that filters in SQL). For the data view set **Data table**
   to the list section of step 2, **List columns**, **Detail URL** `/team-members/{record_id}`.
   For a card of your own set **Layout** to *Custom*, put a `card` inside the view with a `title`
   (`{{name}}`) and a `button` to `/team-members/{{record_id}}`, and give **Record container
   classes** such as `grid grid-cols-1 md:grid-cols-3 gap-6`; use 6 to 12 records per page.
   For an `entry-list` set in the **Properties** panel:
   - **Data table** — the owning form's table (or pick from the dropdown).
   - **Own entries only** — off for a public directory; on for a personal journal.
   - **Filter** (optional) — SQL WHERE fragment; route tokens like `{{route.category}}` are validated server-side.
   - **Scope** (optional) — prefix row keys (`item.name` when scope is `item`).
   Inside it add a child template (e.g. a `card`) with a `title` (`{{name}}`) and a `link` whose URL is
   `/team-members/{{record_id}}`.
   Do **not** rely on **Data configuration** → *Table* for row binding (ignored on entry holders).
4. **Create the detail page**: surface = *public*, URL `/team-members/{record_id}`.
   In the page editor's **Routes** panel confirm the canonical route
   `/team-members/{record_id}` with requirement `record_id = \d+`.
5. Add an **entry-record** section. Set **Data table** to the same table and
   **Load record from route parameter** to `record_id`.
6. Add child fields that reference the record (`{{name}}`, `{{role}}`, …).

Older pages used an **entry-table** admin grid with `add_url` / `edit_url` pointing at a separate
create form and an `/cms/.../{record_id}` edit page (an `entry-record-form` section with
`load_record_from`). They keep working; a new app does not need them.

## Open in modal (web)

`open_in_modal` is a **page property** (Page editor → Properties → *Open in modal
(web)*). When enabled, the website renders that page's content inside a **modal
overlay** — the page title becomes the modal header, with a close button — instead
of a full page. The entry list opens its own form in a modal without this property
(`form_presentation`), so the property matters for pages you open from a link or a
button. It is **web-only**: the mobile app opens the page as a normal screen, and visiting
the URL directly still works (the modal renders over the app shell; closing returns to the
previous page).

Pair it with the form fields on a stand-alone create/edit form:

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

## Start from a template (recommended for a first app)

Six ready-made CMS-in-CMS templates ship with the CMS — complete apps with
translated content (de-CH and en-GB) and sample rows. All share the shape above (one CMS
`entry-form-list` that **is** the form, a public page over the same table); each has its
own public layout and a feature of its own:

| Template | Pages | Public surface | What it shows |
|----------|-------|----------------|---------------|
| **Team members** (flagship) | CMS list + public list + public detail | card grid (`entry-list`) + profile page | portraits (public `file-input`), role select with translated labels, translatable bio, role filter |
| **News & updates** | CMS list + public list + public detail | article cards + article page | category and tags with translated labels, date, translatable body |
| **FAQ accordion** | CMS list + public list | accordion, one item per row | category label per language, translatable question and answer |
| **Events** | CMS list + public list + public detail | event cards (`data-view`, custom layout: date and status badges, title, place, teaser, *View details*) + event page | location and date-range filters, **quick actions** *Cancel* / *Reopen*, status label |
| **Contact directory** | CMS list + public list | contact cards (`data-view`, custom layout: initials, name, department badge, phone and mail links) | server-side search and sort, department filter, translatable department |
| **Testimonials** | CMS list + public list | quote wall (`data-view`, custom layout: quote, avatar, author, role) | **moderation**: the public view has a fixed filter `status = Approved`; *Approve* / *Hide* quick actions in the CMS list |

1. Admin → Pages → **Export / import** (transfer icon) → **Start from template**
   tab (CMS Apps scaffold can also browse templates).
2. Pick a template — **Use this template** jumps to the Import tab with the
   bundle loaded and safe demo keyword/route prefixes pre-filled, so the copy
   never collides with existing pages and its internal links are rewritten to
   the prefixed routes automatically. Bundles that include a `cms_app` block
   recreate the app shell + roles on import.
3. Enable **import data** to seed the sample rows, validate, and import. The
   app is clickable immediately; open it under **CMS Apps** after import. Imported pages
   are private until an editor opens them.

The bundle files live in the **frontend** repo under
`sh-selfhelp_frontend/examples/cms-in-cms/` (see that repo's
`examples/README.md`); each is self-contained — the CMS list that owns the
table (inputs directly inside it), the public pages, with the list and detail bound through portable
`fields.data_table` (`@section:<slug>-cms-list`) tokens and sample rows in `data_tables[]`. The
backend keeps byte-identical copies under `tests/fixtures/examples/`; the frontend script
`scripts/build-cms-example-bundles.mjs` writes both. Nothing is auto-seeded.

To import a bundle **file** instead (e.g. one exported from another install):
Import tab → upload the file → the dialog runs a dry-run validation and shows
any issues → set prefixes if the routes are taken (or enable **skip conflicting
routes**) → confirm.

The owner token is relinked to the freshly-created list section automatically, so
the list/detail show the seeded rows immediately; list configuration (columns, sort,
controls, quick actions, fixed filters) is validated and its field keys relinked by the
same step. Content fields use the default-install locales `de-CH` and `en-GB`; if your
install lacks one, the import **fails naming the missing locale** — add the language
(or re-export without it).

## Export your own bundle

1. Admin → Pages → **Export / import** → **Export** tab.
2. Select the pages to include. Use **Suggest related** to auto-add a page's
   list/detail counterpart and its `/cms` pair.
3. Optionally enable **include data tables** (column definitions) and **include
   sample rows** to bundle the data owned by in-bundle form sections. Sample rows
   carry **every language** of a translatable cell (a locale map), so the bundle
   re-imports with its translations.
4. Download the bundle. It contains page metadata, surface, `page_routes`, content
   fields, and the full section/style tree — portable to another install. Any
   entry-style binding to an in-bundle form section is rewritten to the portable
   `@section:<owner>` token so it relinks on import even though section ids differ.
   Routes are exported as they are: importing the bundle twice with a prefix stacks
   the prefixes (`/b/a/team-members`).

## Notes

- **Route parameter names are a contract**: `record_id`, `user_id`, `token` are
  `snake_case` and identical across backend, frontend, and mobile. Export/import
  never renames them.
- **Access control still applies**: the public detail URL is reachable by anyone,
  but the page's ACL, publish state, and data-access rules are enforced; an
  unauthorized request returns 404.
- **Conflicts are rejected**: two active routes may not share the same path or the
  same path *shape*. The wizard and the importer check this up front.
