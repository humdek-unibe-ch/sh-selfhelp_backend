# Navigation menus and page icons

Audience: Developers and technical operators (backend, frontend, mobile, shared).
Status: active.
Applies to: SelfHelp2 navigation refactor across backend, frontend, shared, and mobile.
Last verified: 2026-07-06.
Source of truth: runtime code in `NavigationMenuService`, `GET /cms-api/v1/navigation`, and the menu-builder tables seeded by `Version20260701092652.php` (cleaned up by `Version20260706074503.php`).

> **This document supersedes the earlier per-page `web_nav_render` / `mobile_nav_render`
> design.** That model grouped header presentation by page-level render fields.
> The target architecture uses a **first-class menu builder** instead. Pages own
> structure, routes, ACL, icons, and content; menus own placement, ordering,
> nesting, and global presentation presets.

## Architecture after the refactor

### Separation of concerns

| Concern | Owner |
| --- | --- |
| Page tree (`id_parent_page`), routes, ACL, headless, content | `pages` |
| Menu membership, order, nesting, external links, groups | `navigation_menu_items` |
| Web header preset, search mode, start pages | `navigation_settings` + `web_header` menu |
| Web footer links | `web_footer` menu |
| Mobile drawer tree | `mobile_drawer` menu |
| Mobile bottom tabs (flat, limited) | `mobile_bottom_tabs` menu |
| Default web/mobile icons for page-linked items | page fields `icon`, `mobile_icon` |

A page appears in public navigation only when a stored menu item references it.
There is no virtual-child expansion; child pages join a menu via explicitly
created child menu items (see the checkbox flow in
[29-navigation-menu-builder.md](29-navigation-menu-builder.md)).

### Public API

`GET /cms-api/v1/navigation` returns:

- `menus` — resolved `web_header`, `web_footer`, `mobile_drawer`, `mobile_bottom_tabs`
- `startup` — guest/logged-in start pages and start modes
- `search` — mode, min chars, result limit, visibility policy

Resolved menu items include `page` metadata (`url`, `title`, `has_content`,
`section_count`) for holder-page and mobile shell behaviour.

`mobile_bottom_tabs` root items are truncated to the menu's `item_limit` (default 5).

### Admin API

Under `/cms-api/v1/admin/navigation` (permissions `admin.navigation.read` /
`admin.navigation.update`):

- `GET /admin/navigation` returns menu definitions (typed `preset`, `max_depth`,
  `item_limit`), the persisted `items` rows (with `layer`, `translations`), and
  the **resolved** public tree per menu. Use the `resolved` block for
  builder/sidebar preview; `items` remains the persisted DB rows for editing.
- `GET /admin/navigation/menus/{menu_key}/preview` returns the same resolved
  payload for a single menu.
- Overview, menu definition update, item CRUD, reorder

### Page icons

Page fields `icon` (web/Tabler) and `mobile_icon` (curated lucide set) remain on
pages. Menu items inherit them unless `icon_override` is set on the item.

### Removed from the active contract (cleanup wave)

Removed in the coordinated refactor; do not build new features on them:

- `pages.nav_position`, `pages.footer_position`
- page property fields `web_nav_render`, `mobile_nav_render`
- exclusive mobile `globalNavRender` shell mode
- keyword-built mobile shell routes (use backend `page.url` + URL-to-route resolver)
- virtual auto-included children (`child_source`, `auto_include_depth`,
  `is_virtual` items) and the `navigationChildSources` lookups
- the free-form `navigation_menus.config` JSON (footer layout is now the typed
  `preset` lookup)

### Cache invalidation

Navigation caches (`CacheService::CATEGORY_NAVIGATION`) invalidate on menu/item
writes and on page/section/field writes that affect labels, icons, URLs, ACL,
headless state, or `has_content`.

### Cross-repo contracts

Shared types (`@selfhelp/shared` `2.0.0`): `INavigationPayload`,
`TWebHeaderPreset`, `TWebFooterPreset`, header layer helpers
(`splitHeaderLayers` / `mergeHeaderLayers`), footer preset helpers
(`flattenFooterItems`), active-trail helpers (`isMenuItemActiveOnWeb` /
`isMenuItemActiveOnMobile` / `expandedIdsForActiveTrail`),
`resolveMobileSegmentGroup`, `isOnAnyMobileMenuFromPayload`.

See `docs/developer/cross-repo-compatibility-matrix.md` for the navigation-wave
version floors (core `0.1.33` / frontend `0.1.59` / mobile `0.1.33` / shared `2.0.0`).

### Related docs

- Archived plan: `docs/archive/menus-refactor-plan.md`
- Archived pre-refactor brainstorm: `docs/archive/page-menu-architecture-brainstorm.md`
- Public routing: `docs/developer/27-db-driven-public-routing.md`
- Cookbook: `docs/cookbook/cms-in-cms-list-detail.md`
