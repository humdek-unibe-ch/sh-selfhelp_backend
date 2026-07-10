Audience: SelfHelp engineers, product owners, and CMS UX designers
Status: archived — superseded by `docs/developer/28-navigation-pages-and-page-icons.md`
Applies to: SelfHelp backend page/navigation loading, web frontend navigation, and mobile app navigation as inspected on 2026-07-01
Last verified: 2026-07-01
Source of truth: Runtime code in `src/Service/CMS/Frontend/PageService.php`, `src/Entity/Page.php`, `migrations/Version20260630130327.php`, `../sh-selfhelp_shared/src/navigation/navRender.ts`, `../sh-selfhelp_shared/src/navigation/menuVisibility.ts`, `../sh-selfhelp_frontend/src/app/components/frontend/navigation/VirtualNavigation.tsx`, and `../sh-selfhelp_mobile/components/renderer/MobileNavRenderers.tsx`

# Page menu architecture brainstorm

## Why this exists

The current idea was: define on each page what menu it should be in, then build the website and mobile menus dynamically from pages.

After reading the backend and the web/mobile consumers, the more accurate current model is:

> A menu is not a separate backend object. It is a filtered view of the page tree.

That distinction matters. Pages own structure (`parentPage`), visibility (`nav_position`, `footer_position`, `is_headless`, ACL, platform access), and a small amount of presentation metadata (`icon`, `mobile_icon`, `web_nav_render`, `mobile_nav_render`). There is no first-class `menus` table, no `menu_items` table, no named menu slots, and no many-to-many relationship between pages and menus.

## What the backend actually returns

`GET /cms-api/v1/pages` and `GET /cms-api/v1/pages/language/{language_id}` return an ACL-filtered page tree.

The backend loading path is:

1. `PageController::getPages()` resolves the requested platform from `X-Client-Type`, `?platform`, legacy `?mobile`, or default `web`.
2. `PageService::getAllAccessiblePagesForUser()` calls `ACLService::getAllUserAcls()`, which uses the `get_user_acl` stored procedure.
3. The backend filters out pages that do not match the platform access type.
4. Non-admin callers only keep page types 2 and 3, which are core and experiment pages as inspected on 2026-07-01.
5. Page title and description translations are loaded in bulk.
6. Page property fields are loaded in bulk: `icon`, `mobile_icon`, `web_nav_render`, `mobile_nav_render`.
7. The result is nested by `id_parent_page` and sorted by `nav_position`, with null positions last.

Important implication: the backend tree contains accessible pages, not only menu pages. The clients decide menu membership using:

```text
navPosition != null AND is_headless == false
```

Footer membership is separate:

```text
footerPosition != null AND is_headless == false
```

## What the fields mean on 2026-07-01

`parentPage`
: Defines the page hierarchy. It is the only structural grouping axis for child navigation.

`nav_position`
: Means "this page is visible in navigation" and gives its order among siblings. It does not say which named menu the page belongs to.

`footer_position`
: Means "this page is visible in the footer" and gives footer order.

`is_headless`
: Removes the page from visible navigation. Headless pages can still be fetched directly if ACL/platform rules allow it.

`page_surface`
: Separates public website pages from CMS app pages for organization and ACL defaults. As inspected on 2026-07-01, it is not a rendered menu slot.

`web_nav_render`
: Presentation hint for how a page renders its menu-visible children on web.

`mobile_nav_render`
: Presentation hint for how a page renders its menu-visible children on mobile.

## Web behavior on 2026-07-01

The website header uses top-level menu pages only. It filters pages with `navPosition != null`, removes headless pages, then `WebsiteHeaderMenu` keeps only `parent_page_id === null`.

The global web menu groups top-level pages by `web_nav_render`:

- `header-dropdown` renders inline in the header.
- `tabs` renders inline as button-like links in the header.
- `sidebar-drawer` renders inside one slide-over drawer opened by a burger.
- `hero-cards` also renders inside that drawer.

For a page with menu-visible child pages, `DynamicPageClient` automatically renders a child navigation block:

- If the page has no body sections, the child navigation becomes the page body.
- If the page has body sections, a compact child navigation strip appears above the content.
- On web, the `tabs` renderer is link/button navigation, not true mounted tab panels.

## Mobile behavior on 2026-07-01

The mobile global shell has two realistic modes:

- `bottom-tabs`
- `drawer`

That shell setting is stored in `useMobileShellStore`, not in the backend page tree. The store comment explicitly says `segmented-tabs` and `hero-cards` are per-page renderers, not meaningful global shell renderers.

For a mobile page with menu-visible children, `CmsPageScreen` uses `mobile_nav_render`:

- `segmented-tabs` renders child pages in place with a top segmented control.
- `bottom-tabs` renders child pages in place with a bottom tab strip.
- `drawer` renders a drill-down list.
- `hero-cards` renders a drill-down card list.

As inspected on 2026-07-01, mobile menu links mostly derive routes from `keyword` (`/${keyword}`), while the backend and web have moved toward DB-resolvable `url` / `page_routes`. That is an architectural mismatch to keep in mind for CMS app list/detail pages and parameterized routes.

## Realistic support matrix

| Menu type | Realistic today? | Best current meaning | Main constraint |
| --- | --- | --- | --- |
| Web header dropdown | Yes | Global website top nav for root pages and one level of children | Only pages with `nav_position`; not a separate named menu |
| Web tabs | Partial | Link strip for a parent's children or top-level header group | Not true tab panels on web; each tab navigates |
| Web sidebar drawer | Partial | Link list for a parent's children, or global overflow drawer | Not a persistent admin-style sidebar layout |
| Web hero cards | Yes, as a launcher | Card grid for child pages or overflow global menu | Only page title/description/icon, not arbitrary dashboard actions |
| Mobile segmented tabs | Yes | In-place child-page switcher for a few children | Child pages must be menu-visible and keyword fetchable |
| Mobile bottom tabs | Yes for global shell, partial for child nav | Global app shell for first 3-5 root menu pages; child variant switches child content in place | Global mode is not backend-driven yet; only first 5 root pages fit |
| Mobile drawer | Yes | Global shell alternative or child drill-down list | Global drawer is instance/shell behavior, not a per-page slot |
| Mobile hero cards | Yes, as a launcher | Child-page card list for dashboards/app roots | Not a global shell menu |
| Footer menu | Yes | Separate footer link list | No independent footer grouping beyond `footer_position` |
| CMS app sidebar | No, not as a first-class system | Can be approximated with child drawer/list or authored sections | Needs either a real app shell/menu model or explicit CMS-authored navigation |
| Named menus or multiple menu membership | No | Not supported by current schema | Needs first-class menu/menu-item data |

## The architectural tension

The 2026-07-01 implementation mixes three ideas into one page tree:

1. Site map: which pages exist, with parent/child relationships.
2. Menu membership: whether a page appears in navigation, represented by `nav_position != null`.
3. Menu presentation: how a parent displays children, represented by `web_nav_render` and `mobile_nav_render`.

This works for simple website navigation, but it gets confusing for CMS apps because CMS apps often need:

- a launcher/dashboard page,
- list pages,
- detail pages,
- create/edit pages,
- modal pages,
- utility pages,
- and possibly a persistent app-side navigation.

Those are not all "menu pages". Detail/edit/modal pages are usually off-menu targets linked from content. A first-class app menu is different from the public website header. Trying to make every page answer "which menu am I in?" with only `nav_position` and `parentPage` is too weak for that.

## Recommended mental model

Use this model unless we decide to build first-class menus:

1. Global navigation is a shell concern.
   - Web: root pages with `nav_position` appear in the website header.
   - Mobile: root pages with `nav_position` appear in bottom tabs or drawer, depending on a global mobile shell setting.

2. Child navigation is a parent-page concern.
   - A parent page may render menu-visible children as tabs, segmented tabs, drawer/list, or hero cards.
   - `web_nav_render` and `mobile_nav_render` belong on the parent page and mean "how do I show my children?"

3. Footer navigation is a separate simple list.
   - `footer_position` is enough unless we need footer columns/groups later.

4. CMS app detail/create/edit pages should usually be off-menu.
   - They are reached from links, form redirects, list row actions, or modal behavior.
   - They should not automatically appear in global header/bottom tabs just because they are accessible.

5. CMS app dashboards should be authored or modeled as app roots.
   - For a simple app, make an app root page with child pages and render the children as hero cards or drawer/list.
   - For a rich dashboard with actions, use normal CMS sections (`card`, `button`, `link`, `entry-list`) rather than pretending page navigation is a dashboard builder.

## Design options

### Option A: Keep page-tree navigation and tighten semantics

No new backend schema.

What changes:

- Treat `nav_position` as "show in navigation", not "belongs to a menu".
- Keep `web_nav_render` and `mobile_nav_render` only as child-navigation presentation on parent pages.
- Move global mobile shell selection to a CMS preference or app setting, not per page.
- Update admin labels/help text so authors understand root navigation vs child navigation vs off-menu pages.
- Document that detail/create/edit/modal pages should have `nav_position = null`.

Best when:

- We only need simple public menus and simple app launchers.
- We want the smallest change with least release risk.

Limit:

- No page can belong to multiple named menus independently.

### Option B: Add a clearer page navigation intent

Small/medium backend change.

Possible fields:

- `navigation_visibility`: `global`, `child-only`, `footer-only`, `off-menu`
- or a simpler UI-only mapping over existing `nav_position` and `footer_position`

This keeps the page tree as the structure but gives admins a clearer model. It may still store mostly in existing columns.

Best when:

- The current admin UX is the main problem.
- We do not need reusable named menus.

Limit:

- It still cannot model "same page in header, bottom tabs, and a CMS app drawer with different labels/icons".

### Option C: First-class menus

Large backend/shared/frontend/mobile change.

New concepts:

- `menus`: code, label, surface, platform, slot, renderer, enabled.
- `menu_items`: menu, parent item, target page, label override, icon override, sort order, visibility rules.
- New endpoint, for example `/cms-api/v1/navigation/{slot}` or `/cms-api/v1/menus/{code}`.

Pages remain the content/routing/ACL unit. Menus become presentation/navigation units. Menu items can inherit page ACL by default.

Best when:

- We need separate website header, mobile bottom tabs, CMS app drawer, footer groups, and dashboard menus.
- Pages need to appear in multiple menus.
- We want menu labels/icons/order independent from page metadata.

Limit:

- This is a cross-repo contract change. It needs migrations, schemas, shared types, frontend/mobile renderers, compatibility floors, docs, and tests.

### Option D: CMS-authored navigation sections

No new backend navigation schema, but more authoring responsibility.

Use normal CMS content styles to create dashboards, launch pages, and app menus:

- cards,
- links,
- buttons,
- entry lists,
- grids,
- tabs style sections where content belongs on the same page.

Best when:

- The menu is really page content or a dashboard, not app chrome.
- Authors need arbitrary copy, descriptions, actions, and layout.

Limit:

- Less automatic. The CMS author must build the launcher page.

## My recommended direction

For the near term, use Option A plus Option D:

- Keep the current page tree as the source for global navigation and child navigation.
- Stop thinking of `web_nav_render` / `mobile_nav_render` as "which menu this page is in".
- Rename the admin mental model to:
  - "Show in navigation"
  - "Navigation order"
  - "Show in footer"
  - "Child navigation style: web"
  - "Child navigation style: mobile"
- Treat CMS app pages as:
  - app root page: can be menu-visible and use hero cards/drawer/list for children,
  - list page: usually menu-visible only if it is a real destination,
  - detail/create/edit/modal pages: off-menu,
  - rich dashboard: authored with sections.

This matches the current architecture and avoids pretending we already have a menu system.

Move to Option C only if we answer "yes" to one of these:

- A page must appear in more than one menu with different order/label/icon.
- Public website, mobile app, and CMS apps need independently managed menu trees.
- CMS app navigation must be persistent app chrome rather than authored page content.
- Footer needs grouped columns or independent menu item labels.

## Questions to decide next

1. Do we want menu membership to remain `nav_position != null`, with better labels, or do we want an explicit "navigation visibility" concept?

2. Should `web_nav_render` and `mobile_nav_render` be visible only when a page has children, so admins understand these fields style child pages, not the page itself?

3. Should the global mobile shell be a CMS preference: `bottom-tabs` vs `drawer`? Today it is a frontend/mobile store default, not backend-driven.

4. For CMS apps, do we want an automatic app root pattern: `/cms/team` has children `/cms/team/list`, `/cms/team/create`, `/cms/team/settings`, and the root renders hero cards or a drawer?

5. Should list/detail/create/edit pages generated by the CMS app wizard default to off-menu, with only the app root or list page visible?

6. Should parameterized pages such as `/team/{record_id}` ever be allowed in navigation, or should they always be off-menu and reached from records/actions?

7. Is a web "sidebar drawer" enough as a drawer/list of child links, or do we actually need a persistent split-pane sidebar for CMS app pages?

8. Do we need multiple named menus before 1.0, or can we postpone first-class `menus` / `menu_items` until after the page-tree model is clearer?

9. Should mobile navigation links use page `url` instead of keyword-derived paths everywhere, matching the backend DB route resolver and web frontend?

10. Should footer navigation stay as simple `footer_position`, or do we need footer groups/columns that would push us toward first-class menus?

## If we implement after this brainstorm

For Option A/D:

- No backend schema migration is required.
- Update admin UI labels/help text in the frontend.
- Update backend/reference docs so the runtime model is explicit.
- Add or adjust tests around `nav_position` menu visibility and child-navigation render fields.
- Consider a mobile fix to use page `url` for navigation links where possible.

For Option C:

- Generate a Doctrine migration with `php bin/console make:migration`; do not hand-name it.
- Add `menus` and `menu_items` tables using lowercase snake_case.
- Add a backend navigation service and API schemas.
- Keep page ACL as the default access gate for menu items.
- Update `@selfhelp/shared` types and render option contracts.
- Update web and mobile consumers.
- Update release manifests and the cross-repo compatibility matrix because this changes a frontend/backend contract.
- Add focused backend contract/permission tests plus web/mobile renderer tests.
