<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Navigation examples (hero home + mobile onboarding)

Audience: Developers and CMS operators.
Status: active.
Applies to: SelfHelp2 Symfony backend `0.1.32+`.
Last verified: 2026-07-01.
Source of truth: `docs/examples/*.bundle.json`, `HeroHomeSeedService`, admin page import API.

## Hero home (fresh install + optional re-import)

The polished marketing-style landing lives in [`hero-home.bundle.json`](../examples/hero-home.bundle.json).

**Fresh installs:** migration `Version20260701112111` replaces the baseline `home-sys*` placeholder on the system `home` page when that page is still untouched (only `home-sys*` sections, no custom edits). A normal `doctrine:migrations:migrate` run is enough — no extra console step.

**Re-import later** (or on an instance that already customized `home` before this migration ran), use the guarded CLI:

```bash
php bin/console app:examples:seed-hero-home
```

Use `--force` only when you intentionally want to replace customized `home` content.

After seeding, rebuild the search projection if you rely on content-index search:

```bash
php bin/console app:navigation:rebuild-search-index --page-id=<home-id>
```

## Mobile onboarding template

[`mobile-onboarding.bundle.json`](../examples/mobile-onboarding.bundle.json) is a mobile-first onboarding/landing template. Import it through the admin **Pages → Import bundle** flow (or `POST /cms-api/v1/admin/pages/import`) with a `qa-` keyword prefix in test environments.

## Manual import

Both bundles use the standard `selfhelp/page-bundle` format consumed by `PageExportImportService`. They are also listed from the developer navigation guide [`28-navigation-pages-and-page-icons.md`](../developer/28-navigation-pages-and-page-icons.md).
