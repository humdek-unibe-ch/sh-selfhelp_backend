<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Navigation examples (hero home + mobile onboarding + menu demo)

Audience: Developers and CMS operators.
Status: active.
Applies to: SelfHelp2 Symfony backend `0.1.33+`.
Last verified: 2026-07-02.
Source of truth: `sh-selfhelp_frontend/examples/`, `NavigationExportImportService`, admin navigation import API.

## Canonical example location

All curated bundles live in **`sh-selfhelp_frontend/examples/`** (`pages/`, `cms-in-cms/`, `navigation/`). The backend resolves them from the monorepo sibling path and falls back to `tests/fixtures/examples/` for CI.

## Hero home (fresh install + optional re-import)

The polished marketing-style landing lives in [`hero-home.bundle.json`](../../sh-selfhelp_frontend/examples/pages/hero-home.bundle.json).

**Fresh installs:** migration `Version20260710093045` replaces the baseline `home-sys*` placeholder on the system `home` page when that page is still untouched. A normal `doctrine:migrations:migrate` run is enough — no extra console step.

**Re-import later**, use the guarded CLI:

```bash
php bin/console app:examples:seed-hero-home
```

## Mobile onboarding template

[`mobile-onboarding.bundle.json`](../../sh-selfhelp_frontend/examples/pages/mobile-onboarding.bundle.json) is a mobile-first onboarding/landing template. Import via **Pages → Export / Import**.

## Menu demo (22-page mini-site)

[`menu-demo.bundle.json`](../../sh-selfhelp_frontend/examples/navigation/menu-demo.bundle.json) ships a realistic mini-site with **all four menus** wired (dropdown header with mega-menu descriptions and a three-level Services > Training branch, grouped footer, mobile drawer, five bottom tabs).

Import via **Navigation → Export / Import** (or `POST /cms-api/v1/admin/navigation/import`). The bundle's pages already use namespaced `demo-*` keywords, so no keyword prefix is needed; the default `/demo` route prefix keeps its routes clear of `/`. Use `menu_policies.replace` for a clean demo reset. Tests importing this fixture must still pass an explicit `qa-`-prefixed `keyword_prefix` per the QA test-data convention.

## Page vs navigation bundles

| Format | Purpose |
| --- | --- |
| `selfhelp/page-bundle` v2.0 | Pages, sections, routes, optional data — **no menu membership** |
| `selfhelp/navigation-bundle` v1.0 | Menu trees, item metadata, optional embedded pages |

Legacy `navigation.assignments` inside page bundles is warned and ignored on import.
