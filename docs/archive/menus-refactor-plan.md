Audience: Developers and technical product owners.
Status: archived — implementation tracked in `docs/developer/28-navigation-pages-and-page-icons.md`
Applies to: SelfHelp2 menu/navigation refactor across backend, shared contracts, web frontend, and mobile app, as inspected on 2026-07-01.
Last verified: 2026-07-01.
Source of truth: runtime code in `src/Service/CMS/Frontend/PageService.php`, `src/Service/CMS/CmsPreferenceService.php`, `src/Controller/Api/V1/Admin/AdminCmsPreferenceController.php`, `migrations/Version20260630130327.php`, sibling frontend/mobile/shared navigation code, and the Mantine UI examples linked in this document.

# Menus refactor plan

## 0. Execution contract (non-negotiable)

**Single wave.** Backend, shared, frontend, and mobile ship together. **No backward compatibility** — no bridge for `nav_position`, `footer_position`, `web_nav_render`, `mobile_nav_render`, keyword-built mobile shell routes, exclusive `globalNavRender`, or dual-read of old page menu fields.

**Do not stop until done.** Implementation is incomplete until all of the following are true in the same coordinated change:

1. All four repos build and typecheck.
2. All new migrations have round-trip tests.
3. Focused PHPUnit / Vitest / mobile unit tests from §12 pass.
4. `composer phpstan` reports 0 errors.
5. `grep` shows no active-contract references to removed page menu fields.
6. `release-manifest.json` floors and `cross-repo-compatibility-matrix.md` are updated.
7. `docs/developer/28-*.md` rewritten and `29-navigation-menu-builder.md` added.

Phases in §11 are **dependency order only**, not separate releases.

### 0.1 Implementation snapshot (2026-07-01 code review)

Roughly **one third of the wave is in progress** across repos. Do not tag or release until every row below is **Done**.

| Area | Done | Still open |
| --- | --- | --- |
| **Backend schema** | `navigation_*` tables, lookups, system menus, data migration from `nav_position`/`footer_position`, `user_navigation_state`, `GET /navigation` route ([`Version20260701092652`](migrations/Version20260701092652.php)) | `search_index_entries` table; admin API routes + permissions migration; cleanup migration dropping `pages.nav_position`/`footer_position` + `get_user_acl` SP update |
| **Backend services** | [`NavigationMenuService`](src/Service/CMS/NavigationMenuService.php) (resolve, auto-include virtual children, `has_content`, cache), [`AdminNavigationService`](src/Service/CMS/Admin/AdminNavigationService.php) (CRUD/reorder/settings), [`NavigationAssignmentService`](src/Service/CMS/NavigationAssignmentService.php) (skeleton) | Wire `NavigationAssignmentService` into [`AdminPageService`](src/Service/CMS/Admin/AdminPageService.php); `AdminNavigationController` + JSON schemas; `NavigationSearchService` + index rebuild/invalidation; last-visited read/write endpoint; fix `manual_plus_suggestions` — today it auto-expands like `page_children` but plan says **builder suggestions only** |
| **Backend page contract** | Icons kept; public navigation schema | Remove `navPosition`/`footerPosition`/`web_nav_render`/`mobile_nav_render` from create/update schemas, [`PageService`](src/Service/CMS/Frontend/PageService.php), export/import, page versions; add `navigationAssignments` to create-page |
| **Shared** | [`INavigationPayload`](sh-selfhelp_shared/src/navigation/navigationPayload.ts), [`TWebHeaderPreset`](sh-selfhelp_shared/src/navigation/headerPreset.ts), [`branchNav.ts`](sh-selfhelp_shared/src/navigation/branchNav.ts), menu membership helpers | Remove active `navRender` exports from page types; add `pageUrlToMobileRoute()`; schema tests for navigation payload |
| **Web frontend** | [`getNavigationSSR`](sh-selfhelp_frontend/src/app/_lib/server-fetch.ts), [`WebsiteHeaderRenderer`](sh-selfhelp_frontend/src/app/components/frontend/layout/header/WebsiteHeaderRenderer.tsx) (all six presets stubbed), header uses `/navigation` | Replace [`VirtualNavigation`](sh-selfhelp_frontend/src/app/components/frontend/navigation/VirtualNavigation.tsx) with `PageBranchNav`; footer from `web_footer`; header search UI; **Navigation builder admin page**; remove page inspector `navPosition`/`footerPosition`; create-page navigation step; `AdminNavbar` driven by menu preview; start-page redirect at `/`; live preview navigation bridge |
| **Mobile** | [`useNavigation`](sh-selfhelp_mobile/hooks/useNavigation.ts), drawer + bottom tabs from payload, [`navigationUtils`](sh-selfhelp_mobile/components/shell/navigationUtils.ts) partial URL support, `isOnAnyMobileMenu` | Remove `globalNavRender` exclusive mode; URL→Expo route resolver (routes still keyword-based); upgrade `SegmentedChildPages`; holder/self-segment rules; cold-start from `startup`; reconcile static drawer screens (`index`/`menu`/`profile`) with CMS drawer; remove [`MobileNavRenderers`](sh-selfhelp_mobile/components/renderer/MobileNavRenderers.tsx) |
| **Hero / search / golden** | — | `hero-home.bundle.json` + guarded seed; content-index search endpoints + tests; `NavigationMenuBuilderWorkflowTest` golden |

### 0.2 Ordered execution checklist (single wave — follow in order)

Work **across all four repos in parallel** within each step; do not merge a step until its exit criteria pass.

1. **Lock contracts (shared first)** — `INavigationPayload` final; `navigationAssignments` request type; remove `TWebNavRender`/`TMobileNavRender` from active page types; `pageUrlToMobileRoute`; branch-nav helpers exported.
2. **Backend admin surface** — `AdminNavigationController`, permissions (`admin.navigation.read` / `admin.navigation.update`), request/response schemas, permission-matrix tests; wire `NavigationAssignmentService` into page create; admin preview + orphan warnings for hidden auto-children.
3. **Backend search + last-visited** — `search_index_entries`, `NavigationSearchService`, `GET /search` + `GET /search/pages`, index on publish/section save, `user_navigation_state` update on page visit, startup modes in `/navigation`.
4. **Backend cleanup** — stop writing/reading `nav_position`/`footer_position`/render fields; migration drops columns + updates `get_user_acl`; export/import bundle v2 `navigation` block.
5. **Web shell** — `PageBranchNav` in `DynamicPageClient`; footer renderer; wire `BurgerMenuClient` to full `web_header` tree on small screens; header search for `content_index`.
6. **Web admin** — Navigation builder UI (four menus + settings tabs); page inspector badges; create-page navigation step; remove `MenuPositionEditor` / drag-drop on page fields.
7. **Mobile shell** — drawer-only-when-items + tabs-only-when-items (both allowed); segmented sibling nav; modal predicate via `isOnAnyMobileMenu`; start pages + last-visited; URL routing.
8. **Hero template** — `hero-home.bundle.json`, guarded fresh-install seed, examples catalogue entry.
9. **Cross-repo release** — bump `supports.*` floors, rewrite doc 28, add doc 29, run verification gate (§15.5), grep shows zero old-field references.

**Exit gate:** all items in §0 opening list + §15.5 commands green.

**Confirmed architecture decisions (2026-07-01 review):**

- `profile-link` auth dropdown stays a **system page tree** outside `navigation_menus`.
- Web gets **automatic in-page branch nav** (`PageBranchNav`) with the same sibling/child resolution rules as mobile segmented nav.

---

## 1. Problem statement

The existing navigation model mixes three different decisions:

- Page structure: page parent/child relationships used for content organization and URL/context.
- Menu structure: which pages or links appear in each public menu, their order, and their menu nesting.
- Menu presentation: how the global website header and a page's own child pages should be drawn.

The page tree itself is good for authoring and routing. `PageService` already returns an ACL-filtered, platform-filtered page tree with real `url` values, `is_headless`, icons, and child pages. The architectural problem is that menu membership is still encoded on pages (`nav_position`, `footer_position`, and the proposed mobile menu fields). That turns every page into a bag of menu-placement flags and makes menu-specific ordering, labels, nesting, and mobile-vs-web behavior hard to reason about.

The refactor should make the global shell explicit:

- A global web header style is selected on the `web_header` menu in the menu builder.
- Mobile drawer and bottom tabs are configured in the navigation builder.
- A page appears in navigation only when a menu item references it, or when an auto-include rule expands it from a referenced parent page.
- Menu nesting is owned by menu items, with optional auto-sync from page children.
- Page-level child navigation render-mode fields are removed. Child and sibling navigation should be resolved automatically from the page tree and menu membership, or authored explicitly as page content.
- Search is an explicit, configurable feature with an ACL-aware scope.
- Hero pages are CMS content/templates, not a new meaning for `headless`.

## 2. Recommendation summary

Use a first-class menu builder. Pages should not own menu placement.

Add `navigation_menus` and `navigation_menu_items` tables. Seed system menus for:

- `web_header`
- `web_footer`
- `mobile_drawer`
- `mobile_bottom_tabs`

The admin UI should expose a dedicated "Navigation" / "Menu builder" page where users edit those menus. The menu builder owns ordering, nesting, external links, page links, automatic child expansion, and per-menu presentation settings. Page-linked menu items inherit their translated page title and page icons by default.

Introduce a new global web header contract instead of extending `web_nav_render`:

```ts
type TWebHeaderPreset =
  | 'simple'
  | 'dropdown'
  | 'mega-menu'
  | 'tabs'
  | 'double-dropdown'
  | 'double-mega-menu';
```

Remove `web_nav_render` and `mobile_nav_render` in the same breaking refactor. They add another hidden decision layer and are no longer needed once global menus, automatic mobile sibling segments, and normal page content templates handle the real use cases.

For mobile, the drawer and bottom tabs should be independent menus, not one exclusive mode. Both surfaces are available by default, but each renders only when that menu has at least one resolved item. Segmented child/sibling navigation becomes automatic shell behavior for relevant nested pages, not a page-level renderer setting.

## 3. Existing runtime facts

Backend:

- `PageService::getAllAccessiblePagesForUser()` returns the accessible page tree. It is not a menu-builder endpoint.
- Menu membership is derived from `nav_position != null && !is_headless`.
- Footer membership is derived from `footer_position != null && !is_headless`.
- `AdminPageController::createPage()` and `AdminPageService::createPage()` accept `navPosition` and `footerPosition`, so page creation is already coupled to the old menu model.
- Page nesting comes from `id_parent_page`.
- Real links come from `page_routes` / page `url`, not from keywords.
- `CmsPreferenceService` reads global fields from the `sh-cms-preferences` page and caches them under `cms_preferences`.
- `AdminCmsPreferenceController` exposes `GET /admin/cms-preferences` only. The frontend already has a PUT client, so update support is a known contract gap.
- `Version20260630130327.php` seeded `icon`, `mobile_icon`, `web_nav_render`, and `mobile_nav_render` as page property fields. Keep `icon` and `mobile_icon`; remove the render-mode fields from the active contract.
- Because backward compatibility is not required before release, `nav_position` and `footer_position` can be removed from the active contracts and replaced by the new menu builder.

Web frontend:

- `WebsiteHeader` is a Server Component and fetches menu/profile pages on the server.
- `WebsiteHeaderMenu` renders only top-level menu pages.
- `GlobalDynamicNav` groups top-level pages by `web_nav_render`, then renders dropdown/tabs inline and drawer/cards in a slide-over drawer.
- `VirtualNavigation` renders a resolved page's children inside the page body using `web_nav_render`.
- `SlugShell` uses Mantine `AppShell` and removes header/footer when `is_headless` is true.
- `CreatePageModal` includes header/footer menu positioning and generates child-page URLs from the parent page URL, for example `/parent/child`. The URL generation is worth keeping; the menu-position controls should be replaced.

Mobile:

- As inspected on 2026-07-01, the global mobile shell has one `globalNavRender` store value, defaulting to bottom tabs.
- The global shell supports bottom tabs and drawer, but the code treats them as exclusive.
- Per-page mobile child navigation supports segmented tabs, bottom tabs, drawer, and hero cards today. These renderer modes should be removed from the active contract and replaced by automatic sibling segments plus normal page templates.
- Mobile shell navigation still builds hrefs from keywords in `components/shell/navigationUtils.ts`; web uses real page `url` values. This should be fixed during the refactor if mobile participates.

## 4. Navigation concepts after the refactor

### Structure

Pages and menus become separate structures:

- Page structure lives on pages: `id_parent_page`, routes, content, ACL, headless/modal behavior, translated page title, and page icons.
- Menu structure lives on menu items: target page or URL, parent menu item, position, optional translated menu label for groups/external links or deliberate page-label overrides, optional icon override, and child expansion mode.
- A page can appear in zero menus, one menu, or many menus without changing the page itself.
- The same page can have different positions and nesting in web header, web footer, mobile drawer, and mobile bottom tabs. By default every page-linked item uses the page's translated title and icon. Different menu labels/icons are an advanced menu-item override, configured in the menu builder and translated through menu-item translations.
- Public menus use backend-resolved page URLs for web and a URL-to-Expo-route resolver for mobile.

Recommended orphan rule: if a menu item auto-includes page children but the page tree contains children that cannot be shown because of ACL/platform/headless rules, show an admin warning in the menu builder. Do not silently invent another menu placement.

### Global presentation

Global presentation is instance-level:

- Web header preset: stored on the `web_header` menu.
- Web header search: stored in navigation settings.
- Mobile drawer and bottom tab limits/behavior: stored on their menu definitions.

Global presentation should not be decided by individual top-level pages.

### Mobile menu placement

Mobile needs a different menu layer from web:

- Drawer menu: hierarchical, can show nested pages.
- Bottom tabs: flat, app-like, best for 3-5 important destinations.
- In-page segmented child navigation: shown at the top of a page or nested page to move across the current sibling group.

A page can be in the drawer, in bottom tabs, in both, or in neither. In a page inspector, we can still show read-only menu membership badges and quick links:

- In mobile drawer.
- In mobile bottom tabs.
- In web header.
- In web footer.

Editing membership happens in the menu builder, not through page-level fields. The page inspector can provide shortcuts: "add to menu", "remove from menu", or "open in menu builder".

### Child and sibling presentation

Child and sibling presentation should not be configured with page-level render-mode fields:

- Mobile nested pages use automatic top segmented sibling navigation when the current resolved menu/page branch has useful siblings.
- Holder pages are detected from content plus menu-visible children: if a page has no content and has visible child targets, the client can route to the first visible child.
- Web pages that need a dashboard/card landing view should author that view as normal CMS content sections or a reusable page template, not as a hidden navigation renderer.
- The site header, footer, drawer, and bottom tabs remain menu builder decisions.

## 5. Navigation builder data model

Recommended storage: add first-class navigation tables and a small settings table for start/search behavior. Do not store menu placement on pages.

Recommended admin UI: create a dedicated "Navigation" / "Menu builder" page in the CMS. It should have tabs or sections for Web header, Web footer, Mobile drawer, Mobile bottom tabs, Start pages, and Search.

Lookup rule: all stable keys and option fields must use the existing lookup pattern. Store lookup FKs in the database and expose lookup codes in API responses. Do not store raw enum strings in navigation tables.

Proposed `navigation_menus` table:

| Field | Scope | Type | Default | Notes |
| --- | --- | --- | --- | --- |
| `id_navigation_menu_key` | menu | FK lookup | seeded | Lookup type `navigationMenuKeys`, codes `web_header`, `web_footer`, `mobile_drawer`, `mobile_bottom_tabs`. API exposes the code as `key`. |
| `id_platform` | menu | FK lookup | seeded | Lookup type `navigationPlatforms`, codes `web`, `mobile`. |
| `id_surface` | menu | FK lookup | seeded | Lookup type `navigationSurfaces`, codes `header`, `footer`, `drawer`, `bottom_tabs`. |
| `id_preset` | menu | FK lookup nullable | menu-specific | Lookup type `navigationMenuPresets`; web header codes include `simple`, `dropdown`, `mega-menu`, `tabs`, `double-dropdown`, `double-mega-menu`. |
| `max_depth` | menu | nullable integer | menu-specific | `null` or `0` can mean unlimited where appropriate. |
| `item_limit` | menu | nullable integer | `5` for mobile tabs | Used by constrained surfaces like bottom tabs. |
| `is_system` | menu | boolean | `1` for seeded core menus | Prevents deleting core menu definitions while still allowing item edits. |
| `config` | menu | JSON nullable | `null` | Escape hatch for menu-specific settings that do not deserve columns yet. |

Proposed `navigation_menu_items` table:

| Field | Scope | Type | Default | Notes |
| --- | --- | --- | --- | --- |
| `id_navigation_menus` | item | FK | required | Owning menu. |
| `id_parent_item` | item | nullable FK | `null` | Menu nesting. |
| `id_item_type` | item | FK lookup | `page` | Lookup type `navigationMenuItemTypes`, codes `page`, `external_url`, `group`, later `action`. |
| `id_pages` | item | nullable FK | `null` | Target page when item type code is `page`. |
| `external_url` | item | nullable string | `null` | Target URL when item type code is `external_url`. |
| `icon_override` | item | nullable string | `null` | Advanced override. If empty, page items use page mobile/web icon where available. Not translatable. |
| `position` | item | integer | required | Order among siblings. |
| `id_child_source` | item | FK lookup | `manual` | Lookup type `navigationChildSources`, codes `manual`, `page_children`, `manual_plus_suggestions`. |
| `auto_include_depth` | item | nullable integer | `1` | Used when `child_source = page_children`. |
| `is_active` | item | boolean | `1` | Allows draft/disabled menu items without deleting. |

Do not store menu-item visibility as `all` / `guest` / `authenticated`. Visibility for page items is resolved dynamically from the linked page's ACL, access type, open-access flag, platform rules, and headless rules. Group items are visible only when they contain at least one visible child. External URL items are visible when active and their parent chain is visible; if an external link needs access control, attach it under a page-controlled branch or add a later explicit ACL guard that reuses page ACL rules.

Proposed `navigation_menu_item_translations` table:

| Field | Scope | Type | Default | Notes |
| --- | --- | --- | --- | --- |
| `id_navigation_menu_items` | translation | FK | required | Owning menu item. |
| `id_languages` | translation | FK | required | Language. |
| `label` | translation | nullable string | `null` | Required for `group` and `external_url`; optional override for `page` items. |
| `description` | translation | nullable string | `null` | Optional for mega menus, drawers, and search/result hints. |
| `aria_label` | translation | nullable string | `null` | Optional accessibility label when visible text is not enough. |

Label resolution:

- Page item with no translated menu label override: use the linked page's translated title, falling back to keyword.
- Page item with translated menu label override: use the menu-item translation for that language.
- Group or external item: use the menu-item translation.

Menu builder display-field UI:

- Page items default to "Use page title and page icon".
- A page item can enable "Custom menu label" when the menu really needs a shorter or context-specific label. That opens translated label fields for each active language.
- A page item can enable "Custom menu icon" when the footer/drawer/tab needs a different icon from the page default.
- Group and external URL items must define translated labels because they do not have page titles.
- The simple page-create flow should not ask for custom labels/icons unless the admin opens advanced menu item settings.

Proposed `navigation_menu_item_exclusions` table:

| Field | Scope | Type | Default | Notes |
| --- | --- | --- | --- | --- |
| `id_navigation_menu_items` | exclusion | FK | required | The parent menu item with `child_source = page_children`. |
| `id_pages` | exclusion | FK | required | Auto-included child page to hide from this menu branch. |

Use explicit `group` menu items for menu-only grouping. Do not use fake pages as headings just to make the footer, drawer, or mega menu look right.

Proposed `navigation_settings` fields:

| Field | Scope | Type | Default | Notes |
| --- | --- | --- | --- | --- |
| `id_web_header_search_mode` | global | FK lookup | `content_index` | Lookup type `navigationSearchModes`, codes `off`, `menu_pages`, `searchable_pages`, `content_index`. |
| `web_header_search_min_chars` | global | integer | `2` | Avoids noisy search calls. |
| `web_header_search_result_limit` | global | integer | `8` | Keeps header search compact. |
| `id_search_default_visibility` | global | FK lookup | `all_accessible_pages` | Lookup type `navigationSearchVisibility`, default policy for pages without an override. |
| `id_search_field_policy` | global | FK lookup | `all_display_text` | Lookup type `navigationSearchFieldPolicies`, codes `all_display_text`, `page_metadata_only`, later `custom`. |
| `web_guest_start_page_id` | global | FK page nullable | seeded home | Page shown for anonymous users who enter the website root. |
| `web_user_start_page_id` | global | FK page nullable | seeded home | Page shown for logged-in users who enter the website root. |
| `id_web_user_start_mode` | global | FK lookup | `fixed_page` | Lookup type `navigationStartModes`, codes `fixed_page`, `last_visited_then_fixed_page`. |
| `mobile_guest_start_page_id` | global | FK page nullable | seeded home | First app page for users who are not logged in, after public boot logic. |
| `mobile_user_start_page_id` | global | FK page nullable | seeded home | First app page after login or app launch with a valid session. |
| `id_mobile_user_start_mode` | global | FK lookup | `fixed_page` | Lookup type `navigationStartModes`, codes `fixed_page`, `last_visited_then_fixed_page`. |
| `id_mobile_start_page_source` | global | FK lookup | `same_as_web` | Lookup type `navigationMobileStartSources`, codes `same_as_web`, `custom_mobile_pages`. |
| `id_route_sync_old_route_policy` | global | FK lookup | `ask` | Lookup type `navigationRouteSyncPolicies`, codes `ask`, `keep_alias`, `remove_old_route`. |

Proposed page property fields that remain on pages:

| Field | Scope | Type | Default | Notes |
| --- | --- | --- | --- | --- |
| `search_visibility` | page | FK lookup | `inherit` | Lookup type `navigationSearchVisibilityOverrides`, codes `inherit`, `visible`, `hidden`. Defaults to global policy. |
| `icon` | page | existing string | nullable | Default web/menu icon for page-linked menu items. |
| `mobile_icon` | page | existing string | nullable | Default mobile icon for page-linked menu items. |

Fields to remove from pages:

- `nav_position`
- `footer_position`
- `web_nav_render`
- `mobile_nav_render`

Do not expose secrets through the public navigation payload. The public endpoint should return only menu/settings data that is safe for anonymous users.

## 5.1 Auto-linked page children

The menu builder should make later page-tree changes easy for admins.

Recommended behavior:

- A menu item linked to a page can set `child_source = page_children`.
- When enabled, the public menu expands that menu item with the linked page's child pages at render time.
- New child pages added later under that page automatically appear under the menu item, subject to ACL/platform/headless rules, `auto_include_depth`, and any menu-item exclusions.
- If `child_source = manual`, page children do not appear just because they exist. They appear only when the menu builder creates explicit child menu items.
- The CMS admin sidebar should show those auto-included children under the same menu item, even when they are virtual and not stored as explicit `navigation_menu_items` rows.
- Virtual child items should have direct links to edit the linked page.
- The menu builder should show a "Convert to explicit items" action when admins want custom labels, icons, or custom ordering for generated children.
- Auto-included children should support per-branch exclusions so an admin can hide one child without changing the page tree or every other menu.
- If `child_source = manual`, new child pages do not appear automatically, but the builder should show suggestions: "This page has 2 child pages not in this menu".

This gives authors both modes: automatic page-tree mirroring for simple sites, and explicit menu design for polished public navigation.

Grouping rule:

- Use item type code `group` for pure menu grouping such as footer columns, drawer categories, or mega-menu sections.
- Use `child_source = page_children` only when the menu should intentionally mirror part of the page tree.
- A page being a child page is not by itself enough to make it appear in a public menu. The menu must either contain an explicit child item or an auto-include rule from a parent item.

## 5.2 Admin sidebar integration

The admin sidebar should remain easy to browse, like the current "Menu Pages" section in the screenshot, but it should be driven by menu builder data instead of page-level `nav_position`.

Recommended sidebar sections:

- Navigation
- Web header
- Web footer
- Mobile drawer
- Mobile bottom tabs
- Content pages
- System pages

Behavior:

- Each public menu section renders the resolved menu item tree.
- Page-linked menu items navigate directly to `/admin/pages/{keyword}`.
- Each menu item should also expose a small action to edit the menu item itself in the menu builder.
- Auto-included child pages appear nested under their parent menu item.
- If a menu item points to an external URL or group, it opens/expands as a menu item but does not route to a page editor.
- Pages can show badges for every menu they appear in, for example "Web header", "Mobile drawer", "Mobile tabs".
- The page inspector can include "Open in menu builder" links for each membership.

When creating a child page:

- If the parent page appears in a menu item with `child_source = page_children`, the new child page appears automatically in that menu and in the admin sidebar.
- If the parent page appears only in manual menu items, the create flow should ask whether to add the child to the same menus.
- If the parent page appears in no menus, the child page stays content-only until explicitly added.

## 5.3 Web footer ownership

The web footer should move out of page-level fields too.

Recommended behavior:

- `web_footer` is a seeded system menu, just like `web_header`.
- Footer links are represented by `navigation_menu_items`, not by `pages.footer_position`.
- Footer items can point to pages, external URLs, or non-clickable groups.
- The same page can appear in the footer and header with different nesting and position. It uses the page's translated title and page icon by default; translated menu labels and item icons are advanced overrides configured on the menu item.
- Footer presentation belongs to the `web_footer` menu's `preset` / `config`, not to the linked pages.
- The old `footer_position` field should be removed from page create/update flows and response contracts in the breaking cleanup, or left unused only during an implementation bridge.

This matters because footer navigation is not always the same thing as page structure. Legal links, support links, social/external links, and grouped footer columns are menu design decisions. Pages should remain responsible for content, routes, ACL, and page-tree parentage.

## 5.4 Page creation and menu assignment

Page creation still needs a friendly way to add the new page to navigation, but it should create menu items instead of setting page fields.

Recommended create-page flow:

1. Create the page's identity: keyword, title fields, surface, access, headless/open access, parent page, and URL.
2. Show an optional "Navigation" step.
3. Let the admin choose one or more menu assignments: Web header, Web footer, Mobile drawer, Mobile bottom tabs.
4. For each selected menu, choose the parent menu item or root, position among siblings, label/icon override if needed, and whether child pages are manual or auto-included.
5. Save the page and requested menu items in one backend transaction when the assignments are submitted with page creation.

Recommended API shape:

- Replace `navPosition` and `footerPosition` in the create-page request with optional `navigationAssignments`.
- Each assignment should contain `menuKey`, optional `parentItemId`, optional `position`, optional label/icon overrides, and optional `childSource`.
- The backend should create the page first, then ask `NavigationMenuService` to create the menu items before committing.
- Existing page update flows should expose menu membership as badges and links, not editable `nav_position` / `footer_position` fields.

Recommended admin shortcuts:

- From the menu builder, "Create page here" should open the create-page modal with the target menu and parent item preselected.
- From the menu builder, "Add existing page here" should create a menu item that points to an existing page.
- From the page inspector, "Add to menu" should open the menu builder or a compact assignment dialog.
- From a page's child list, "Create child page" should preserve the parent-page URL behavior and then handle menu assignment with the rules below.

Child-page behavior:

- If the parent page is represented by a menu item with `child_source = page_children`, the new child page does not need its own explicit menu item. It appears automatically under that parent in the resolved menu.
- If the parent page is represented only by manual menu items, the create flow should ask whether to add the new child under the same menu branches.
- If the create action was launched from a specific menu item, default the matching menu assignment to enabled.
- If the create action was launched from generic "New page", default to no menu assignment and let the admin opt in.
- If the parent page is in multiple menus, show all matching menu branches with checkboxes so the admin can add the child to any subset.

This keeps the simple case simple while still letting a carefully designed footer, drawer, or bottom-tab menu differ from the page tree.

## 5.5 Menu nesting and page URLs

Menu nesting should not automatically rewrite page URLs.

Recommended rule:

- Page tree and page routes own URLs.
- Menu tree owns navigation presentation.
- Moving a menu item changes only the menu.
- Moving a page under another page can suggest or update the canonical route, because that is a page-tree change.

Reasons:

- The same page can appear in several menus at different depths.
- Footer grouping often has no relation to URL structure.
- Mobile drawer and mobile bottom tabs can intentionally present a different structure from the website header.
- Automatically changing URLs when a menu item moves would break saved links and make multi-menu placement ambiguous.

What the UI should do instead:

- When creating a child page under a parent page, keep the existing behavior: default the URL to the parent URL plus the child slug, for example `/parent/child`.
- When adding an existing page under a menu item whose target page has a different URL branch, show a warning or hint, not an automatic rewrite.
- Offer an explicit checkbox such as "Sync URL with page parent" when the admin creates a child page or moves a page in the page tree.
- When that checkbox is enabled, automatically update the canonical route during the save so the URL follows the selected page parent.
- If that sync changes an existing public route, ask whether the old route should remain as an alias/redirect or be removed. The global default can be stored with the route-sync policy lookup, but the save UI should still make the choice visible.
- If the selected menu parent is a group or external URL, do not infer a page parent or nested URL. Ask for the page parent separately or default to a root-level URL.

## 6. Public and admin API plan

Add a public-safe endpoint for renderers:

```txt
GET /cms-api/v1/navigation
```

Response shape:

```json
{
  "menus": {
    "web_header": {
      "key": "web_header",
      "platform": "web",
      "surface": "header",
      "preset": "dropdown",
      "items": []
    },
    "web_footer": {
      "key": "web_footer",
      "platform": "web",
      "surface": "footer",
      "items": []
    },
    "mobile_drawer": {
      "key": "mobile_drawer",
      "platform": "mobile",
      "surface": "drawer",
      "items": []
    },
    "mobile_bottom_tabs": {
      "key": "mobile_bottom_tabs",
      "platform": "mobile",
      "surface": "bottom_tabs",
      "item_limit": 5,
      "items": []
    }
  },
  "startup": {
    "web_guest_start_page": { "keyword": "home", "url": "/", "title": "Home" },
    "web_user_start_page": { "keyword": "home", "url": "/", "title": "Home" },
    "web_user_start_mode": "fixed_page",
    "mobile_guest_start_page": { "keyword": "home", "url": "/", "title": "Home" },
    "mobile_user_start_page": { "keyword": "home", "url": "/", "title": "Home" },
    "mobile_user_start_mode": "fixed_page",
    "mobile_start_page_source": "same_as_web"
  },
  "search": {
    "mode": "content_index",
    "default_visibility": "all_accessible_pages",
    "field_policy": "all_display_text"
  }
}
```

Backend implementation options:

1. Add a dedicated `NavigationMenuService` that resolves menu definitions and menu items.
2. Keep `CmsPreferenceService` for unrelated global CMS settings only.
3. Add `NavigationSettingsService` only if start/search settings become large enough to justify separation.

Recommended: create `NavigationMenuService` now. It should be the public source of truth for menu rendering on web and mobile.

Admin updates:

- Add admin CRUD endpoints for menus and menu items.
- Add reorder/move endpoints for drag/drop.
- Add a "resolve menu preview" endpoint so the builder can show explicit and auto-included items exactly as public clients will see them.
- Add routes/permissions such as `admin.navigation.read` and `admin.navigation.update`.

Recommended: use dedicated navigation endpoints instead of extending `PUT /admin/cms-preferences`.

Required backend work:

- Generate a Doctrine migration with the repository command.
- Create `navigation_menus`, `navigation_menu_items`, `navigation_menu_item_translations`, `navigation_menu_item_exclusions`, and navigation settings storage.
- Seed lookup rows for all navigation keys and option fields: menu keys, platforms, surfaces, presets, item types, child sources, search modes, search visibility policies, start modes, mobile start sources, and route-sync policies.
- Seed the four system menus.
- Seed the default web header preset as `dropdown`.
- Seed coherent fresh-install menu items: home in the web header, useful legal/support links in the footer when those pages exist, and a small mobile drawer/bottom-tab set when mobile is enabled.
- Remove page-level `nav_position`, `footer_position`, `web_nav_render`, and `mobile_nav_render` from active code, response schemas, request schemas, shared types, and frontend/mobile UI.
- Replace create-page `navPosition` / `footerPosition` request fields with optional `navigationAssignments`, or add an equivalent orchestration endpoint that creates the page and menu items in one transaction.
- Add route-suggestion logic for page creation and explicit page-tree moves, but do not change URLs during menu-only moves.
- Add a route-sync confirmation flow. If the admin checks "Sync URL with page parent", update the route automatically during that save operation and apply the configured old-route policy.
- Add content-index search endpoints and indexing logic as part of the first refactor wave.
- Add/modify JSON schemas under `config/schemas/api/v1`.
- Add API route rows through generated Doctrine migrations.
- Add navigation cache categories or use the existing page/frontend cache invalidation paths plus navigation-specific keys.
- Invalidate navigation and search caches after menu/item writes and after linked page writes that affect menu labels, icons, URLs, ACL, headless state, page children, sections, or translations.
- Do not expose `firebase_config` or other private settings through the public endpoint.

## 7. Web header presets based on Mantine

Reviewed Mantine UI references:

- `https://ui.mantine.dev/component/header-simple/`
- `https://ui.mantine.dev/component/header-menu/`
- `https://ui.mantine.dev/component/header-mega-menu/`
- `https://ui.mantine.dev/component/header-tabs/`
- `https://ui.mantine.dev/component/double-header/`
- `https://ui.mantine.dev/component/header-search/`

Recommended presets:

| Preset | Mantine fit | Supports child pages | Best use |
| --- | --- | --- | --- |
| `simple` | Header simple | Limited | Small sites with flat menus. Child pages should fall back to a drawer or inline dropdown. |
| `dropdown` | Header menu | Yes, depth 1-2 | Default. Best match for normal website navigation with nested page children. |
| `mega-menu` | Header mega menu | Yes, depth 1-2 | Sites with rich top-level sections, descriptions, icons, and many children. |
| `tabs` | Header tabs | Yes, but shallow | Sites where top-level sections behave like categories. Good for a few top-level pages. |
| `double-dropdown` | Double header + header menu | Yes, depth 1-2 | Sites needing a utility row plus main navigation. |
| `double-mega-menu` | Double header + mega menu | Yes, depth 1-2 | Larger public sites with utility links/search/language/profile on the top row. |

Decision: `dropdown` is the seeded default, but all presets in the table should be implemented as supported options in the web menu builder. This is a core CMS-builder feature, not a one-off theme setting.

`header-search` should not be a standalone preset. Search is a modifier that can be enabled for any preset where there is room. For cramped layouts, show a search icon that opens a command-style modal or popover.

Implementation notes:

- Use Mantine `AppShell.Header`, `Container`, `Group`, `Menu`, `HoverCard` or `Popover`, `Tabs`, `Burger`, `Drawer`, `ActionIcon`, `TextInput`, `Autocomplete` or `Combobox`.
- Keep a single `WebsiteHeaderRenderer` registry keyed by `web_header_preset`.
- Replace `GlobalDynamicNav` grouping with a single render pass over the resolved `web_header` menu tree.
- Keep server rendering in `WebsiteHeader` so the first paint still contains real navigation.
- Fetch `getNavigationSSR()` once and read `menus.web_header` plus startup/search settings from it.
- Hydrate the same config into a React Query key so client navigation, preview, and ACL refreshes stay consistent.

## 8. Nested menu tree rules

Build public menus from resolved menu items:

```ts
resolveMenu('web_header', { languageId, user, platform: 'web' });
resolveMenu('mobile_drawer', { languageId, user, platform: 'mobile' });
resolveMenu('mobile_bottom_tabs', { languageId, user, platform: 'mobile' });
```

Rules:

- Top-level header items are top-level `navigation_menu_items` for `web_header`.
- A menu item's children are explicit child menu items plus any auto-included page children requested by `child_source`.
- A page with child pages should still be clickable. In dropdown and mega presets, the label should link to the parent page and the chevron/hover opens children.
- Limit hover-based depth to `web_header_max_depth`. Deeper levels should link to the parent page, where page-level child navigation can take over.
- On mobile, drawer uses the `mobile_drawer` menu and can show deeper nesting.
- On mobile, bottom tabs use the `mobile_bottom_tabs` menu as a flat selected list. They should stay limited to 3-5 important destinations even if selected items link to nested pages.
- On mobile, child and sibling navigation is handled on the destination page by the segmented child-nav component.

## 8.1 Mobile drawer and bottom tabs

Mobile shell rules:

- The app shell should be drawer-capable when the resolved `mobile_drawer` menu has at least one item.
- The bottom tab bar renders when the resolved `mobile_bottom_tabs` menu has at least one item.
- Both can render at the same time: hamburger/header drawer for full navigation, bottom tabs for the most important destinations.
- If neither surface has assigned pages, the app renders no global mobile menu chrome. Direct page routes still work.
- There is no need for global "enable drawer" / "enable bottom tabs" switches in the first implementation; assigning pages is the switch.

Drawer rules:

- Build a nested tree from `mobile_drawer` menu items.
- Sort siblings by menu item `position`.
- Use indentation, active parent highlighting, and collapsible groups for children.
- Keep the drawer visually dense and native: icon, label, active indicator, enough touch height, and no web-like mega panels.
- If a child is drawer-visible but all ancestors are hidden, show an admin warning. Do not silently promote the child, because that makes the tree unpredictable.

Bottom tab rules:

- Build a flat list from top-level `mobile_bottom_tabs` menu items.
- Sort by menu item `position`.
- Enforce `mobile_bottom_tabs_limit`, default `5`.
- Use the page's mobile icon when set, else the web icon, else an initial.
- A bottom tab can point to a nested page if the author deliberately selected it. It still appears flat in the tab bar.
- Active matching should include descendants so a parent tab stays active when the user is inside that branch.
- If the current page is in both the drawer and the bottom tabs, both surfaces should show their active state.
- If a descendant is active, the drawer should mark the descendant and its ancestor chain. The bottom tab should mark the matching tab or the nearest matching ancestor tab.

Bottom tab target rules:

- If a bottom-tab page has no children, render that page normally.
- If a bottom-tab page has children and its own content sections, render a top segmented group with a self segment first, followed by its children. The self segment uses the page title by default.
- If a bottom-tab page has children but no content sections, treat it as a holder and automatically select the first menu-visible child. The segmented group contains only the children.
- If the selected bottom-tab target is itself a nested page, render that page normally and show its sibling group at the top when available.
- The renderer needs either `has_content` / `section_count` on the navigation payload, or it must decide after fetching the page content. Adding `has_content` to the page-list response is cleaner because it avoids a holder-page flash.

CMS page inspector changes:

- Replace page-level menu placement controls with read-only menu membership badges.
- Include quick actions: "add to menu", "open in menu builder", and "remove from menu" where permissions allow.
- Show whether membership is explicit or auto-included from a parent menu item.
- Keep the existing "Mobile child-page navigation" select for how this page shows children or siblings once the user is on the page.

## 8.2 Mobile segmented child and sibling navigation

The second screenshot points to the most important mobile behavior: when the user is on a nested page, the top segmented nav should show the relevant sibling group.

Resolution rule:

1. If the current page has menu-visible child pages, show those children as the segmented group.
2. Else, if the current page has a parent with menu-visible children, show the current page's siblings as the segmented group.
3. Else, render no segmented child nav.

This means `/test` shows `t1`, `t2` if those are children, and `/test/t1` still shows `t1`, `t2` at the top with `t1` active.

Layout rule:

- For 1-3 segments, distribute the buttons across the available width.
- For more segments, size each pill from its content, reduce horizontal padding, and allow horizontal scroll.
- Text should not be clipped. Prefer measured min widths, two-line fallback for long labels, and smaller text only at the last step.
- Keep touch targets at least native-friendly height.
- Active state should use the mobile theme accent, not hardcoded blue.
- Use the HeroUI Native adapter where it has a suitable segmented/tabs primitive. In the OSS build, a custom React Native `Pressable` row is acceptable, but it must use the same semantic colors, radii, spacing, and typography tokens.

Navigation rule:

- Tapping a segment should update the route using the backend-resolved page URL or a URL-to-Expo-route resolver, not by guessing `/${keyword}`.
- The segmented nav should work with deep links and refresh: the active segment is derived from the current resolved page.
- For deeper nesting, show the nearest useful sibling group at the top and leave the full hierarchy to the drawer.

## 8.3 Mobile route vs modal rule

The mobile app already has an important behavior: pages that are not part of navigation can open as modal/sheet pages instead of replacing the main screen. After adding first-class mobile menus, the predicate must become "is this page represented by any resolved mobile menu item?"

Recommended predicate:

```ts
isOnAnyMobileMenu(page) =
  resolvedMenus.mobile_drawer.containsPage(page.id) ||
  resolvedMenus.mobile_bottom_tabs.containsPage(page.id)
```

Rules:

- Check every page in the tree, including nested children.
- If the target page is on the drawer tree, route to it as a normal screen.
- If the target page is on the bottom-tab list, route to it as a normal screen.
- If the target page is in both menus, route normally and mark it active in both places.
- If the target page is not in either mobile menu, open it as a modal/sheet when the current mobile flow expects contextual pages.
- If a child page is visible in the drawer but not bottom tabs, it still counts as on-menu and should not open as a modal.
- If a child page is not visible in either surface, it remains off-menu and can open as a modal even if its parent is on-menu.
- Apply the same idea anywhere web has contextual/modal navigation: use the platform's own "in any resolved menu" predicate. For web this means the resolved web header/footer menus; for mobile it means the resolved drawer/bottom-tab menus.

Active state rules:

- Drawer: mark the exact active page and all active ancestors. Use a stronger background/left accent for the exact page and a quieter expanded/active style for ancestors.
- Bottom tabs: mark the exact page if it is a bottom tab. Otherwise mark the nearest ancestor that is a bottom tab.
- Segmented nav: mark the exact active child/sibling segment. If the parent self segment is active, mark that segment.
- Use semantic theme colors from the mobile adapter, not hardcoded colors, so light/dark and HeroUI Native Pro can style the same state.

## 9. Search design

There are three different products hidden inside "search":

1. Search menu pages.
2. Search all searchable pages by title/description.
3. Search rendered page content.

Recommended first-wave implementation: ship proper content search now. No compatibility bridge is needed, so the final architecture should include the content-index endpoint, ACL filtering, admin configuration, and header UI in the same refactor wave.

Search modes:

### Mode 1: menu-page search

Use the existing menu tree on the web client. Search title, description, keyword, and URL for pages visible in the menu.

Pros:

- No new search endpoint.
- ACL-safe because it uses already-returned accessible menu pages.
- Fast for compact header search.

Cons:

- Does not search pages that are intentionally off-menu.
- Does not search section content.

### Mode 2: searchable-page search

Add a public endpoint:

```txt
GET /cms-api/v1/search/pages?query=&language_id=&limit=
```

This searches page title, description, keyword, and URL for ACL-visible pages that pass `search_visibility`.

### Mode 3: content-index search

Add:

```txt
GET /cms-api/v1/search?query=&language_id=&limit=
```

This searches ACL-visible pages and selected section fields. It must:

- Use the same page access rules as page loading.
- Search published content for public users and preview content only for authenticated preview users.
- Search all published page metadata and section display-text fields by default.
- Exclude form submissions, data-table rows, user-generated records, credentials, tokens, hidden/internal fields, raw JSON configuration, and binary/media blobs unless a later setting explicitly includes a safe subset.
- Strip HTML and return short snippets, not raw full section bodies.
- Cache per language and preference version where possible, but apply ACL filtering at query time or per user scope.
- Invalidate on page, section, translation, ACL, and preference changes.

Recommended default: `content_index` with `search_field_policy = all_display_text`.

Pros of searching all display text:

- Users can find content even when the page is not in the header or footer.
- Admins do not need to mark every normal text section as searchable.
- Search behaves like a real website/app search instead of only a menu filter.
- It works well with CMS pages whose content changes without route/menu changes.

Cons and mitigations:

- It can return noisy results from button labels, captions, or repeated layout text. Mitigate with weighting: title first, headings second, body text third, small UI labels lower.
- It can become expensive if every request scans section translations live. Mitigate with an indexed/searchable projection that is invalidated on page/section/translation changes.
- It can leak sensitive content if ACL is not applied perfectly. Mitigate by reusing the same page access rules as page loading and by excluding submissions, records, hidden/internal fields, and secrets from the index.
- Snippets can look odd for complex components. Mitigate by storing normalized display text per section and returning short highlighted snippets only.

Admin configuration:

- Search can be `off`, `menu_pages`, `searchable_pages`, or `content_index`.
- Page-level `search_visibility` can be `inherit`, `visible`, or `hidden`.
- Global `search_field_policy` starts with `all_display_text`; a later `custom` policy can expose per-style field controls if admins need more precision.

## 10. Hero pages and headless pages

Do not make `headless` mean hero.

`headless` already means "render this page without global header/footer chrome". That is useful for modals, embeds, auth-like standalone flows, and special screens. If we overload it with "hero header", then authors lose a clean no-chrome flag and the shell has to infer content design from layout behavior.

Recommended hero model:

- A hero page is a normal page whose first section is a hero-style content block.
- A hero header is a reusable section/template, not a shell mode.
- Fresh installs should seed a polished hero home page by default. It must be usable without extra customization.
- If a page is headless and has a hero section, it renders as a full no-chrome landing page. That is a combination of two independent choices, not one flag.
- Web and mobile can use different start pages. A web home hero does not have to be the mobile onboarding page.
- If the same page should render differently per platform, use platform-aware fields/sections. If the user journey is actually different, use separate pages and point `web_guest_start_page`, `web_user_start_page`, `mobile_guest_start_page`, and `mobile_user_start_page` at the right targets.
- There is no separate "onboarding mode" or "mobile hero mode" in the architecture. Mobile onboarding is just the selected mobile guest start page.

Mantine hero examples reviewed:

- `https://ui.mantine.dev/component/hero-image-background/`
- `https://ui.mantine.dev/component/hero-content-left/`
- `https://ui.mantine.dev/component/hero-image-right/`
- `https://ui.mantine.dev/component/hero-bullets/`

Recommended implementation path:

1. Create a default `hero-home.bundle.json` using existing styles: `background-image`, `container`, `title`, `text`, `button`, `group`, `grid`, `image`, and `list`.
2. Seed fresh installs with that hero-home content for `home`, guarded so edited customer home pages are never overwritten.
3. Add the same bundle to the example catalogue so admins can re-import or clone it later.
4. Add a dedicated `hero` or `hero-header` style later only if templates built from primitives are too hard to author. A new style would require the full style contract: Doctrine migration, shared type, web renderer, mobile renderer or documented mobile fallback, docs under `docs/reference/styles/`, and tests.
5. Add mobile hero/onboarding examples to the same template catalogue, not as a separate architecture concept. Admins can assign one of those pages to `mobile_guest_start_page` when the app needs a different first screen.

The default hero should feel finished: real image support, readable overlay or content layout, primary/secondary actions, responsive mobile behavior, and no placeholder-looking copy or empty visual blocks.

Mobile hero design:

- Render mobile hero pages with HeroUI Native-compatible primitives and theme tokens, not web CSS assumptions.
- Use safe-area aware layout, a strong visual area, translated title/body text, primary and secondary actions, and optional feature bullets.
- Prefer one focused first screen over a long marketing landing page in the app.
- Support both shared responsive pages and mobile-specific pages. If mobile uses the same page as web, platform-aware fields/sections should keep it compact. If the journey is different, the admin selects a separate mobile page in the start-page dropdown.
- Provide at least two importable examples: a shared responsive hero home and a mobile-first onboarding/landing page.

## 10.1 Default start pages

Default start pages are a navigation concern, not a hero concern.

Recommended behavior:

- Web root `/`: resolve the user state. If the selected start page has URL `/`, render it at root. If it has another canonical URL, redirect to that URL.
- Direct web URLs: never redirect just because a default start page exists. If the user opens `/about`, render `/about`.
- Mobile app cold start without a valid session: route to `mobile_guest_start_page` after any required auth/anonymous policy checks.
- Mobile app cold start with a valid session: route to `mobile_user_start_page`.
- After login: route to the configured logged-in behavior for that platform, unless a safe redirect was explicitly requested.
- After logout: route to the guest start page for the active platform.

Admin configuration:

- Web guest landing page: page dropdown.
- Web logged-in landing page: page dropdown.
- Web logged-in start mode: `fixed_page` or `last_visited_then_fixed_page`.
- Mobile start page source: `same_as_web` or `custom_mobile_pages`.
- Mobile guest landing page: page dropdown, enabled when mobile uses custom pages.
- Mobile logged-in landing page: page dropdown, enabled when mobile uses custom pages.
- Mobile logged-in start mode: `fixed_page` or `last_visited_then_fixed_page`.

Last-visited behavior:

- Track the last normal page visited by each authenticated user per platform.
- Do not store modal-only, headless-only, denied, deleted, or unsafe redirect URLs as the remembered page.
- On login or app cold start, if `last_visited_then_fixed_page` is enabled and the remembered page is still accessible, continue there.
- If there is no remembered page or it is no longer accessible, fall back to the configured logged-in landing page.
- Store the page id plus resolved URL/keyword snapshot so the backend can validate access and the client can navigate without keyword guessing.

The config response should return resolved page metadata for these start pages and the selected start modes. Clients should not hardcode `home` once this exists.

## 11. Refactor phases

These phases are implementation order only. The target is one coordinated breaking refactor wave across backend, shared contracts, web, and mobile. Do not ship a long-term compatibility layer for `nav_position`, `footer_position`, keyword-built mobile routes, or the old exclusive mobile `globalNavRender`.

### Phase 0: locked decisions

Locked defaults:

- Web default: `dropdown`.
- Supported web header presets: `simple`, `dropdown`, `mega-menu`, `tabs`, `double-dropdown`, `double-mega-menu`.
- Mobile default: drawer and bottom tabs are available, but each renders only when at least one page is assigned to it.
- Search default: `content_index`.
- Search field policy: `all_display_text`.
- Storage: first-class navigation menus/items plus navigation settings.
- Hero: polished default hero home on fresh installs, plus importable examples.
- Start pages: configurable guest and logged-in page dropdowns for web and mobile.
- Logged-in start behavior: fixed page by default, optional last-visited-then-fixed fallback per platform.
- Mobile route handling: full URL-based adoption now, no keyword-built shell routes.
- URL sync: menu-only moves do not change routes; explicit page-tree sync asks the admin and can apply automatically when checked.

### Phase 1: shared contracts

- Add `TWebHeaderPreset`, `WEB_HEADER_PRESET_VALUES`, default constants, option metadata, and resolvers to `@selfhelp/shared`.
- Add `INavigationPayload`, `INavigationMenu`, and `INavigationMenuItem` shared types.
- Remove `TWebNavRender` and `TMobileNavRender` from the active shared navigation contract.
- Add helper functions for traversing resolved menu item trees.
- Add shared helpers for checking page membership in resolved menus and for resolving mobile child/sibling segmented groups.
- Add shared `isOnAnyMobileMenu` / `resolveMobilePagePresentation` helpers so route-vs-modal behavior is not duplicated.
- Add shared route/start-page types for resolved startup targets.
- Add shared search mode, search result, route-sync, and last-visited start-mode types.

### Phase 2: backend navigation builder

- Generate a Doctrine migration.
- Create `navigation_menus`, `navigation_menu_items`, `navigation_menu_item_translations`, `navigation_menu_item_exclusions`, and navigation settings storage.
- Seed lookup rows for all navigation keys and option fields.
- Seed the four system menus.
- Seed the default web header preset as `dropdown`.
- Seed `search_visibility` and the content-index search settings.
- Add `NavigationMenuService`.
- Add public `GET /navigation`.
- Add admin menu/menu-item CRUD, move, reorder, preview, and auto-include conversion endpoints.
- Add content-index search endpoints and index/projection maintenance.
- Add last-visited-page storage and update endpoint or event path.
- Replace page create/update `navPosition` / `footerPosition` handling with `navigationAssignments` or a transactional create-page-with-navigation command.
- Add backend validation that menu assignments reference existing system menus, valid parent menu items, and page targets visible on the intended platform.
- Add URL suggestion/support logic for child pages and explicit page-tree moves. Menu-only moves must not rewrite routes.
- Add/refresh JSON schemas.
- Add route/permission migrations.
- Add cache invalidation for menu writes and linked page writes.
- Include resolved page metadata and `has_content` / `section_count` in resolved menu items when needed.
- Include `has_content` or `section_count` in the mobile/frontend navigation payload if we want holder pages to auto-select their first child without a flash.
- Add admin warnings for auto-included page children that are hidden by ACL/platform/headless rules.

### Phase 3: web frontend

- Add `getNavigationSSR()` in `server-fetch.ts`.
- Fetch navigation in `WebsiteHeader`.
- Replace `GlobalDynamicNav` with `WebsiteHeaderRenderer`.
- Implement Mantine header presets.
- Render the resolved `web_header` menu tree and selected preset.
- Render footer from resolved `web_footer`.
- Add header search UI for `content_index`, with compact menu/search behavior for every header preset.
- Add CMS configuration UI for web/mobile landing pages, logged-in start modes, search mode, search field policy, and route-sync policy.
- Update live preview to pass/render navigation payload.
- Update page inspector to show menu membership badges and menu builder shortcuts.
- Replace create-page header/footer position controls with a navigation-assignment step that can target web header, web footer, mobile drawer, and mobile bottom tabs.
- Add menu-builder actions for "Create page here" and "Add existing page here".
- Replace `MenuPositionEditor` / `DragDropMenuPositioner` usage where it edits page fields. Drag/drop should move `navigation_menu_items` instead.

### Phase 4: mobile

- Fetch public navigation payload.
- Remove exclusive `globalNavRender` behavior.
- Remove page-level mobile child-nav render modes from the shell contract.
- Render drawer when `menus.mobile_drawer.items` is non-empty.
- Render bottom tabs when `menus.mobile_bottom_tabs.items` is non-empty.
- Build drawer from the resolved `mobile_drawer` item tree.
- Build bottom tabs from the resolved `mobile_bottom_tabs` top-level item list.
- Upgrade `SegmentedChildPages` into a themed child/sibling segmented nav with auto-fit, shrink, and horizontal scroll behavior.
- Implement bottom-tab holder rules: self segment when the tab page has content, first-child auto-selection when it is only a holder.
- Update mobile modal routing to use "in any mobile menu" instead of only the old single-menu predicate.
- Mark active state in every surface where the page appears.
- Use page `url` or a URL-to-Expo-route resolver for shell navigation instead of constructing paths from keywords.
- Apply configured mobile guest/logged-in start pages and optional last-visited behavior.
- Render mobile hero/start pages as normal CMS pages with HeroUI Native-compatible layout and tokens.
- Verify the screenshots' two states: full drawer tree plus bottom tabs, and nested page with top sibling segments plus bottom tabs.

### Phase 5: hero-home template

- Add `hero-home.bundle.json`.
- Seed fresh installs with the polished hero home page when the home page is still the untouched default.
- Add a mobile-first onboarding/landing page template to the examples catalogue so mobile can use the same hero page by default or a different selected page when configured.
- Expose it through example bundle listing.
- Document the template and the intended section structure.
- Wire default start-page preferences so templates can become the platform/auth entry pages without changing hardcoded routes.

### Phase 6: cleanup

- Remove the old `GlobalDynamicNav` grouping behavior.
- Remove page-level `nav_position`, `footer_position`, `web_nav_render`, and `mobile_nav_render` in code paths replaced by the menu builder.
- Generate the cleanup migration that drops the old page columns or seeded page-property rows after the new navigation tables own those decisions.
- Remove old request/response schema fields, entity getters/setters, service sorting logic, shared types, frontend page form fields, mobile store fields, and tests that only cover the old menu model.
- Remove page-create and page-update UI fields that directly edit header/footer positions.
- Archive or update `docs/developer/28-navigation-pages-and-page-icons.md` so it no longer describes the old dynamic-global-header model.

## 12. Tests and verification

Backend:

- Service tests for menu resolution, explicit items, auto-included page children, ACL/platform/headless filtering, and external/group items.
- Service tests for page-title/icon inheritance, translated menu label overrides, group/external translated labels, and dynamic ACL-derived visibility.
- Controller/API tests for public navigation payload.
- Admin CRUD/reorder tests for menus and menu items.
- Permission matrix for admin navigation update.
- Create-page tests for transactional `navigationAssignments`.
- Child-page tests proving auto-included menu children appear without explicit menu item rows.
- Auto-include exclusion tests proving hidden child pages stay out of the resolved branch.
- Route tests proving menu-only moves do not change page URLs, while explicit page-tree route sync does.
- Search tests for title/page search, all-display-text content indexing, ACL filtering, snippets, and invalidation.
- Last-visited start-page tests for valid remembered pages, denied/deleted pages, and fallback to configured logged-in page.
- Cache invalidation tests for menu writes and linked page writes.

Shared:

- Resolver tests for header presets and config defaults.
- Resolved menu helper tests for nesting, ordering, membership checks, and active ancestor matching.

Frontend:

- Unit tests for header preset dispatch.
- Unit tests for nested menu rendering data.
- Unit tests for header search UI in `content_index` mode.
- Unit tests for create-page navigation assignments and menu-builder "Create page here" defaults.
- Unit tests for landing-page configuration and route-sync UI choices.
- Playwright screenshots for `dropdown`, `mega-menu`, `tabs`, and mobile widths once implemented.

Mobile:

- Unit tests proving drawer and bottom tabs render only when assigned pages exist.
- Unit tests for drawer tree filtering, bottom tab list filtering, and per-menu ordering.
- Unit tests for segmented child/sibling group resolution.
- Unit tests for bottom-tab holder/self-segment behavior.
- Unit tests for `isOnAnyMobileMenu` and modal-vs-route presentation.
- Unit tests for active state when a page appears in drawer, bottom tabs, or both.
- Navigation tests proving page URLs resolve consistently without keyword guessing.
- Tests for mobile guest/logged-in start pages and last-visited fallback.
- Visual checks for drawer + bottom tabs together and nested-page top segments.

Docs:

- Update `docs/developer/28-navigation-pages-and-page-icons.md`.
- Add or update public/admin API docs.
- If adding a new style, update `docs/reference/styles/` in the same change.

Static analysis and checks after implementation:

- Backend: focused PHPUnit for touched services/controllers plus `composer phpstan`.
- Shared: typecheck and focused tests.
- Frontend: focused tests, typecheck/lint for touched code, and browser visual checks for headers.
- Mobile: focused tests/typecheck for shell navigation.

## 13. Compatibility and release notes

This is a cross-repo contract change. When implemented, update:

- Backend `release-manifest.json` `supports.frontend` if frontend depends on new backend config/search routes.
- Frontend `release-manifest.json` `supports.core` if frontend depends on new backend config/search routes.
- `docs/developer/cross-repo-compatibility-matrix.md`.
- `@selfhelp/shared` version if navigation config/header preset types ship through shared.

Pre-1.0 breaking cleanup is allowed by repository policy, and this refactor should use that freedom deliberately. The target release removes the old dynamic header grouping, page-level menu positions, and keyword-built mobile navigation in the same coordinated wave that adds the new navigation contract.

## 14. Resolved decisions

1. `dropdown` is the default web header preset. The other Mantine-inspired presets are still supported selectable options.
2. Search should be proper content search, not only menu filtering. The first complete refactor includes `content_index`.
3. Content search indexes all published display-text fields by default, with exclusions for submissions, records, hidden/internal data, secrets, raw config, and binary/media blobs.
4. Fresh installs get a polished hero home page by default. The same hero bundle is also importable for later customization.
5. Mobile adopts URL-based shell navigation in the same wave. No keyword-built route workaround remains.
6. CMS configuration exposes guest and logged-in landing pages. Logged-in users can use fixed landing pages or continue from the last valid visited page.
7. Web and mobile can share start pages by default, but mobile can select different guest/logged-in pages when needed.
8. The mobile segmented self tab uses the page title by default.
9. Child pages appear in a menu only when the menu says so: explicit child item or parent item auto-inclusion. Pure grouping uses `group` menu items, not fake pages.
10. URL sync is explicit. When the admin checks "Sync URL with page parent", the save updates the route automatically and asks what to do with the old route.
11. **`manual_plus_suggestions` is builder-only (Q1:B).** Runtime menu resolution treats it like `manual` — no auto-expansion of page children. The menu builder shows "child pages not in this menu" suggestions when this mode is selected.
12. **Mobile shell system routes (Q2:B).** `profile` stays a hardcoded system drawer/route (like web `profile-link`). Remove the static `menu` drawer screen once CMS `mobile_drawer` works. `index` is only the startup redirect hop, not a parallel navigation tree.
13. **Mobile URL routing (Q3:B).** Shared `pageUrlToMobileRoute(url, keyword)` maps canonical `page.url` to Expo routes (`/` → `index`, else `/(app)/{keyword}`). Wave 1 does **not** add a `[...slug]` file route; nested public URLs resolve by keyword lookup, not path segments.
14. **Double-header presets (Q4:B).** Utility row (logo area, search trigger, language, theme, auth) lives in `WebsiteHeader`; menu presets render only the navigation row below. Custom utility links from `web_header.config` are post-wave.
15. **Content search gate (Q5:B).** Default remains `content_index`, but header search UI ships only after the index subsystem and ACL integration tests pass.
16. **Column drop timing (Q6:B).** Feature migrations land first; a **final cleanup migration** in the same PR drops `nav_position`/`footer_position` columns and updates `get_user_acl` only after all four repos are grep-clean on removed fields.

## 15. Architectural review — drawbacks, risks, and fit (2026-07-01)

This section records what a full four-repo code review found. None of these block the refactor; they define extra work the implementation plan must include.

### 15.1 Fits the architecture well

| Decision | Why it fits SelfHelp2 |
| --- | --- |
| First-class `navigation_*` tables | Matches lookup-FK pattern, Doctrine entities, thin controllers, `CacheService` categories — same as pages, plugins, scheduled jobs. |
| `GET /navigation` separate from `GET /pages` | `PageService::getAllAccessiblePagesForUser` is an ACL page **tree**, not a menu contract. Keeping `/pages` for profile tree, admin browse, keyword flatten, and route checks is correct. |
| Menu preset on `web_header` menu row | Replaces the broken model where each top-level page picked its own `web_nav_render` and `GlobalDynamicNav` grouped them ([`VirtualNavigation.tsx`](../sh-selfhelp_frontend/src/app/components/frontend/navigation/VirtualNavigation.tsx)). |
| `navigation_settings` singleton table | Better than extending `sh-cms-preferences` page fields: start-page FKs, search modes, and route-sync policy need real FK constraints. `CmsPreferenceService` stays for language/timezone/firebase only. |
| Auto-include virtual children | Elegant alternative to duplicating every child as a menu row; admin preview endpoint must materialize virtual items for builder UI (§5.1). |
| `profile-link` outside menu builder | Auth dropdown is a system concern ([`selectProfilePages`](../sh-selfhelp_frontend/src/utils/navigation.utils.ts), seeded in `Version20260501000600`). Mixing it into public menus would confuse ACL and admin UX. |

### 15.2 Drawbacks and mitigations (must be in implementation)

| Drawback | Evidence | Mitigation |
| --- | --- | --- |
| **Reverses shipped 0.1.31 nav-render work** | [`Version20260630130327`](migrations/Version20260630130327.php), [`docs/developer/28-navigation-pages-and-page-icons.md`](docs/developer/28-navigation-pages-and-page-icons.md) | Deliberate pre-1.0 break. Rewrite doc 28 in same PR. |
| **Content-index search is the largest subsystem** | No search index exists today; only admin list `search` query params | `NavigationSearchService` + `search_index_entries` table; index on publish/section save; integration tests before header UI ships. |
| **`get_user_acl` stored procedure still returns `nav_position` / `footer_position`** | [`migrations/Version20260501000000.php`](migrations/Version20260501000000.php) baseline SP | New migration alters SP after column drop; update [`RoleDataAccessRepository`](src/Repository/RoleDataAccessRepository.php) selects. |
| **Dual fetch on clients** | Mobile: [`usePages`](sh-selfhelp_mobile/hooks/usePages.ts) everywhere; web: [`useAppNavigation`](sh-selfhelp_frontend/src/hooks/useAppNavigation.ts) | Add `useNavigation()` / `getNavigationSSR()`; keep `/pages` for tree/profile/routes; invalidate both caches together. |
| **"Menu-visible" for branch nav is subtler than page-tree children** | Segmented nav must not show off-menu page children | Backend resolver exposes `branch_nav_group` per page OR shared helper walks resolved menus + auto-include rules (§8.2). Document predicate in shared. |
| **Mobile keyword hrefs break `page_routes`** | [`getPageHref` → `/${keyword}`](sh-selfhelp_mobile/components/shell/navigationUtils.ts) | Shared `pageUrlToMobileRoute(page.url)`; shell + segmented nav use `page.url` only. |
| **`open_in_modal` vs off-menu modal overlap** | Web: page property opens CMS modal; mobile: off-menu opens sheet | Web: `open_in_modal` OR `!isInResolvedMenu`; mobile: `isOnAnyMobileMenu` only. Document in §8.3 / web branch-nav docs. |
| **Six Mantine header presets + responsive burger** | [`BurgerMenuClient`](sh-selfhelp_frontend/src/app/components/shared/common/BurgerMenuClient.tsx) is currently a no-op toggle | Presets must include **mobile-web** collapse: `simple` / cramped layouts open drawer with full `web_header` tree. |
| **Admin sidebar duplication for legal footer pages** | [`AdminNavbar`](sh-selfhelp_frontend/src/app/components/cms/admin-shell/admin-navbar/AdminNavbar.tsx) intentionally lists footer legal pages in both Footer and System→Legal | Preserve when driving sidebar from menu preview; legal system pages may also appear as `web_footer` menu items. |
| **CMS app wizard creates pages without menu items** | [`CmsAppWizardService`](src/Service/CMS/Admin/CmsAppWizardService.php) uses `AdminPageService::createPage` | Wizard pages stay off public menus by default (correct for CMS-in-CMS). Optional follow-up: wizard checkbox "Add list page to web header". Not required for wave 1. |
| **Page export/import + page versions** | [`PageExportImportService`](src/Service/CMS/Admin/PageExportImportService.php), [`PageVersionService`](src/Service/CMS/Admin/PageVersionService.php) snapshot `nav_position` | Bundle format v2: `navigation` block with menu assignments; version diff drops nav columns. |
| **Last-visited needs new storage** | No `user_navigation_state` today | New table: `id_users`, `platform` lookup, `id_pages`, `url_snapshot`, `updated_at`; validate on read. |
| **`manual_plus_suggestions` child source** | Seeded lookup code in §5 | Builder UI only: show suggestions when `child_source = manual`; no runtime expansion difference from `manual`. |
| **Live preview + mobile preview bridge** | [`PreviewSyncBridge`](sh-selfhelp_mobile/components/preview/PreviewSyncBridge.tsx) uses `isKeywordOnMenu` | Preview fetches `/navigation`; modal=auto uses `isOnAnyMobileMenu`. |
| **Golden workflow gap** | No navigation golden test yet | Add `tests/Golden/NavigationMenuBuilderWorkflowTest.php`. |

### 15.3 What the condensed plan missed (now in cursor plan + §0)

Your full draft (§§5–12) is the source of truth for field-level tables, Mantine preset matrix, menu-builder UX, auto-include rules, start-page behavior, hero bundles, and the complete test matrix. The cursor plan at `.cursor/plans/menus_navigation_refactor_536b9881.plan.md` now adds explicit todos for: `get_user_acl` migration, page version/export bundles, live preview bridge, last-visited storage, and golden navigation workflow.

### 15.4 Complete file touch list (implementation checklist)

**Backend (`sh-selfhelp_backend`):** entities `NavigationMenu`, `NavigationMenuItem`, `NavigationMenuItemTranslation`, `NavigationMenuItemExclusion`, `NavigationSettings`, `UserNavigationState`, `SearchIndexEntry`; repositories; `NavigationMenuService`, `NavigationSettingsService`, `NavigationSearchService`, `NavigationAssignmentService`; `NavigationController`, `AdminNavigationController`, `SearchController`; migrations (schema, lookups, seeds, data migration, SP update, column drop); schemas under `config/schemas/api/v1/`; permissions; [`PageService`](src/Service/CMS/Frontend/PageService.php), [`AdminPageService`](src/Service/CMS/Admin/AdminPageService.php), [`PageFieldService`](src/Service/CMS/Admin/PageFieldService.php), [`PositionManagementService`](src/Service/CMS/Admin/PositionManagementService.php) (remove nav/footer reorder), [`PageExportImportService`](src/Service/CMS/Admin/PageExportImportService.php), [`PageVersionService`](src/Service/CMS/Admin/PageVersionService.php), [`LookupService`](src/Service/Core/LookupService.php); tests per §12.

**Shared (`sh-selfhelp_shared`):** `src/navigation/` module rewrite; `INavigationPayload` types; remove `navRender.ts` active exports; [`transformPageData`](sh-selfhelp_shared/src/utils/transformPageData.ts), [`pages.ts`](sh-selfhelp_shared/src/types/pages.ts), [`endpoints.ts`](sh-selfhelp_shared/src/api/endpoints.ts); plugin-sdk navigation helpers if referenced.

**Frontend (`sh-selfhelp_frontend`):** [`server-fetch.ts`](sh-selfhelp_frontend/src/app/_lib/server-fetch.ts), [`useAppNavigation.ts`](sh-selfhelp_frontend/src/hooks/useAppNavigation.ts), [`navigation.utils.ts`](sh-selfhelp_frontend/src/utils/navigation.utils.ts), [`WebsiteHeader.tsx`](sh-selfhelp_frontend/src/app/components/frontend/layout/header/WebsiteHeader.tsx), [`WebsiteHeaderMenu.tsx`](sh-selfhelp_frontend/src/app/components/frontend/layout/header/WebsiteHeaderMenu.tsx), new `WebsiteHeaderRenderer/`, new `PageBranchNav`, remove [`VirtualNavigation.tsx`](sh-selfhelp_frontend/src/app/components/frontend/navigation/VirtualNavigation.tsx) render registries, [`DynamicPageClient.tsx`](sh-selfhelp_frontend/src/app/[[...slug]]/DynamicPageClient.tsx), [`CreatePage.tsx`](sh-selfhelp_frontend/src/app/components/cms/pages/create-page/CreatePage.tsx), [`page-field-groups.tsx`](sh-selfhelp_frontend/src/app/components/cms/pages/page-inspector/page-field-groups.tsx), [`PageInspector.tsx`](sh-selfhelp_frontend/src/app/components/cms/pages/page-inspector/PageInspector.tsx), [`useAdminPages.ts`](sh-selfhelp_frontend/src/hooks/useAdminPages.ts), [`AdminNavbar.tsx`](sh-selfhelp_frontend/src/app/components/cms/admin-shell/admin-navbar/AdminNavbar.tsx), new Navigation builder page, [`LivePreviewWebPane.tsx`](sh-selfhelp_frontend/src/app/components/cms/live-preview/LivePreviewWebPane.tsx), root `/` start-page redirect logic, [`navigation.api.ts`](sh-selfhelp_frontend/src/api/navigation.api.ts).

**Mobile (`sh-selfhelp_mobile`):** new `useNavigation` hook + `navigationService.ts`; [`navigationUtils.ts`](sh-selfhelp_mobile/components/shell/navigationUtils.ts), [`mobileShellStore.ts`](sh-selfhelp_mobile/stores/mobileShellStore.ts), [`(app)/_layout.tsx`](sh-selfhelp_mobile/app/(app)/_layout.tsx), [`CmsDrawerContent.tsx`](sh-selfhelp_mobile/components/shell/CmsDrawerContent.tsx), [`BottomNavigationTabs.tsx`](sh-selfhelp_mobile/components/shell/BottomNavigationTabs.tsx), [`SegmentedChildPages.tsx`](sh-selfhelp_mobile/components/renderer/SegmentedChildPages.tsx), [`CmsPageScreen.tsx`](sh-selfhelp_mobile/components/renderer/CmsPageScreen.tsx), remove [`MobileNavRenderers.tsx`](sh-selfhelp_mobile/components/renderer/MobileNavRenderers.tsx), [`PreviewSyncBridge.tsx`](sh-selfhelp_mobile/components/preview/PreviewSyncBridge.tsx), cold-start routing in app root layout.

### 15.5 Verification gate (run before calling the wave complete)

```bash
# Backend
composer phpstan
php bin/phpunit tests/Unit/Routing/ tests/Service/CMS/Navigation/ tests/Controller/Api/V1/Navigation/ tests/Golden/NavigationMenuBuilderWorkflowTest.php
php bin/phpunit tests/Integration/Migrations/Version*RoundTripTest.php  # new migration classes only

# Shared
npm run test -- src/navigation/

# Frontend
npm run test -- src/utils/navigation src/app/components/frontend/layout/header
# Playwright: dropdown + mega-menu + mobile width screenshots

# Mobile
npm run test -- components/shell/navigationUtils components/renderer/SegmentedChildPages

# Contract grep (must return zero hits in active paths)
rg 'nav_position|footer_position|web_nav_render|mobile_nav_render|navPosition|globalNavRender' \
  --glob '!docs/archive/**' --glob '!CHANGELOG.md' --glob '!migrations/Version20260630130327.php'
```

### 15.6 Product decisions — locked 2026-07-01

All questions answered **B**. **No backward compatibility.** See §14 items 11–16.

| # | Locked answer |
| --- | --- |
| Q1 | Builder-only `manual_plus_suggestions`; runtime resolver fixed |
| Q2 | Profile system route; drop `menu` screen; `index` = startup redirect |
| Q3 | `pageUrlToMobileRoute(url, keyword)` → `/(app)/{keyword}`; no `[...slug]` in wave 1 |
| Q4 | Utility row in `WebsiteHeader`; presets on second row |
| Q5 | `content_index` default; search UI gated on index tests |
| Q6 | Cleanup migration last in same PR after grep-clean |

---

