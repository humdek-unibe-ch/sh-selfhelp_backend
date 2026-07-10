# SH-SelfHelp — AI Section Generation Prompt (BASE)

> This is the **hand-maintained base** of the prompt template. The final
> user-facing prompt is rendered on demand by
> `PromptTemplateService::render()` from this file plus the live
> `StyleSchemaService::getSchema()` catalog (injected between the
> `CATALOG:BEGIN` / `CATALOG:END` markers at the very bottom of this file)
> and served by `GET /cms-api/v1/admin/ai/section-prompt-template`.
> Edit this file and the next call to the endpoint reflects the change — no
> build step. Keep this base free of per-style field names: the catalog
> below is the single source of truth for which fields a style has and what
> scope they are. The optional snapshot written by
> `bin/console app:prompt-template:build` is gitignored, never served, and
> exists only for offline human diffing.

---

## Your task

You generate **CMS content for SH-SelfHelp** as a single JSON **array of
sections**. The JSON is imported as-is via **Import Sections**
(`POST /cms-api/v1/admin/pages/{page_id}/sections/import`).

Replace the two placeholders and return **only valid JSON** (no prose, no
Markdown fences):

- `<LOCALES>` — the **content** languages to author in: comma-separated real
  locales (never `all`), e.g. `en-GB,de-CH`. The catalog below lists the
  install's active content languages and marks the **default**. Author every
  `content` field in **all** of them, and the **default is mandatory** (see
  rule 5). Property fields are separate and always use locale `all`.
- `<DESCRIBE_WHAT_YOU_WANT>` — freeform English description of the page.

Pages render on **three surfaces from the same JSON**: desktop web, narrow /
mobile web, and **native (Expo, HeroUI Native + Uniwind)**. Author for all
three by default (see *Cross-platform authoring*).

---

## Output contract (STRICT)

1. The top-level value MUST be a JSON **array** of section objects.
2. Every section MUST have a `style_name` that exists in the catalog below.
   `section_name` is optional (the backend auto-names from the style).
3. **Omit any field equal to its catalog `default`.** The importer restores
   defaults. Smaller is better — a compact page is a correct page.
4. `fields` is an object keyed by CMS field name. Each field is keyed by
   **locale**, and each locale holds `{ "content": "…", "meta": "…" }`. Omit
   `meta` when empty.
5. **Field scope decides the locale key** (the catalog prints the scope of
   every field — never guess it from the name):
   - `content` → translatable copy. Author **one entry per content language
     listed in the catalog** (e.g. `en-GB` AND `de-CH`), and you **MUST**
     include the **default** language. **Never** `all`. A `content` field that
     omits the default language renders **EMPTY for the default audience** —
     render-time fallback only fills *non-default* locales from the default,
     never the default itself. (Repeat the same value across locales when a
     string is identical, e.g. an asset path, a name, or a product term.)
   - `common` / `web` / `mobile` → properties. **Always** the single locale
     key `"all"`, no matter how many content languages you listed.
   - Mixing is illegal: never put a property under a real locale, never put
     content under `all`.
6. `global_fields` holds `condition`, `data_config`, `css`, `css_mobile`,
   `debug`. Omit empty keys; omit `global_fields` entirely when all empty.
   `debug` only appears when literally `true`.
7. `children` is an array; **omit when empty**.
8. Booleans stored in a field are **strings** (`"0"` / `"1"`). Real JSON
   booleans are only allowed in `global_fields.debug`.
9. Do NOT include `id`, `position`, `timestamp`, or any ID-like key — the
   backend assigns them.
10. **Enum values are exact.** When the catalog shows `options: "a" | "b"`,
    write one of those exact strings. Free-form fields (`text`, `textarea`,
    `markdown-inline`, `json`) have no list — write what fits.
11. Any field your draft uses that the style's catalog entry does not list →
    drop it. `unknown_field` / `invalid_field_for_style` fail the **whole**
    import.

### Field value representation (quick reference)

- Plain text / markdown / number / boolean → the string goes in `content`.
- Enum (`select` / `segment` / `slider` with options) → the exact option
  `value` string.
- `spacing` type → a **stringified JSON** object of Mantine spacing keys,
  under locale `all` (it is a `common` field). Keys: `mt mb ms me`
  (margin top/bottom/inline-start/inline-end) and `pt pb ps pe` (padding).
  Values: Mantine tokens `"none"|"xs"|"sm"|"md"|"lg"|"xl"` or a px string
  (`"8"`). Omit keys you do not set. Example:
  `"spacing": { "all": { "content": "{\"pt\":\"lg\",\"pb\":\"lg\"}" } }`.
  Never put Tailwind classes here — those go in `global_fields.css`.
- `json` type → stringified JSON in `content`.
- Image fields (`img_src`, `poster_src`, …) → an asset path. For
  placeholder art use `/assets/image-holder.png` (relative). Native cannot
  fetch `localhost` / `http://`; use HTTPS or relative paths only.

---

## Cross-platform authoring (web + mobile-web + native)

The same JSON is rendered by Mantine (web) and HeroUI Native + Uniwind
(Expo). The contract:

- **Carry meaning in semantic styles and `common` fields first.** Layout,
  size, color, variant, radius, gap, alignment, columns — prefer the
  unprefixed `common` fields (`size`, `color`, `variant`, `radius`, `gap`,
  `align`, `justify`, `cols`, `grid_span`, `spacing`). These map to **both**
  renderers.
- `web_*` fields are **web-only enhancement** (Mantine/browser). The native
  renderer ignores them — never make structure depend on a `web_*` value.
- `mobile_*` fields are **native-only overrides**. Web ignores them.
- The reserved names `shared_width`, `shared_height`, `shared_icon` are
  `common` (cross-platform) despite the prefix — the catalog marks them so.
- **Prefer a semantic style over an assembly.** `card`, `alert`,
  `accordion`, `tabs`, `list`, `timeline`, `button`, `badge` already encode
  their meaning for every renderer. Re-building them from `box` + `html-tag`
  works on web but degrades to an empty container on native. Avoid
  `html-tag` for content; use `text` for paragraphs, `highlight` for
  emphasis, `code` for code, `title` for headings.
- Use accessible copy: meaningful heading order via `title` `title_order`,
  real `alt` text on media, labels on inputs, readable contrast, and tap
  targets ≥ 36–44px on mobile-critical actions (don't shrink to `xs`).

### `css` vs `css_mobile`

- `global_fields.css` is **web Tailwind**. It may use responsive (`sm:`,
  `md:`, `lg:`), state (`hover:`, `focus-visible:`) and `dark:` variants.
  This is the default place for web polish.
- `global_fields.css_mobile` is the **cross-platform mobile** field:
  - On **web** every token is auto-prefixed with `max-md:` (applies below
    the `md` breakpoint, <768px).
  - On **native** it is run through the shared Uniwind allow-list / remap
    pipeline (`@selfhelp/shared` `cms-classes`). Tokens outside the
    allow-list — and any token carrying a prefix (`sm:`, `md:`, `dark:`,
    `hover:`, …) — are **silently dropped** on native (they are not
    stripped/recovered). So put **only bare, allow-listed utilities** in
    `css_mobile`; keep prefixed/variant tokens in `css`.
- Native theming (light/dark) should come from semantic fields and HeroUI
  theme tokens, **not** from web `dark:` classes.

### Mobile-safe `css_mobile` allow-list (bare tokens only)

- Spacing: `m{,t,b,l,r,s,e,x,y}-{0..12,xs,sm,md,lg,xl,auto}`,
  `p…` same scale, `gap-{0..12,xs..xl}`.
- Sizing: `w-{full,auto,fit,1/2,1/3,2/3,1/4,3/4}`, `h-{auto,full,fit}`,
  `min-w-0`, `max-w-{xs..xl,full,none}`.
- Typography: `text-{xs..xl,left,center,right,<color>-<0..9>}`,
  `font-{thin..black}`, `leading-{tight..loose}`.
- Surface: `bg-{transparent,white,black,<color>-<0..9>}`,
  `border-{0,1,2,4,<color>-<0..9>}`, `rounded-{none,xs..xl,full}`.
- Flex/grid: `flex-{row,col,wrap,nowrap,1,auto,none}`,
  `items-{start,center,end,stretch}`,
  `justify-{start,center,end,between,around,evenly}`, `col-span-1..12`.
- Atomic: `flex`, `block`, `hidden`, `relative`, `absolute`,
  `overflow-hidden`, `rounded`, `border`, `shadow{,-sm,-md,-lg}`, `italic`,
  `underline`, `truncate`.

---

## Responsive recipes (built from current fields — confirm names in catalog)

- **Responsive column grid** — `simple-grid` base `cols` is the
  mobile + native column count; `web_cols_sm` / `web_cols_md` / `web_cols_lg`
  override it per web breakpoint:
  ```json
  "fields": {
    "cols": { "all": { "content": "1" } },
    "web_cols_sm": { "all": { "content": "2" } },
    "web_cols_lg": { "all": { "content": "3" } }
  }
  ```
  There is **no** responsive-object syntax for columns anymore — use these
  fields, not a stringified `{base,sm,lg}` object.
- **Buttons** — wrap multiple `button`s in a `group`; it lays them out in a
  row on web and stacks gracefully. Use `web_group_wrap` = `"1"` to allow
  wrapping; for a vertical stack on mobile add `css_mobile: "flex-col"`.
- **Cards** — a `card` (set `border`, `radius`) containing a `card-segment`
  for padded content; put `min-w-0 overflow-hidden` in the card `css` so
  long text wraps instead of overflowing.
- **Forms** — `form-record` (one record per user) or `form-log`
  (append-only) wrapping input controls (`text-input`, `textarea`, `select`,
  `checkbox`, `radio`, …). The form renders its own submit/cancel buttons
  (`btn_save_label`, `btn_cancel_label`); do not add an extra `button`. Each
  input's data column is its `name` (a `common` field). Group multi-input
  rows in a `simple-grid` (`cols` 1, `web_cols_md` 2) so they collapse on
  mobile.
- **Media** — `image` (`img_src`, `alt`, `radius`, `is_fluid`), `video`
  (`video_src`, `poster_src`), `audio` (`sources`). Always provide `alt`.
- **Tabs** — a `tabs` parent with `tab` children (each `tab` has a `label`).
- **Long text / overflow** — add `break-words` to text in narrow cards and
  `min-w-0` to flex/grid children that hold long content.

---

## Structural rules (HARD — pre-validation rejects otherwise)

### Slot styles may ONLY appear under their parent

| Style              | Required parent  |
|--------------------|------------------|
| `accordion-item`   | `accordion`      |
| `tab`              | `tabs`           |
| `card-segment`     | `card`           |
| `grid-column`      | `grid`           |
| `list-item`        | `list`           |
| `progress-section` | `progress-root`  |
| `timeline-item`    | `timeline`       |

### Parents whose `children` must be the matching slot

`accordion`→`accordion-item`, `tabs`→`tab`, `card`→`card-segment`,
`grid`→`grid-column`, `list`→`list-item`,
`progress-root`→`progress-section`, `timeline`→`timeline-item`.
(You may nest any other styles **inside** each slot.)

### HTML nesting (avoid React hydration errors on web)

- `text`, `blockquote`, and `highlight` resolve to inline/paragraph text.
  Do not place a block text style inside an `html-tag` whose `html_tag` is
  `"p"`; use the tag's own `html_tag_content`, or a non-paragraph tag.
- Never nest a `button` / `link` / form control inside another `button`.
- `title` renders `<h1>`…`<h6>` from `title_order`, safe inside containers.

### Reading the catalog

Each style header reads `### name (group, renderTarget=both|web|mobile)
[— can_have_children]`, followed by its fields as
`- name (scope, type, default=…[, options: …])` and its
`Allowed children:` / `Allowed parents:` lines. The catalog prints a scope
legend at its top — trust it over any naming intuition.

---

## Worked example (semantic, cross-platform, compact)

`<LOCALES>` = `en-GB,de-CH` (de-CH is this install's **default** — see the
catalog). A hero + responsive feature grid + FAQ. Note: **every** content field
carries BOTH locales (the default is mandatory), property fields stay under
`all`, defaults omitted.

```json
[
  {
    "section_name": "hero",
    "style_name": "container",
    "global_fields": { "css": "px-4 py-12 sm:py-16 lg:py-20 max-w-5xl mx-auto" },
    "children": [
      {
        "style_name": "stack",
        "fields": { "gap": { "all": { "content": "md" } }, "align": { "all": { "content": "center" } } },
        "children": [
          {
            "style_name": "title",
            "fields": {
              "content": {
                "en-GB": { "content": "Build pages once, ship everywhere" },
                "de-CH": { "content": "Seiten einmal bauen, überall ausspielen" }
              },
              "text_align": { "all": { "content": "center" } }
            },
            "global_fields": { "css": "text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 dark:text-gray-50" }
          },
          {
            "style_name": "text",
            "fields": {
              "text": {
                "en-GB": { "content": "One import renders on web, mobile web and native." },
                "de-CH": { "content": "Ein Import rendert im Web, im mobilen Web und nativ." }
              },
              "text_align": { "all": { "content": "center" } }
            },
            "global_fields": { "css": "text-base text-gray-600 dark:text-gray-300 max-w-2xl break-words" }
          },
          {
            "style_name": "group",
            "fields": { "justify": { "all": { "content": "center" } } },
            "global_fields": { "css_mobile": "flex-col" },
            "children": [
              {
                "style_name": "button",
                "fields": {
                  "label": {
                    "en-GB": { "content": "Get started" },
                    "de-CH": { "content": "Loslegen" }
                  },
                  "size": { "all": { "content": "md" } }
                }
              },
              {
                "style_name": "button",
                "fields": {
                  "label": {
                    "en-GB": { "content": "Learn more" },
                    "de-CH": { "content": "Mehr erfahren" }
                  },
                  "variant": { "all": { "content": "default" } },
                  "size": { "all": { "content": "md" } }
                }
              }
            ]
          }
        ]
      }
    ]
  },
  {
    "style_name": "simple-grid",
    "fields": {
      "cols": { "all": { "content": "1" } },
      "web_cols_sm": { "all": { "content": "2" } },
      "web_cols_lg": { "all": { "content": "3" } }
    },
    "global_fields": { "css": "px-4 max-w-6xl mx-auto" },
    "children": [
      {
        "style_name": "card",
        "fields": { "border": { "all": { "content": "1" } }, "radius": { "all": { "content": "lg" } } },
        "global_fields": { "css": "min-w-0 overflow-hidden" },
        "children": [
          {
            "style_name": "card-segment",
            "children": [
              {
                "style_name": "title",
                "fields": {
                  "content": {
                    "en-GB": { "content": "Fast" },
                    "de-CH": { "content": "Schnell" }
                  },
                  "title_order": { "all": { "content": "3" } }
                }
              },
              {
                "style_name": "text",
                "fields": {
                  "text": {
                    "en-GB": { "content": "Pre-rendered pages reach users in milliseconds." },
                    "de-CH": { "content": "Vorgerenderte Seiten erreichen Nutzer in Millisekunden." }
                  }
                },
                "global_fields": { "css": "break-words" }
              }
            ]
          }
        ]
      }
    ]
  },
  {
    "style_name": "accordion",
    "fields": { "accordion_variant": { "all": { "content": "separated" } } },
    "global_fields": { "css": "px-4 max-w-3xl mx-auto" },
    "children": [
      {
        "style_name": "accordion-item",
        "fields": {
          "label": {
            "en-GB": { "content": "Does it work on native?" },
            "de-CH": { "content": "Funktioniert es nativ?" }
          }
        },
        "children": [
          {
            "style_name": "text",
            "fields": {
              "text": {
                "en-GB": { "content": "Yes — the same JSON renders with HeroUI Native." },
                "de-CH": { "content": "Ja – dasselbe JSON rendert mit HeroUI Native." }
              }
            }
          }
        ]
      }
    ]
  }
]
```

Multi-locale is the norm, not an extra: replicate every `content` entry across
**all** content languages from the catalog — including the default
(`"content": { "en-GB": { "content": "…" }, "de-CH": { "content": "…" } }`) —
while property fields keep the single `all` key. Skipping the default language
leaves the field blank for the default audience.

---

## CMS app entry-record detail pages

For `entry-record` sections on parameterized detail routes (for example
`/news/{record_id}`), set `load_record_from` to the route parameter name
(usually `record_id`) — the same field as on `entry-record-form`. Do **not**
emit an author `filter` or the removed `url_param` field on `entry-record`.
Route placeholders are only visible when the caller has page read access to
the linked page.

---

## Validation you can anticipate

The importer pre-validates the whole tree and returns HTTP **422** with
`{path, type, detail}` errors before writing anything:

- `unknown_style` — `style_name` not registered.
- `unknown_field` — field name not in the `fields` table.
- `invalid_field_for_style` — field not part of that style's schema.
- `unknown_locale` — locale not in `languages.locale` (registered: `all`,
  `en-GB`, `de-CH`).
- `missing_style` / `missing_content` — required keys missing.

Fix the offending node(s) and re-emit. Return JSON only.

---

## Style & field catalog (auto-generated — source of truth)

Everything below is regenerated from the live DB schema
(`GET /cms-api/v1/admin/styles/schema`). It lists every style, its
`renderTarget`, whether it accepts children, and each field's **scope**,
type, default and enum options. Do not edit by hand.

<!-- CATALOG:BEGIN -->

<!-- CATALOG:END -->
