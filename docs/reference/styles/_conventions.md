# Style conventions and common fields

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 (backend field contract, `@selfhelp/shared` types, frontend/mobile renderers).
Last verified: 2026-06-04.
Source of truth: `styles` / `fields` / `rel_fields_styles` rows seeded by the Doctrine migrations, the live `GET /cms-api/v1/admin/styles/schema` endpoint, the `@selfhelp/shared` style types, and the frontend style components.

This page describes the fields and conventions **shared by every style**, so the
per-category reference pages can focus on what is unique to each style. Read it
once; then each style page only lists its own distinctive fields.

## What a style is (for developers)

A style is a reusable CMS building block. At runtime:

- the backend stores it as a row in `styles` (with `id_group`, `description`, and `can_have_children`);
- it owns a set of **fields** (`fields` + `rel_fields_styles`), each with a type, a `display` flag, a `default_value`, and `help` text;
- `PageService` renders a **section** that instantiates the style, resolves each field's content for the active locale, evaluates the visibility condition, and recurses into child sections;
- `@selfhelp/shared` exposes the typed contract (`src/types/styles/<category>.ts`) and the registry entry (`src/registry/styles.registry.ts`);
- the frontend renders it from `src/app/components/frontend/styles/`, and the mobile app renders the `frontendOnly` styles.

## What a style is (for administrators)

A style is the "type" you pick when you add a section to a page. It decides what
the section looks like and does — a heading, a button, a form input, a layout
box, and so on. After you pick a style you fill in its **fields** (text,
toggles, dropdowns) in the section editor. Some styles are **containers**: you
drag other sections inside them.

## Field display flag

Each field is either:

- **`display = 0` (internal / configuration)** — not translated. One value shared across all languages (e.g. a size, a colour, a toggle).
- **`display = 1` (content)** — translatable. You can enter a different value per language; the visitor sees the value for their active locale.

## Common fields on (almost) every style

| Field | Type | Purpose |
|-------|------|---------|
| `css` | css | Extra CSS class string applied to the rendered element on the **web** frontend. Use it for Tailwind / custom classes. |
| `css_mobile` | css | Class string used by the **mobile** app (the mobile renderer reads only this, never `css`). |
| `condition` | condition | A JSON-Logic expression evaluated per request. When it resolves to false the section is hidden (mirrors the backend `ConditionService`). |
| `debug` | checkbox | When on, the section emits debug data (condition result, resolved variables) for editors. |
| `data_config` | data-config | Optional JSON that binds the section to backend data (used by data-driven styles such as `entry-list`, `loop`, and the form styles). |

## Spacing and "use Mantine style"

Most visual styles also expose:

| Field | Type | Purpose |
|-------|------|---------|
| `mantine_spacing_margin_padding` | segment | A visual box-model control for the section's margin **and** padding. |
| `mantine_spacing_margin` | segment | Legacy margin-only spacing still present on some styles. |
| `use_mantine_style` | checkbox | `1` (default for most) renders the real Mantine component with its theme. `0` renders a plain element you fully control with `css` / Tailwind. Turn it off when you want raw HTML styling. |

## Standard Mantine cosmetic props

Many component styles expose the same Mantine look-and-feel props. They follow
[Mantine](https://mantine.dev) semantics; exact allowed values and defaults are
in the live `admin/styles/schema` endpoint:

| Field | Purpose |
|-------|---------|
| `mantine_size` | Component size — usually `xs`–`xl` (some accept a CSS length). |
| `mantine_color` | Theme colour key (e.g. `blue`, `red`) or a custom colour. |
| `mantine_radius` | Corner radius — `xs`–`xl` or a number. |
| `mantine_variant` | Visual variant (e.g. `filled`, `outline`, `light`, `subtle`, `default`). |
| `mantine_left_icon` / `mantine_right_icon` | Icon name picked from the icon selector, rendered before/after the content. |

## Icons

Icon fields (`mantine_left_icon`, `mantine_right_icon`, and similar) take an icon
name from the bundled Tabler icon set, chosen through the admin icon picker.

## Conventions used on the per-category pages

Each style entry on a category page has:

- **Purpose** — one or two sentences (and the Mantine/HTML element it maps to).
- **Administrators** — when and how to use it in the page editor.
- **Developers** — how it renders and any behaviour worth knowing.
- **Distinctive fields** — the fields unique to that style (the common, spacing,
  and standard Mantine props above are not repeated).
- **Children** — whether the style can contain child sections.

> Defaults and `help` text are owned by the database. This reference describes
> **purpose and behaviour**; treat the seed migrations and the live
> `admin/styles/schema` endpoint as the authoritative source for exact default
> values and per-field help.

## Related references

- [index.md](./index.md) — the full style catalog.
- [style-schema-endpoint.md](../../developer/style-schema-endpoint.md) — the live schema contract (every style + field + default).
- [section.md](../../developer/section.md) — how sections instantiate styles and resolve field content.
- [cms-translation.md](../../developer/cms-translation.md) — how translatable (`display = 1`) content is stored and resolved per locale.
