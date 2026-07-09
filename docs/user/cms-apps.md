<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# CMS Apps — how to use them

Audience: CMS administrators and content editors (non-technical).  
Status: active.  
Applies to: SelfHelp2 Host Admin (`/admin/cms-apps`) and the public/CMS site shell.  
Last verified: 2026-07-08.  
Source of truth: Live Host Admin UI + first-class `cms_apps` backend behaviour.

This guide explains **CMS Apps**: little products inside your site (team directory,
news, FAQ, …) that have a public list, optional detail pages, and an editing
area under `/cms/...`. You do **not** need to write code.

## The three places (keep them separate)

| Place | What you do there |
| --- | --- |
| **CMS Apps** (Host Admin sidebar) | Set up the *app shell*: name, which pages belong to it, form structure, scaffold / import. |
| **Manage content** (`/cms/...`) | Add, edit, and delete *records* (people, posts, …) in a table — same site shell visitors use. |
| **Public pages** (`/team-members`, …) | What visitors see (lists and profiles). |

You never build a second “record editor” inside Host Admin. Records live on the
CMS surface.

## Import the Team Members demo (recommended first try)

1. Open **Admin → CMS Apps**.
2. In the **CMS Apps** sidebar header, click the **Import template** action  
   (or open **Pages → Import / export pages** and switch to **Examples**).
3. Choose **Team members**.
4. On the Import tab, leave the suggested keyword/route prefixes as they are
   (they keep the demo off real URLs such as `/team-members`).
5. Leave **Import sample records** **on** so Ada, Alan, Grace, … appear immediately.
6. Click **Validate**, then **Import**.
7. After import, refresh **CMS Apps**. You should see **Team members** with a
   **Manage content** link (often under `/demo-team-members/cms/...` when using
   the gallery prefix).

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
   **Important:** this only removes the app listing. Pages and member records
   stay. You can then import again with a different keyword prefix, or clean up
   old demo pages under **Content Pages** if you no longer need them.

### Empty app you created by hand (“d” / “DD”)

1. Open the app → **Delete shell**, or use the trash icon on the list.
2. Or change its **slug** to lowercase kebab-case (e.g. `my-team`) with
   **Save metadata**, then use **Scaffold** or **Import template**.

## Day-to-day: manage people (records)

1. In **CMS Apps**, open the app.
2. Click **Manage content** (opens `/cms/...` in a new tab).
3. Use **Add** / row **Edit** / delete on the table — forms open as modals.
4. Open the public list URL (from the app’s public list page, or Live preview)
   to see the same records as visitors.

## Live preview (design check without leaving Admin)

1. Open the app in **CMS Apps**.
2. Click **Live preview** (eye icon on the list, or the button on the detail
   page).
3. You see the CMS list as it will look for editors, with draft / language
   tools from the preview bar.

## Create an empty app and scaffold

1. **CMS Apps → Create app** — enter a **Name**; the **Slug** fills in
   automatically (lowercase with hyphens).
2. Open the new app → **Scaffold** to generate form + CMS list/detail (+
   optional public list/detail) pages.
3. Or **Import template** instead of scaffolding.

## Delete an app

- **List:** trash icon → confirm **Delete shell**.  
- **Detail:** **Delete shell** → confirm.

Pages return under **Content Pages**. Form tables and records remain until you
delete those pages deliberately.

## Permissions (short)

Someone needs `admin.cms_app.*` rights to see and change CMS Apps. Editing
records on `/cms/...` uses normal page / CMS access for those pages. Ask an
administrator if buttons are missing.

## Related

- Developer cookbook: backend `docs/cookbook/cms-in-cms-list-detail.md`
- Example bundle notes: frontend `examples/cms-in-cms/README.md`
