<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# CMS Apps — how to use them

Audience: CMS administrators and content editors (non-technical).  
Status: active.  
Applies to: SelfHelp2 Host Admin (`/admin/cms-apps`) and the public/CMS site shell.  
Last verified: 2026-11-13.  
Source of truth: Live Host Admin UI + first-class `cms_apps` backend behaviour.

This guide explains **CMS Apps**: little products inside your site (team directory,
news, FAQ, events, …) that have a public list, optional detail pages, and an editing
area under `/cms/...`. You do **not** need to write code.

## The three places (keep them separate)

| Place | What you do there |
| --- | --- |
| **CMS Apps** (Host Admin sidebar) | Set up the *app shell*: name, which pages belong to it, form structure, scaffold / import. |
| **Manage content** (`/cms/...`) | Add, edit, and delete *records* (people, posts, …) in a list — same site shell visitors use. |
| **Public pages** (`/team-members`, …) | What visitors see (lists and profiles). |

You never build a second “record editor” inside Host Admin. Records live on the
CMS surface, in **one list that holds the form**: the same fields are used to add,
to edit and to restore a record, and the public pages read the very same data.

## Import the Team Members demo (recommended first try)

1. Open **Admin → CMS Apps**.
2. In the **CMS Apps** sidebar header, click the **Import template** action  
   (or open **Pages → Import / export pages** and switch to **Examples**).
3. Choose **Team members**. Five more templates are there too: **News**, **FAQ**,
   **Events**, **Contact directory** and **Testimonials**. Each has its own public
   design and a feature of its own (portraits, filters, Cancel/Reopen actions,
   moderation).
4. On the Import tab, leave the suggested keyword/route prefixes as they are
   (they keep the demo off real URLs such as `/team-members`).
5. Leave **Import sample records** **on** so Ada, Alan, Grace, … appear immediately.
6. Click **Validate**, then **Import**.
7. After import, refresh **CMS Apps**. You should see **Team members** with a
   **Manage content** link (often under `/demo-team-members/cms/...` when using
   the gallery prefix).

Imported pages are **private** until you open them on purpose (Pages → the page →
access), so a fresh import never shows anything to the public by accident.

### If import says the slug is wrong

Slugs must be lowercase letters, numbers, and hyphens. The importer now
**normalises** gallery prefixes such as `demo_team_members_` into a valid app
slug automatically. If you still see an error, tell an administrator — the
backend may need updating.

### If import says the app already has pages

That slug is already used by an app with pages attached.

1. Open **CMS Apps**.
2. Open the app (or use the trash icon on the list).
3. Choose **Delete shell**.  
   **Important:** this only removes the app listing. Pages and records
   stay. You can then import again with a different keyword prefix, or clean up
   old demo pages under **Content Pages** if you no longer need them.

### Empty app you created by hand (“d” / “DD”)

1. Open the app → **Delete shell**, or use the trash icon on the list.
2. Or change its **slug** to lowercase kebab-case (e.g. `my-team`) with
   **Save metadata**, then use **Scaffold** or **Import template**.

## Day-to-day: manage records

1. In **CMS Apps**, open the app.
2. Click **Manage content** (opens `/cms/...` in a new tab).
3. Use **Add** for a new record and a row's **Edit** to change one — the form opens
   as a modal over the list. **Delete** asks for confirmation and removes the
   record from the list and from the public pages; a deleted record cannot be
   restored from the list.
4. Search, sort, the filters above the list and the page numbers work on the
   server, so they stay fast with thousands of records. The address bar keeps the
   state: you can bookmark a filtered view or send the link to a colleague.
5. **History** (when switched on for the list) shows who changed what and when,
   compares two versions and **restores** an earlier one. Restoring never erases
   history — it adds one more version. If a colleague saved first, you get a
   conflict message and keep your input; saving over theirs is your choice.
6. Some templates add **quick actions** to a row, for example **Cancel event** /
   **Reopen**, or **Approve** / **Hide** for a testimonial.
7. Open the public list URL (from the app’s public list page, or Live preview)
   to see the same records as visitors.

## Live preview (design check without leaving Admin)

1. Open the app in **CMS Apps**.
2. Click **Live preview** (eye icon on the list, or the button on the detail
   page).
3. You see the public list as visitors will see it, with draft / language
   tools from the preview bar.

## Create an empty app and scaffold

1. **CMS Apps → Create app** — enter a **Name**; the **Slug** fills in
   automatically (lowercase with hyphens).
2. Open the new app → **Scaffold**. The wizard shows the pages it will create:

   | Page | Route | What it is |
   | --- | --- | --- |
   | CMS list | `/cms/<base>` | One entry list that holds the form: add, edit, delete, restore |
   | Public list | `/<base>` | A read-only list of the same records |
   | Public detail | `/<base>/{record_id}` | One record |

3. Choose **Create a new form + data table** and list the form fields (text,
   text area, select, checkbox, radio, number). The form is defined **once**, inside
   the CMS list; the public pages read the same table, so no input is defined twice.
   Or leave it off and pick an **existing data table**: the CMS list then shows and
   deletes its records, but cannot add or edit them (there is no form to write
   them). A new form always needs the CMS list.
4. Or **Import template** instead of scaffolding.

## Delete an app

- **List:** trash icon → confirm **Delete shell**.  
- **Detail:** **Delete shell** → confirm.

Pages return under **Content Pages**. Form tables and records remain until you
delete those pages deliberately.

## Permissions (short)

Someone needs `admin.cms_app.*` rights to see and change CMS Apps. Editing
records on `/cms/...` uses normal page / CMS access for those pages; the record
buttons (add, edit, delete, history) follow the page's rights for each person.
Ask an administrator if buttons are missing.

## Draft and publish

When the wizard's **with status** option is on (the news and team templates
use it), each record has a `status` field: `draft` or `published` (default
`draft`). The CMS list shows every record and offers **Publish** /
**Unpublish** quick actions. Public list and detail pages only show
**published** rows (`list_fixed_filter` / `entry-record.fixed_filter`).

Once a record is published, **later edits are public at once** — there is no
separate "published version". Unpublish hides the record (and its images /
downloads) from the public pages again; the CMS list still shows it.

## Images and downloads

Wizard field types **image** and **files** create a `file-input` with
`files_public` on. Public cards and the detail page use `{{scope.field__url}}`
for the first file (image) and `{{scope.field__links}}` for the downloads
block (HTML links drawn by the ordinary `text` style). Anyone who may open
the page may open those files; a draft's files stay hidden with the record.
Image fields accept raster types only (JPEG, PNG, WebP, GIF) — SVG is refused
on upload and never shown inline on a public page.

## Data binding reminders

- The **CMS list** (`entry-form-list`) reads the table of its own form. The
  **public list** (`data-view`, or an `entry-list` for custom layouts) and the
  **detail page** (`entry-record`) take that table in **Data table** (the importer
  fills it in).
- A public **data view** can draw each record with your own design: set **Layout** to
  *Custom* and put sections (a card, a title, a button ...) inside the view, with the column
  names in double braces (`{{title}}`). The shipped Events, Contact directory and
  Testimonials templates do this; the template README lists a *compact* and a *spacious*
  set of classes for each template.
- **Detail pages** (`entry-record`) use **Load record from route parameter**
  (`load_record_from`, usually `record_id`) and an optional typed **`fixed_filter`**
  (same JSON shape as a list fixed filter). A record outside that filter is
  treated as missing: the page still answers **200** with an empty detail (same
  as an unknown `record_id`); its file URL answers **404**.
- **Public lists** (`entry-list`) may use an optional **Filter**; a `data-view`
  uses **list fixed filter** instead (the Testimonials wall shows only
  `status = Approved`; news/team public surfaces filter `status = published`).
- Select inputs keep a short **code** in the record; each language has its own
  label (see the option-label guide of the frontend docs).
- Older apps may still use an admin grid (`entry-table`, column mapping with
  `fields_map`); they keep working, new apps do not need it.

## Related

- Entry lists in detail (search, filters, history): [`entry-lists.md`](./entry-lists.md)
- Developer cookbook: backend `docs/cookbook/cms-in-cms-list-detail.md`
- Example bundle notes: frontend `examples/cms-in-cms/README.md`
