# CMS Styles Reference

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 (backend field contract, shared types, frontend/mobile renderers).
Last verified: 2026-06-18.
Source of truth: `styles` / `fields` / `rel_fields_styles` rows seeded by the Doctrine migrations, the live `GET /cms-api/v1/admin/styles/schema` endpoint, the `@selfhelp/shared` style types, and the frontend style components.

A **style** is a reusable CMS building block. Each style:

- is a row in the `styles` table (with `id_group`, `can_have_children`, `description`);
- owns a set of **fields** (`fields` + `rel_fields_styles`), each with a type, a `display` flag (`0` = internal/non-translatable config, `1` = external/translatable content), a `default_value`, and `help` text;
- has a typed contract in `@selfhelp/shared` (`src/types/styles/<group>.ts`) and a registry entry in `src/registry/styles.registry.ts`;
- is rendered by a frontend component under `src/app/components/frontend/styles/` (and, for `frontendOnly` styles, by the mobile app).

> Field **default values** and **help text** are owned by the database. Treat
> the seed migrations and the live `admin/styles/schema` endpoint as the
> authoritative values — this reference describes purpose and behaviour and
> only pins exact defaults where a doc page calls them out explicitly.

## How to use this reference

- Read [`_conventions.md`](./_conventions.md) first — it documents the fields and Mantine prop conventions **shared by every style**, so the per-style/per-category pages only cover what is unique.
- The tables below are the **catalog**: every core style, its category, whether it can contain child sections, and a link to its documentation.
- **Documentation layout:** the auth flow styles (multi-step pages with lots of CMS copy) each get a dedicated page under [`auth/`](./auth/). The atomic component styles (layout, typography, media, interactive, forms, composite) are documented together on a **per-category page**, one section per style — open the category page and jump to the style's heading.
- Every page is written for **two audiences**: an "Administrators" view (when/how to use the style in the page editor) and a "Developers" view (what it maps to and how it renders).
- Per repository rule, any style you add or change must ship/refresh its documentation (see [`_template.md`](./_template.md) and the "Style documentation" rule in `AGENTS.md`).
- Plugin-contributed styles are not listed here; they are documented in their owning plugin repository and registered at runtime through `extendStyleRegistry()`.

## Architecture and audit (mobile readiness)

These cross-cutting pages document the **style contract** itself — how a DB style
maps through the shared semantic layer to the web (Mantine) and mobile (HeroUI
Native / React Native) renderers — and audit the catalog against the live DB:

| Page | What it gives you |
|------|-------------------|
| [style-field-naming-rules.md](./style-field-naming-rules.md) | The field naming taxonomy (`content`/`common`/`shared_`/`web_`/`mobile_`) + lifecycle statuses. The rulebook. |
| [style-platform-matrix.md](./style-platform-matrix.md) | One row per style: render target, web Mantine target, mobile HeroUI Native / RN target, mobile fit. |
| [style-mobile-mapping.md](./style-mobile-mapping.md) | The semantic mapper tables and per-field web/mobile loading (deep mobile mapping). |
| [style-field-audit.md](./style-field-audit.md) | DB-vs-code audit: drift, duplicates, typos, scope distribution. |
| [style-refactoring-recommendations.md](./style-refactoring-recommendations.md) | The prioritised cleanup plan derived from the audit. |
| [style-field-audit.generated.json](./style-field-audit.generated.json) | Machine-readable audit data (regenerate with `php scripts/build-style-audit.php`). |

Each style below is currently `render_target = both`; see the platform matrix for
the per-style web/mobile mapping and the recommended targeting.

## auth

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `login` | no | [auth/login.md](./auth/login.md) |
| `register` | no | [auth/register.md](./auth/register.md) |
| `validate` | yes | [auth/validate.md](./auth/validate.md) |
| `reset-password` | no | [auth/reset-password.md](./auth/reset-password.md) |
| `two-factor-auth` | no | [auth/two-factor-auth.md](./auth/two-factor-auth.md) |
| `profile` | no | [auth/profile.md](./auth/profile.md) |

## layout

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `container` | yes | [layout.md#container](./layout.md#container) |
| `box` | yes | [layout.md#box](./layout.md#box) |
| `flex` | yes | [layout.md#flex](./layout.md#flex) |
| `group` | yes | [layout.md#group](./layout.md#group) |
| `stack` | yes | [layout.md#stack](./layout.md#stack) |
| `simple-grid` | yes | [layout.md#simple-grid](./layout.md#simple-grid) |
| `grid` | yes | [layout.md#grid](./layout.md#grid) |
| `grid-column` | yes | [layout.md#grid-column](./layout.md#grid-column) |
| `space` | no | [layout.md#space](./layout.md#space) |
| `divider` | no | [layout.md#divider](./layout.md#divider) |
| `paper` | yes | [layout.md#paper](./layout.md#paper) |
| `center` | yes | [layout.md#center](./layout.md#center) |
| `scroll-area` | yes | [layout.md#scroll-area](./layout.md#scroll-area) |
| `card` | yes | [layout.md#card](./layout.md#card) |
| `card-segment` | yes | [layout.md#card-segment](./layout.md#card-segment) |
| `aspect-ratio` | yes | [layout.md#aspect-ratio](./layout.md#aspect-ratio) |
| `background-image` | yes | [layout.md#background-image](./layout.md#background-image) |
| `ref-container` | yes | [layout.md#ref-container](./layout.md#ref-container) |
| `data-container` | yes | [layout.md#data-container](./layout.md#data-container) |

## typography

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `title` | no | [typography.md#title](./typography.md#title) |
| `text` | no | [typography.md#text](./typography.md#text) |
| `code` | no | [typography.md#code](./typography.md#code) |
| `highlight` | no | [typography.md#highlight](./typography.md#highlight) |
| `blockquote` | no | [typography.md#blockquote](./typography.md#blockquote) |
| `html-tag` | yes | [typography.md#html-tag](./typography.md#html-tag) |
| `kbd` | no | [typography.md#kbd](./typography.md#kbd) |
| `typography` | yes | [typography.md#typography](./typography.md#typography) |
| `fieldset` | yes | [typography.md#fieldset](./typography.md#fieldset) |
| `spoiler` | yes | [typography.md#spoiler](./typography.md#spoiler) |

## media

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `image` | no | [media.md#image](./media.md#image) |
| `video` | no | [media.md#video](./media.md#video) |
| `audio` | no | [media.md#audio](./media.md#audio) |
| `figure` | yes | [media.md#figure](./media.md#figure) |
| `carousel` | yes | [media.md#carousel](./media.md#carousel) |

## interactive

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `button` | no | [interactive.md#button](./interactive.md#button) |
| `link` | no | [interactive.md#link](./interactive.md#link) |
| `action-icon` | no | [interactive.md#action-icon](./interactive.md#action-icon) |
| `alert` | yes | [interactive.md#alert](./interactive.md#alert) |
| `badge` | no | [interactive.md#badge](./interactive.md#badge) |
| `avatar` | no | [interactive.md#avatar](./interactive.md#avatar) |
| `chip` | no | [interactive.md#chip](./interactive.md#chip) |
| `indicator` | yes | [interactive.md#indicator](./interactive.md#indicator) |
| `theme-icon` | no | [interactive.md#theme-icon](./interactive.md#theme-icon) |
| `notification` | no | [interactive.md#notification](./interactive.md#notification) |

## forms

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `form-log` | yes | [forms.md#form-log](./forms.md#form-log) |
| `form-record` | yes | [forms.md#form-record](./forms.md#form-record) |
| `input` | no | [forms.md#input](./forms.md#input) |
| `text-input` | no | [forms.md#text-input](./forms.md#text-input) |
| `textarea` | no | [forms.md#textarea](./forms.md#textarea) |
| `rich-text-editor` | no | [forms.md#rich-text-editor](./forms.md#rich-text-editor) |
| `select` | no | [forms.md#select](./forms.md#select) |
| `radio` | no | [forms.md#radio](./forms.md#radio) |
| `checkbox` | no | [forms.md#checkbox](./forms.md#checkbox) |
| `slider` | no | [forms.md#slider](./forms.md#slider) |
| `range-slider` | no | [forms.md#range-slider](./forms.md#range-slider) |
| `datepicker` | no | [forms.md#datepicker](./forms.md#datepicker) |
| `switch` | no | [forms.md#switch](./forms.md#switch) |
| `combobox` | no | [forms.md#combobox](./forms.md#combobox) |
| `color-input` | no | [forms.md#color-input](./forms.md#color-input) |
| `color-picker` | no | [forms.md#color-picker](./forms.md#color-picker) |
| `file-input` | no | [forms.md#file-input](./forms.md#file-input) |
| `number-input` | no | [forms.md#number-input](./forms.md#number-input) |
| `segmented-control` | no | [forms.md#segmented-control](./forms.md#segmented-control) |
| `rating` | no | [forms.md#rating](./forms.md#rating) |
| `progress` | no | [forms.md#progress](./forms.md#progress) |
| `progress-root` | yes | [forms.md#progress-root](./forms.md#progress-root) |
| `progress-section` | no | [forms.md#progress-section](./forms.md#progress-section) |
| `show-user-input` | no | [forms.md#show-user-input](./forms.md#show-user-input) |

## composite

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `accordion` | yes | [composite.md#accordion](./composite.md#accordion) |
| `accordion-item` | yes | [composite.md#accordion-item](./composite.md#accordion-item) |
| `tabs` | yes | [composite.md#tabs](./composite.md#tabs) |
| `tab` | yes | [composite.md#tab](./composite.md#tab) |
| `timeline` | yes | [composite.md#timeline](./composite.md#timeline) |
| `timeline-item` | yes | [composite.md#timeline-item](./composite.md#timeline-item) |
| `list` | yes | [composite.md#list](./composite.md#list) |
| `list-item` | yes | [composite.md#list-item](./composite.md#list-item) |
| `entry-list` | yes | [composite.md#entry-list](./composite.md#entry-list) |
| `entry-record` | yes | [composite.md#entry-record](./composite.md#entry-record) |
| `entry-record-delete` | no | [composite.md#entry-record-delete](./composite.md#entry-record-delete) |
| `loop` | yes | [composite.md#loop](./composite.md#loop) |

## system

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `no-access` | no | [system.md#no-access](./system.md#no-access) |
| `missing` | no | [system.md#missing](./system.md#missing) |
| `not-found` | no | [system.md#not-found](./system.md#not-found) |
| `version` | no | [system.md#version](./system.md#version) |

## Related references

- [_conventions.md](./_conventions.md) — fields and Mantine prop conventions shared by every style.
- [style-schema-endpoint.md](../../developer/style-schema-endpoint.md) — the live `admin/styles/schema` contract that exposes every style + field + default.
- [section.md](../../developer/section.md) — how sections instantiate styles and resolve field content.
- [cms-translation.md](../../developer/cms-translation.md) — how translatable (`display = 1`) field content is stored and resolved per locale.
