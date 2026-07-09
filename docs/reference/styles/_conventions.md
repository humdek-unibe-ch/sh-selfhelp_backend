# Style conventions and common fields

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 (backend field contract, `@selfhelp/shared` types, frontend/mobile renderers).
Last verified: 2026-07-09.
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

## Field naming, scope, and platform

Field scope has **two independent dimensions** — translatability (`display`) and
the name prefix — which the backend collapses into a single four-value `scope` on
every field of the `admin/styles/schema` and `admin` section responses (computed
once in `StyleRepository::deriveFieldScope()`). Translatability wins first
(`display = 1` is always `content`); property fields (`display = 0`) then split by
prefix. The CMS groups fields by this `scope` and must not re-derive it from the
name or the `display` flag.

**No prefix = both platforms.** A property field with no platform prefix applies
to both web and mobile. There is no separate `shared_` prefix: the cross-platform
presentation fields (`size`, `radius`, `spacing`, `color`, `variant`, `gap`, …)
are plain unprefixed `common`-scope fields, the same scope as cross-platform
behaviour/data fields (`value`, `name`, `url`). Only `web_` and `mobile_` mark a
field as platform-specific. (Migration `Version20260622165615` dropped the
redundant `shared_` prefix from 47 fields — see the reserved-name note below.)

| Field | `display` | Scope | Examples | Meaning |
|-------|-----------|-------|----------|---------|
| translatable copy | `1` | `content` | `label`, `title`, `text`, `placeholder` | Authored content, grouped per language |
| unprefixed property | `0` | `common` | `value`, `name`, `url`, `size`, `radius`, `spacing`, `color`, `variant` | Cross-platform behaviour, data, **and** presentation (both platforms) |
| `web_` property | `0` | `web` | `web_variant`, `web_shadow`, `web_size` | Mantine / browser-specific presentation (web renderer only) |
| `mobile_` property | `0` | `mobile` | `mobile_keyboard_type`, `mobile_haptic_feedback` | HeroUI Native / native-device presentation (mobile renderer only) |

Do not prefix ordinary fields just because both platforms read them — unprefixed
already means both. Renderer precedence is: current-platform `web_*`/`mobile_*`
override → unprefixed cross-platform semantic → component default. The mobile
renderer never reads `web_*` and the web renderer never reads `mobile_*`.

> **Reserved-name exceptions.** Three cross-platform presentation fields keep a
> `shared_` prefix — `shared_height`, `shared_width`, `shared_icon` — because the
> bare names `height` / `width` / `icon` already exist as **page-type** fields in
> the globally-unique `fields` table (page `icon` is live on real pages). They
> are still `common`-scope (both platforms); only their *name* is prefixed.

The unprefixed cross-platform semantic fields use the **true cross-platform
common denominator** (HeroUI Native has no `xs`/`xl`), so they are intentionally
narrower than the Mantine `web_*` scales and are mapped 1:1 per platform by
`@selfhelp/shared` (`theme/semantic.ts`) with no clamping:

| Field | Allowed values | Notes |
|-------|----------------|-------|
| `size` | `sm`, `md`, `lg` | `web_size` keeps the full Mantine `xs..xl` |
| `radius` | `none`, `sm`, `md`, `lg`, `full` | `full` = pill, `none` = square |
| `spacing` | box-model object | one complete margin+padding control |

The backend enforces these option lists (`fields.config`) and default domains.

## Render target

Every style has a **render target** (`web` | `mobile` | `both`), returned as
`renderTarget` by the catalog/schema endpoints and sourced from the
`styleRenderTargets` lookup (`styles.id_render_target`; `NULL` means `both`). It
declares where a style is *intentionally* renderable. This is distinct from:

- the **page access target** (`pageAccessTypes`: `web` | `mobile` | `mobile_and_web`), which controls where a page may load; and
- the **request client** (`web` | `mobile`), which identifies the current renderer.

There is no `pages.id_platform` — page-level targeting lives only on
`pageAccessTypes`. A client silently omits styles whose render target excludes
it; that is not an error.

## Common fields on (almost) every style

| Field | Type | Purpose |
|-------|------|---------|
| `css` | css | Extra CSS class string applied to the rendered element on the **web** frontend. Use it for Tailwind / custom classes. |
| `css_mobile` | css | Class string used by the **mobile** app (the mobile renderer reads only this, never `css`). |
| `condition` | condition | A JSON-Logic expression evaluated per request. When it resolves to false the section is hidden (mirrors the backend `ConditionService`). |
| `debug` | checkbox | When on, the section emits debug data (condition result, resolved variables) for editors. |
| `data_config` | data-config | Optional JSON that retrieves **helper scopes** for interpolation (used by `loop`, `data-container`, and similar). On **`entry-list` / `entry-record` it must not choose the row table** — use the style's **Data table** property field instead. A legacy `data_config.table` entry on an entry holder is ignored at render time; see [composite.md#entry-list](./composite.md#entry-list). |

## Spacing

Most visual styles also expose:

| Field | Type | Scope | Purpose |
|-------|------|-------|---------|
| `spacing` | spacing | common | Portable box-model control for the section's margin **and** padding. Mapped to both platforms (the single cross-platform spacing field every spacing-capable style uses). |

## Standard Mantine cosmetic props

Many component styles expose the same Mantine look-and-feel props. They follow
[Mantine](https://mantine.dev) semantics; exact allowed values and defaults are
in the live `admin/styles/schema` endpoint:

| Field | Purpose |
|-------|---------|
| `web_size` | Component size — usually `xs`–`xl` (some accept a CSS length). |
| `color` | Semantic theme colour key (e.g. `blue`, `red`); mapped to Mantine `color` on web and HeroUI Native colour on mobile (slice 2 / RF-13). Cross-platform (both renderers). |
| `web_radius` | Corner radius — `xs`–`xl` or a number. |
| `web_variant` | Visual variant (e.g. `filled`, `outline`, `light`, `subtle`, `default`). |
| `web_left_icon` / `web_right_icon` | Icon name picked from the icon selector, rendered before/after the content. |

## Icons

Icon fields (`web_left_icon`, `web_right_icon`, and similar) take an icon
name from the bundled Tabler icon set, chosen through the admin icon picker.

## Editing content fields in the section editor

How a field is **edited** in the admin is driven by its **type** (the editor is
chosen from the field type, not a per-name allowlist), and every text surface
supports `{{` variable interpolation (see
[interpolation-system](../../developer/10-interpolation-system.md#cms-editor-field-rendering-modes-and--coverage)
for the full table):

- `text` fields render as a **single-line** mention input. The three structural
  identifiers (`name`, `value`, `title`) stay **plain** inputs (no interpolation,
  they are keys/values, not display copy); every other `text` field gets the
  `{{` picker.
- `markdown-inline` fields are the same single-line mention input plus inline
  **bold / italic / underline / link** shortcuts.
- `textarea` fields are the **rich-text WYSIWYG** editor (full toolbar:
  headings, lists, alignment, links) — this is where **longer / multiline /
  nicely-formatted copy** is authored (it accepts Enter). On the `sh-mail-config`
  page these bodies also expose the email **Style** preset dropdown — see
  [email styles](../email-styles.md).
- `markdown`, `json`, `code` (raw HTML markup), `css`, and the data-config SQL
  filter use the Monaco code editor with `{{` completion. The SQL filter is
  **locked by default** and unlockable for manual editing.

For the non-technical version, see
[user/interpolation-and-data-naming.md](../../user/interpolation-and-data-naming.md).

## Cross-platform rendering parity (tracked gaps)

Web and mobile render the same CMS payload through separate renderers, so a few
contracts are **intentionally asymmetric today**. These are tracked here as
explicit gaps (not implicit drift) so they are visible when planning mobile work:

- **Rich-text block content — tracked mobile gap.** The `text` (used by the
  `text` + `highlight` styles) and `blockquote_content` fields are `textarea`
  rich-text, so they can carry **block** HTML (headings, lists, paragraphs,
  alignment). The **web** renderer preserves that block structure
  (`renderRichBlock` / `sanitizeHtmlForBlock`); the **mobile** renderer currently
  parses only the **inline subset** (`parseInlineRich` + `<InlineText>`, block
  tags degrade to inline via `sanitizeContent`). Authored headings/lists therefore
  appear flattened on mobile. Closing this needs a mobile block renderer — until
  then keep critical structure out of mobile-facing copy.
- **entry-table headers (then `show-user-input`) — parity restored (mobile
  `0.1.30`).** Rows are keyed by the immutable `field_key`; both platforms now
  resolve human labels through the section's `field_labels`
  (`field_key => display_name`) map, so default headers and `fields_map`
  mappings behave the same on web and mobile.

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
- [style-field-naming-rules.md](./style-field-naming-rules.md) — the field naming taxonomy + lifecycle contract (the *why* behind this page).
- [style-platform-matrix.md](./style-platform-matrix.md) — per-style render target + web/mobile renderer mapping.
- [style-mobile-mapping.md](./style-mobile-mapping.md) — semantic contract → Mantine + HeroUI Native.
- [style-field-audit.md](./style-field-audit.md) — DB-vs-code field audit and drift report.
- [style-schema-endpoint.md](../../developer/style-schema-endpoint.md) — the live schema contract (every style + field + default).
- [section.md](../../developer/section.md) — how sections instantiate styles and resolve field content.
- [cms-translation.md](../../developer/cms-translation.md) — how translatable (`display = 1`) content is stored and resolved per locale.
