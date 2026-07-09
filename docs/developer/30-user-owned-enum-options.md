<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# User-owned enum options

Audience: Developers and technical operators.
Status: active.
Applies to: SelfHelp2 option-based CMS form styles.
Last verified: 2026-07-09.
Source of truth: `src/Service/CMS/Frontend/OptionLabelHydrator.php`, `DataColumnService.php`, migration `Version20260709102039`, and `@selfhelp/shared/src/content/optionLabels.ts`.

User-owned enums are style configuration, not system lookups. Existing `select`, `radio`, `combobox`, and `segmented-control` styles use two fields:

- A non-translatable catalog (`options`, `radio_options`, `combobox_options`, or `segmented_control_data`) stores stable codes, ordering, disabled state, and optional metadata.
- Translatable `option_labels` stores a `code -> label` map for each CMS `language_id`.

Form rows store selected codes only. Labels never enter `data_cells`.

## Hydration

`PageService` delegates entry-row projection to `OptionLabelHydrator`. It discovers option controls under the data table's owning form section, resolves the requested language, and adds:

- `_{field}_label` for single select, radio, and segmented control.
- `_{field}_labels` for multi select and multi combobox.

Multiplicity comes from the style configuration, not the number of codes in the row. Multi labels use the existing comma-and-space interpolation string convention.

Resolution order is active-language `option_labels`, CMS default-language `option_labels`, language-1 compatibility content, legacy catalog `label`/`text`, then the code. Web and mobile use the matching shared TypeScript resolver.

## Reserved namespace

Generated underscore keys are read-only. `DataColumnService` rejects submitted `_{field}_label` and `_{field}_labels` keys with HTTP 400, while hydration fails loudly if historical data already collides.

## Writes, cache, and permissions

The section editor writes the catalog as a property and each language map as normal translated section content. Existing section-update cache invalidation refreshes page and frontend caches; no new cache category is introduced. The feature adds no route, permission, authentication, ACL, or data-access rule.

## Legacy compatibility

Readers accept old `{value, text}` and `{value, label}` catalogs, so existing bundles retain readable labels. Opening and saving through the multilingual grid emits the canonical two-field shape. Boolean checkbox remains unchanged because it is not an option group.
