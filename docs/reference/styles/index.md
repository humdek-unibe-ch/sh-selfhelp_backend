# CMS Styles Reference

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 (backend field contract, shared types, frontend/mobile renderers).
Last verified: 2026-06-04.
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

- The tables below are the **catalog**: every core style, its category, and whether it can contain child sections.
- Styles with a link have a **full reference page**. Per repository rule, any style you add or change must ship/refresh its full page (see [`_template.md`](./_template.md) and the "Style documentation" rule in `AGENTS.md`).
- Plugin-contributed styles are not listed here; they are documented in their owning plugin repository and registered at runtime through `extendStyleRegistry()`.

## auth

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `login` | no | [auth/login.md](./auth/login.md) |
| `register` | no | [auth/register.md](./auth/register.md) |
| `validate` | yes | [auth/validate.md](./auth/validate.md) |
| `resetPassword` | no | catalog only |
| `twoFactorAuth` | no | catalog only |
| `profile` | no | catalog only |

## layout

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `container` | yes | catalog only |
| `box` | yes | catalog only |
| `flex` | yes | catalog only |
| `group` | yes | catalog only |
| `stack` | yes | catalog only |
| `simple-grid` | yes | catalog only |
| `grid` | yes | catalog only |
| `grid-column` | yes | catalog only |
| `space` | no | catalog only |
| `divider` | no | catalog only |
| `paper` | yes | catalog only |
| `center` | yes | catalog only |
| `scroll-area` | yes | catalog only |
| `card` | yes | catalog only |
| `card-segment` | yes | catalog only |
| `aspect-ratio` | yes | catalog only |
| `background-image` | yes | catalog only |

## typography

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `title` | no | catalog only |
| `text` | no | catalog only |
| `code` | no | catalog only |
| `highlight` | no | catalog only |
| `blockquote` | no | catalog only |
| `html-tag` | yes | catalog only |
| `kbd` | no | catalog only |
| `typography` | yes | catalog only |
| `fieldset` | yes | catalog only |
| `spoiler` | yes | catalog only |

## media

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `image` | no | catalog only |
| `video` | no | catalog only |
| `audio` | no | catalog only |
| `figure` | yes | catalog only |
| `carousel` | yes | catalog only |

## interactive

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `button` | no | catalog only |
| `link` | no | catalog only |
| `action-icon` | no | catalog only |
| `alert` | yes | catalog only |
| `badge` | no | catalog only |
| `avatar` | no | catalog only |
| `chip` | no | catalog only |
| `indicator` | yes | catalog only |
| `theme-icon` | no | catalog only |
| `notification` | no | catalog only |

## forms

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `form-log` | yes | catalog only |
| `form-record` | yes | catalog only |
| `input` | no | catalog only |
| `text-input` | no | catalog only |
| `textarea` | no | catalog only |
| `rich-text-editor` | no | catalog only |
| `select` | no | catalog only |
| `radio` | no | catalog only |
| `checkbox` | no | catalog only |
| `slider` | no | catalog only |
| `range-slider` | no | catalog only |
| `datepicker` | no | catalog only |
| `switch` | no | catalog only |
| `combobox` | no | catalog only |
| `color-input` | no | catalog only |
| `color-picker` | no | catalog only |
| `file-input` | no | catalog only |
| `number-input` | no | catalog only |
| `segmented-control` | no | catalog only |
| `rating` | no | catalog only |
| `progress` | no | catalog only |
| `progress-root` | yes | catalog only |
| `progress-section` | no | catalog only |

## composite

| Style | Can have children | Documentation |
|-------|-------------------|---------------|
| `accordion` | yes | catalog only |
| `accordion-item` | yes | catalog only |
| `tabs` | yes | catalog only |
| `tab` | yes | catalog only |
| `timeline` | yes | catalog only |
| `list` | yes | catalog only |
| `list-item` | yes | catalog only |
| `entryList` | yes | catalog only |
| `entryRecord` | yes | catalog only |
| `entryRecordDelete` | no | catalog only |
| `loop` | yes | catalog only |

## Related references

- [style-schema-endpoint.md](../../developer/style-schema-endpoint.md) — the live `admin/styles/schema` contract that exposes every style + field + default.
- [section.md](../../developer/section.md) — how sections instantiate styles and resolve field content.
- [cms-translation.md](../../developer/cms-translation.md) — how translatable (`display = 1`) field content is stored and resolved per locale.
