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
`/news/{record_id}`), scope the loaded row with an explicit `filter` property
that references the route placeholder, for example
`AND record_id = {{route.record_id}}`. The removed `url_param` field is not
part of the live schema -- never emit it. Route placeholders are only visible
when the caller has page read access to the linked page.

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
> Catalog regenerated: 2026-07-09T19:57:10+00:00

Content languages — author EVERY content-scope field in EACH of these locales. A content field
missing the default language renders EMPTY for the default audience (render-time fallback only
fills NON-default locales from the default, never the default itself):
- de-CH (default)
- en-GB
Property fields (scope common/web/mobile) are language-independent: always use the locale "all".

Field scope legend (scope implies the locale + platform — never re-derive it from the name):
- content — translatable copy; one entry per real locale (e.g. en-GB, de-CH), never "all".
- common — cross-platform property; locale "all" only; renders on web AND native.
- web — web-only property (Mantine/browser); locale "all"; the native renderer ignores it.
- mobile — native-only property (HeroUI Native); locale "all"; the web renderer ignores it.
Reserved names shared_width / shared_height / shared_icon are common (cross-platform) despite the prefix.
Each field reads: `- name (scope, type, default=…[, options: …][, hidden][, disabled])`.
Style headers read: `### name (group, renderTarget=both|web|mobile)[ — can_have_children]`.

### accordion (mantine, renderTarget=both) — can_have_children

Mantine Accordion component for collapsible content

Fields:
- accordion_variant (common, select, default="default") — options: "default" | "contained" | "filled" | "separated" — help: Visual variant of the accordion. On web maps to the Mantine variant (default/contained/filled/separated); on mobile, "default" renders a pl…
- multiple (common, checkbox, default="0") — help: If `multiple` prop is set, multiple panels can be opened simultaneously. For more information check https://mantine.dev/core/accordion
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the accordion. For more information check https://mantine.dev/core/accordion
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Accordion component
- web_accordion_chevron_position (web, segment, default="right") — options: "left" | "right" — help: Sets the position of the chevron icon. For more information check https://mantine.dev/core/accordion
- web_accordion_chevron_size (web, select, default="16") — options: "14" | "16" | "18" | "20" | "24" | "32" — help: Sets the size of the chevron icon in pixels. Choose from preset sizes or enter a custom value (e.g., 12, 14, 16, 18, 20, 24, 32). For more… — placeholder: "16"
- web_accordion_default_value (web, text, default=null) — help: Sets the initially opened item(s). Use comma-separated values for multiple items (e.g., "item-1,item-2"). For more information check https:… — placeholder: "item-1,item-2"
- web_accordion_disable_chevron_rotation (web, checkbox, default="0") — help: If set, chevron icon will not rotate when item is opened. For more information check https://mantine.dev/core/accordion
- web_accordion_loop (web, checkbox, default="1") — help: If set, keyboard navigation will loop from last to first item and vice versa. For more information check https://mantine.dev/core/accordion
- web_accordion_transition_duration (web, select, default="200") — options: "0" | "150" | "200" | "300" | "400" | "500" — help: Sets the duration of expand/collapse transition in milliseconds. Choose from preset durations or enter a custom value (e.g., 100, 150, 200,… — placeholder: "200"

Allowed children: (any)

### accordion-item (mantine, renderTarget=both) — can_have_children

Mantine Accordion.Item component for individual accordion items (accepts all children, panels handled in frontend)

Fields:
- description (content, textarea, default="") — help: Optional subtitle shown under the item label. Leave empty to hide.
- disabled (common, checkbox, default="0") — help: If set, the accordion item will be disabled and cannot be opened. For more information check https://mantine.dev/core/accordion
- label (content, markdown-inline, default=null) — help: Sets the label text displayed in the accordion control. For more information check https://mantine.dev/core/accordion
- spacing (common, spacing, default="") — help: Sets the margin and padding of the AccordionItem component
- web_accordion_item_icon (web, select-icon, default=null) — help: Sets the icon displayed next to the label in the accordion control. For more information check https://mantine.dev/core/accordion
- web_accordion_item_value (web, text, default=null) — help: Unique identifier for the accordion item. Either a custom value or falls back to section-${style.id}. This value is used to control which i… — placeholder: "unique-item-value"

Allowed children: (any)
Allowed parents: accordion

### action-icon (mantine, renderTarget=both)

Mantine ActionIcon component for interactive icons

Fields:
- aria_label (content, text, default=null) — help: Accessible name announced by screen readers for this icon-only control.
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the action icon. For more information check https://mantine.dev/core/action-icon
- disabled (common, checkbox, default="0") — help: If `disabled` prop is set ActionIcon will be disabled. For more information check https://mantine.dev/core/action-icon
- is_link (common, checkbox, default="0") — help: If `isLink` prop is set ActionIcon will be a link. For more information check https://mantine.dev/core/action-icon
- open_in_new_tab (common, checkbox, default="0") — help: If `openInNewTab` prop is set ActionIcon will open in a new tab. For more information check https://mantine.dev/core/action-icon
- page_keyword (common, select-page-keyword, default="#") — help: Select a page keyword to link to. For more information check https://mantine.dev/core/action-icon
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the action icon. For more information check https://mantine.dev/core/action-icon
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the action icon. For more information check https://mantine.dev/core/action-icon
- spacing (common, spacing, default="") — help: Sets the margin and padding of the ActionIcon component
- web_action_icon_loading (web, checkbox, default="0") — help: If `loading` prop is set, action icon will show loading state. For more information check https://mantine.dev/core/action-icon
- web_left_icon (web, select-icon, default=null) — help: Sets the icon for the action icon. For more information check https://mantine.dev/core/action-icon
- web_variant (web, select, default="subtle") — options: "filled" | "light" | "outline" | "subtle" | "default" | "transparent" | "white" — help: Sets the variant of the action icon. For more information check https://mantine.dev/core/action-icon

### alert (mantine, renderTarget=both) — can_have_children

Mantine Alert component for displaying important messages and notifications

Fields:
- alert_title (content, text, default=null) — help: Sets the title of the alert. For more information check https://mantine.dev/core/alert
- closable (common, checkbox, default="0") — help: If set, the alert will have a close button. For more information check https://mantine.dev/core/alert
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the alert. For more information check https://mantine.dev/core/alert
- content (content, textarea, default=null) — help: Sets the main content/message of the alert. For more information check https://mantine.dev/core/alert
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the alert. For more information check https://mantine.dev/core/alert
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Alert component
- web_left_icon (web, select-icon, default=null) — help: Sets the icon for the alert. For more information check https://mantine.dev/core/alert
- web_variant (web, select, default="light") — options: "filled" | "light" | "outline" | "subtle" | "default" | "transparent" | "white" — help: Sets the variant of the alert. For more information check https://mantine.dev/core/alert

Allowed children: (any)

### aspect-ratio (mantine, renderTarget=both) — can_have_children

Mantine AspectRatio component for maintaining aspect ratios

Fields:
- spacing (common, spacing, default="") — help: Sets the margin and padding of the AspectRatio component
- web_aspect_ratio (web, select, default="16/9") — options: "16/9" | "4/3" | "1/1" | "21/9" | "3/2" | "9/16" — help: Sets the aspect ratio of the component. For more information check https://mantine.dev/core/aspect-ratio

Allowed children: (any)

### audio (Media, renderTarget=both)

allows to load and replay an audio source on a page.

Fields:
- alt (content, text, default=null) — help: The alternative text to be displayed if the audio cannot be loaded.
- has_controls (common, checkbox, default="1") — help: Show the native playback controls.
- media_autoplay (common, checkbox, default="0") — help: Start playing automatically.
- media_loop (common, checkbox, default="0") — help: Restart the audio automatically when it ends.
- sources (content, json, default=null) — help: This field expects a [JSON](!https://www.json.org/json-en.html) list of source objects where each object has the following keys: - `source`…
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Audio component

### avatar (mantine, renderTarget=both)

Mantine Avatar component for user profile images

Fields:
- alt (content, text, default="Avatar") — help: Sets the alt text for the avatar image. For more information check https://mantine.dev/core/avatar
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the avatar. For more information check https://mantine.dev/core/avatar
- img_src (content, select-image, default=null) — help: Sets the image source for the avatar. Has priority over icon and initials fields. Either img_src, icon, or initials can be used. If img_src…
- name (common, text, default=null) — help: Name of the person. When no image is set it is shown as initials and seeds an auto-generated colour.
- radius (common, slider, default="full") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the avatar. For more information check https://mantine.dev/core/avatar
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the avatar. For more information check https://mantine.dev/core/avatar
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Avatar component
- web_avatar_initials (web, text, default="U") — help: Sets custom text to generate initials for the avatar. Used only when neither img_src nor icon is set. Enter full names (e.g., "Stefan Kod"… — placeholder: "Enter name to generate initials (e.g., Stefan Kod → SK)"
- web_left_icon (web, select-icon, default=null) — help: Sets the icon for the avatar. Used only when img_src is empty. Either img_src, icon, or initials can be used. Icon will be displayed as the…
- web_variant (web, select, default="light") — options: "filled" | "light" | "outline" | "subtle" | "default" | "transparent" | "white" — help: Sets the variant of the avatar. For more information check https://mantine.dev/core/avatar

### background-image (mantine, renderTarget=both) — can_have_children

Mantine background-image component for background images

Fields:
- img_src (content, select-image, default=null) — help: Sets the background image source. For more information check https://mantine.dev/core/background-image
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the background image container. For more information check https://mantine.dev/core/background-image
- spacing (common, spacing, default="") — help: Sets the margin and padding of the BackgroundImage component

Allowed children: (any)

### badge (mantine, renderTarget=both)

Mantine Badge component for status indicators and labels

Fields:
- circle (common, checkbox, default="0") — help: Render the badge as a circle (equal width and height, no horizontal padding) - ideal for short counts.
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the badge. For more information check https://mantine.dev/core/badge
- label (content, markdown-inline, default=null) — help: Sets the label for the badge. For more information check https://mantine.dev/core/badge
- radius (common, slider, default="lg") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the badge. For more information check https://mantine.dev/core/badge
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the badge. For more information check https://mantine.dev/core/badge
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Badge component
- variant (common, select, default="filled") — options: "filled" | "light" | "outline" | "subtle" | "default" | "transparent" | "white" — help: Visual variant of the badge. Mapped to the Mantine variant on web and HeroUI on mobile.
- web_auto_contrast (web, checkbox, default="0") — help: If `autoContrast` prop is set Badge will automatically adjust the contrast of the badge to the background color. For more information check…
- web_left_icon (web, select-icon, default=null) — help: Sets the left section icon for the badge. For more information check https://mantine.dev/core/badge
- web_right_icon (web, select-icon, default=null) — help: Sets the right section icon for the badge. For more information check https://mantine.dev/core/badge
- web_variant (web, select, default="") — options: "filled" | "light" | "outline" | "subtle" | "default" | "transparent" | "white" — help: Web-only variant override (e.g. "dot"). Leave empty to use the cross-platform Variant.

### blockquote (mantine, renderTarget=both)

Mantine Blockquote component for quoted text

Fields:
- blockquote_content (content, textarea, default=null) — help: Sets the content for the blockquote. For more information check https://mantine.dev/core/blockquote
- cite (content, text, default=null) — help: Sets the citation for the blockquote. For more information check https://mantine.dev/core/blockquote
- color (common, color-picker, default="gray") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the blockquote. For more information check https://mantine.dev/core/blockquote
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Blockquote component
- web_icon_size (web, select, default="20") — options: "14" | "16" | "18" | "20" | "24" | "32" — help: Sets the size of the blockquote icon in pixels. Choose from preset sizes or enter a custom value. For more information check https://mantin… — placeholder: "16"
- web_left_icon (web, select-icon, default=null) — help: Sets the icon for the blockquote. For more information check https://mantine.dev/core/blockquote

### box (mantine, renderTarget=both) — can_have_children

Mantine Box component as a base for all Mantine components with style props support

Fields:
- content (content, textarea, default="") — help: Set text content over the children. For more information check https://mantine.dev/core/box
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Box component

Allowed children: (any)

### button (Link, renderTarget=both)

renders a button-style link with several predefined colour schemes.

Fields:
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Select color for the button. For more information check https://mantine.dev/core/button
- confirmation_continue (content, text, default="OK") — help: Continue button for the modal when the button is clicked
- confirmation_message (content, textarea, default="Do you want to continue?") — help: The message shown on the modal
- confirmation_title (content, text, default="") — help: Confirmation title for the modal when the button is clicked
- disabled (common, checkbox, default="0") — help: If `disabled` prop is set Button will be disabled. For more information check https://mantine.dev/core/button
- full_width (common, checkbox, default="0") — help: If `fullWidth` prop is set Button will take 100% of parent width
- is_link (common, checkbox, default="0") — help: If `isLink` prop is set Button will be a link. For more information check https://mantine.dev/core/button
- label (content, markdown-inline, default=null) — help: The text to appear on the button.
- label_cancel (content, text, default="") — help: Cancel button label on the confirmation modal
- mobile_button_feedback (mobile, select, default="") — options: "scale-highlight" | "scale-ripple" | "scale" | "none" — help: Native press feedback on mobile. Leave empty for the default (scale + highlight).
- open_in_new_tab (common, checkbox, default="0") — help: If `openInNewTab` prop is set Button will open in a new tab. For more information check https://mantine.dev/core/button
- page_keyword (common, select-page-keyword, default="") — help: Select a page keyword for an internal CMS link. Leave empty or # to use Path / external URL instead.
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Select border radius for the button. For more information check https://mantine.dev/core/button
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Select size for the button. For more information check https://mantine.dev/core/button
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Button component
- url (common, text, default="") — help: Absolute path (/…) or external URL when Internal page is unset. Used for profile/back links and mailto:.
- variant (common, select, default="filled") — options: "filled" | "light" | "outline" | "subtle" | "default" | "transparent" | "white" — help: Visual variant of the button. Mapped to the Mantine variant on web and HeroUI on mobile.
- web_auto_contrast (web, checkbox, default="0") — help: If `autoContrast` prop is set Button will automatically adjust the contrast of the button to the background color. For more information che…
- web_compact (web, checkbox, default="0") — help: If `compact` prop is set Button will be smaller. Button supports xs – xl and compact-xs – compact-xl sizes. compact sizes have the same fon…
- web_left_icon (web, select-icon, default=null) — help: `leftSection` and `rightSection` allow adding icons or any other element to the left and right side of the button. When a section is added,…
- web_right_icon (web, select-icon, default=null) — help: `leftSection` and `rightSection` allow adding icons or any other element to the left and right side of the button. When a section is added,…

### card (mantine, renderTarget=both) — can_have_children

Card container component with Mantine styling

Fields:
- border (common, checkbox, default="0") — help: Draw a border around the card. Maps to Mantine `withBorder` on web and a themed border on mobile.
- img_src (content, select-image, default="") — help: Optional image shown at the top of the card. Pick from the asset library; leave empty to hide.
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the card. For more information check https://mantine.dev/core/card
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Card component
- title (content, markdown-inline, default="") — help: Optional heading rendered above the card content. Leave empty to hide.
- web_card_shadow (web, slider, default="sm") — options: "none" | "xs" | "sm" | "md" | "lg" | "xl" — help: Sets the shadow of the card. For more information check https://mantine.dev/core/card

Allowed children: (any)

### card-segment (mantine, renderTarget=both) — can_have_children

Card segment component for organizing card content

Fields:
- border (common, checkbox, default="0") — help: Draw a separating border for this segment. Maps to Mantine Card.Section `withBorder` on web and a themed divider on mobile.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the CardSegment component
- web_segment_inherit_padding (web, checkbox, default="0") — help: Web only: make the segment inherit the card's horizontal padding (Mantine `inheritPadding`).

Allowed children: (any)
Allowed parents: card

### carousel (mantine, renderTarget=both) — can_have_children

Mantine Carousel component for displaying content in a slideshow format

Fields:
- drag_free (common, checkbox, default="0") — help: If set, disables slide snap points allowing free dragging. For more information check https://mantine.dev/x/carousel
- has_controls (common, checkbox, default="1") — help: If set, displays navigation controls (previous/next buttons). For more information check https://mantine.dev/x/carousel
- has_indicators (common, checkbox, default="1") — help: If set, displays slide indicators at the bottom. For more information check https://mantine.dev/x/carousel
- orientation (common, segment, default="horizontal") — options: "horizontal" | "vertical" — help: Sets the orientation of the carousel. For more information check https://mantine.dev/x/carousel
- skip_snaps (common, checkbox, default="0") — help: If set, allows skipping slides without snapping to them. For more information check https://mantine.dev/x/carousel
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Carousel component
- web_carousel_align (web, segment, default="start") — options: "start" | "center" | "end" — help: Sets the alignment of slides. For more information check https://mantine.dev/x/carousel
- web_carousel_contain_scroll (web, segment, default="trimSnaps") — options: "auto" | "trimSnaps" | "keepSnaps" — help: Sets the contain scroll behavior. For more information check https://mantine.dev/x/carousel
- web_carousel_controls_offset (web, slider, default="sm") — options: "xs" | "sm" | "md" | "lg" | "xl" — help: Sets the offset of the navigation controls from the carousel edges. Choose from preset sizes or enter a custom value. For more information…
- web_carousel_duration (web, select, default="25") — options: "10" | "25" | "50" | "100" | "150" | "200" | "0" — help: Sets the transition duration in milliseconds. Choose from preset durations or enter a custom value. For more information check https://mant… — placeholder: "25"
- web_carousel_embla_options (web, json, default=null) — help: Advanced Embla carousel options as JSON. Example: {"loop":true,"align":"center","slidesToScroll":1}. See https://www.embla-carousel.com/api…
- web_carousel_in_view_threshold (web, slider, default="0") — help: Sets the threshold for slide visibility detection (0-1). Use the slider to adjust the percentage. For more information check https://mantin…
- web_carousel_next_control_icon (web, select-icon, default=null) — help: Sets the icon for the next control button. For more information check https://mantine.dev/x/carousel
- web_carousel_previous_control_icon (web, select-icon, default=null) — help: Sets the icon for the previous control button. For more information check https://mantine.dev/x/carousel
- web_carousel_slide_gap (web, slider, default="sm") — options: "xs" | "sm" | "md" | "lg" | "xl" — help: Sets the gap between slides. Choose from preset sizes or enter a custom value. For more information check https://mantine.dev/x/carousel
- web_carousel_slide_size (web, slider, default="100") — help: Sets the size of each slide as a percentage. Use the slider to adjust from 10% to 100%. For more information check https://mantine.dev/x/ca…
- web_control_size (web, select, default="26") — options: "14" | "16" | "18" | "20" | "24" | "32" — help: Sets the size of the navigation controls in pixels. Use the slider to adjust from 14px to 40px. For more information check https://mantine.… — placeholder: "16"
- web_height (web, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "fit-content" | "max-content" | "min-content" — help: Sets the height of the carousel. Choose from preset values or enter a custom value. For more information check https://mantine.dev/x/carous…
- web_loop (web, checkbox, default="0") — help: If set, enables infinite loop navigation. For more information check https://mantine.dev/x/carousel

Allowed children: (any)

### center (mantine, renderTarget=both) — can_have_children

Mantine Center component for centering content

Fields:
- mah (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "200px" | "300px" | "400px" | "500px" | "600px" | "800px" | "1000px" — help: Sets the maximum height of the Center component. For more information check https://mantine.dev/core/center
- maw (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "200px" | "300px" | "400px" | "500px" | "600px" | "800px" | "1000px" — help: Sets the maximum width of the Center component. For more information check https://mantine.dev/core/center
- mih (common, select, default=null) — options: "0" | "25%" | "50%" | "100%" | "200px" | "300px" | "400px" | "500px" — help: Sets the minimum height of the Center component. For more information check https://mantine.dev/core/center
- miw (common, select, default=null) — options: "0" | "25%" | "50%" | "100%" | "200px" | "300px" | "400px" | "500px" — help: Sets the minimum width of the Center component. For more information check https://mantine.dev/core/center
- shared_height (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Height of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- shared_width (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Width of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Center component
- web_center_inline (web, checkbox, default="0") — help: If `inline` prop is set, Center will use inline-flex instead of flex display. For more information check https://mantine.dev/core/center

Allowed children: (any)

### checkbox (mantine, renderTarget=both)

Mantine Checkbox component for boolean input with customizable styling

Fields:
- checkbox_value (common, text, default="1") — help: Sets the checkbox value when checked. For more information check https://mantine.dev/core/checkbox
- color (common, color-picker, default=null) — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the checkbox. Choose from theme colors or enter a custom color. For more information check https://mantine.dev/core/check…
- description (content, textarea, default=null) — help: Sets the description text displayed below the label. For more information check https://mantine.dev/core/checkbox
- disabled (common, checkbox, default="0") — help: If set, the checkbox will be disabled. For more information check https://mantine.dev/core/checkbox
- is_required (common, checkbox, default="0") — help: If set, the checkbox will be required for form submission. For more information check https://mantine.dev/core/checkbox
- label (content, markdown-inline, default=null) — help: Sets the label text displayed next to the checkbox. For more information check https://mantine.dev/core/checkbox
- label_position (common, segment, default="right") — options: "right" | "left" — help: Which side the label sits on (left/right). Applied on web and mobile.
- locked_after_submit (common, checkbox, default="0") — help: If selected and if the field is used in a form that is not `is_log`, once the value is set, the field will not be able to be edited anymore.
- mobile_checkbox_variant (mobile, segment, default="primary") — options: "primary" | "secondary" — help: Native checkbox style on mobile: primary or secondary.
- name (common, text, default=null) — help: Sets the name attribute for the checkbox input. For more information check https://mantine.dev/core/checkbox
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the checkbox. Choose from preset values or enter a custom value. For more information check https://mantine.dev/c…
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the checkbox. Choose from preset sizes (xs, sm, md, lg, xl). For more information check https://mantine.dev/core/checkbox
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Checkbox component
- toggle_switch (common, checkbox, default="0") — help: If enabled and the `type` of the input is `checkbox`, the input will be presented as a `toggle switch`
- value (common, text, default=null) — help: Sets the value attribute for the checkbox input. For more information check https://mantine.dev/core/checkbox
- web_checkbox_checked (web, checkbox, default="0") — help: If `checked` prop is set, checkbox will be in checked state. For more information check https://mantine.dev/core/checkbox
- web_checkbox_icon (web, select-icon, default=null) — help: Sets a custom icon for the checkbox. For more information check https://mantine.dev/core/checkbox
- web_checkbox_indeterminate (web, checkbox, default="0") — help: If `indeterminate` prop is set, checkbox will be in indeterminate state. For more information check https://mantine.dev/core/checkbox
- web_use_input_wrapper (web, checkbox, default="0") — help: When enabled, uses Input.Wrapper for proper label and description handling. When disabled, renders label and description inline next to the…

### chip (mantine, renderTarget=both)

Mantine Chip component for selectable tags

Fields:
- chip_checked (common, checkbox, default="0") — help: If `checked` prop is set, chip will be in checked state. For more information check https://mantine.dev/core/chip
- chip_off_value (common, text, default="0") — help: Value to submit when chip is unchecked/unselected. For more information check https://mantine.dev/core/chip — placeholder: "0"
- chip_on_value (common, text, default="1") — help: Value to submit when chip is checked/selected. For more information check https://mantine.dev/core/chip — placeholder: "1"
- chip_variant (common, select, default="filled") — options: "filled" | "outline" | "light" — help: Visual variant of the chip. On web maps to the Mantine variant (filled/outline/light); on mobile maps to the HeroUI Native chip variant.
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the chip. For more information check https://mantine.dev/core/chip
- disabled (common, checkbox, default="0") — help: If `disabled` prop is set Chip will be disabled. For more information check https://mantine.dev/core/chip
- is_required (common, checkbox, default="0") — help: Makes the chip field required for form submission
- label (content, markdown-inline, default=null) — help: If this field is set, a this text will be rendered inside the chip.
- name (common, text, default=null) — help: Field name for form submission. Either a custom value or falls back to section-${style.id}
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the chip. For more information check https://mantine.dev/core/chip
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the chip. For more information check https://mantine.dev/core/chip
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Chip component
- value (common, text, default=null) — help: Default value for the chip field
- web_icon_size (web, select, default="16") — options: "14" | "16" | "18" | "20" | "24" | "32" — help: Sets the size of the chip icon in pixels. Choose from preset sizes or enter a custom value (e.g., 12, 14, 16, 18, 20, 24, 32). For more inf… — placeholder: "16"
- web_left_icon (web, select-icon, default=null) — help: Sets the icon for the chip. For more information check https://mantine.dev/core/chip
- web_tooltip_position (web, select, default="top") — options: "top" | "bottom" | "left" | "right" | "top-start" | "top-end" | "bottom-start" | "bottom-end" | "left-start" | "left-end" | "right-start" | "right-end" — help: Sets the position where the tooltip will appear relative to the chip.

### code (mantine, renderTarget=both)

Mantine Code component for inline code display

Fields:
- code_block (common, checkbox, default="0") — help: Render as a block (multi-line) instead of inline code. Applied on web and mobile.
- color (common, color-picker, default="gray") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the code. For more information check https://mantine.dev/core/code
- content (content, textarea, default=null) — help: Sets the content for the code. For more information check https://mantine.dev/core/code
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Corner radius of the code block. Mapped per platform.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Code component

### color-input (mantine, renderTarget=both)

Mantine color-input component for color selection

Fields:
- description (content, textarea, default="") — help: Description text displayed below the input field
- disabled (common, checkbox, default="0") — help: If `disabled` prop is set color-input will be disabled. For more information check https://mantine.dev/core/color-input
- is_required (common, checkbox, default="0") — help: If set, the color selection becomes required for form submission
- label (content, markdown-inline, default="") — help: Sets the label of the input field. For more information check https://mantine.dev/core/color-input
- name (common, text, default="") — help: Field name for form submission
- placeholder (content, text, default="Pick a color") — help: Sets the placeholder text for the color input. For more information check https://mantine.dev/core/color-input
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the color input. For more information check https://mantine.dev/core/color-input
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the color input. For more information check https://mantine.dev/core/color-input
- spacing (common, spacing, default="") — help: Sets the margin and padding of the ColorInput component
- value (common, text, default="") — help: Default color value for the color input. Supports hex, rgba, or hsl formats
- web_color_format (web, segment, default="hex") — options: "hex" | "rgba" | "hsla" — help: Sets the format of the color input. For more information check https://mantine.dev/core/color-input
- web_color_input_disallow_input (web, checkbox, default="0") — help: Pick-only: prevent typing a colour value by hand.
- web_color_input_with_eye_dropper (web, checkbox, default="1") — help: Show the eye-dropper button to pick a colour from anywhere on screen.
- web_color_input_with_preview (web, checkbox, default="1") — help: Show the selected-colour preview swatch inside the field.

### color-picker (mantine, renderTarget=both)

Mantine color-picker component for color selection

Fields:
- color_picker_alpha_label (content, text, default="Alpha") — help: Accessibility label for the alpha slider. For more information check https://mantine.dev/core/color-picker — placeholder: "Alpha"
- color_picker_hue_label (content, text, default="Hue") — help: Accessibility label for the hue slider. For more information check https://mantine.dev/core/color-picker — placeholder: "Hue"
- color_picker_saturation_label (content, text, default="Saturation") — help: Accessibility label for the saturation slider. For more information check https://mantine.dev/core/color-picker — placeholder: "Saturation"
- description (content, textarea, default="") — help: Description text displayed below the input field
- full_width (common, checkbox, default="0") — help: If set, the color picker will take the full width of its container. For more information check https://mantine.dev/core/color-picker
- is_required (common, checkbox, default="0") — help: If set, the color selection becomes required for form submission
- label (content, markdown-inline, default="") — help: Sets the label of the input field. For more information check https://mantine.dev/core/color-picker
- name (common, text, default="") — help: Field name for form submission
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the color picker. For more information check https://mantine.dev/core/color-picker
- spacing (common, spacing, default="") — help: Sets the margin and padding of the ColorPicker component
- value (common, text, default="") — help: Default color value for the color picker. Supports hex, rgba, or hsl formats
- web_color_format (web, segment, default="hex") — options: "hex" | "rgba" | "hsla" — help: Sets the format of the color picker. For more information check https://mantine.dev/core/color-picker
- web_color_picker_swatches (web, json, default="[\"#2e2e2e\", \"#868e96\", \"#fa5252\", \"#e64980\", \"#be4bdb\", \"#7950f2\", \"#4c6ef5\", \"#228be6\"]") — help: Array of predefined color swatches. Enter as JSON array of hex color strings. For more information check https://mantine.dev/core/color-pic…
- web_color_picker_swatches_per_row (web, slider, default="7") — options: "3" | "4" | "5" | "6" | "7" | "8" — help: Sets the number of swatches per row. For more information check https://mantine.dev/core/color-picker

### combobox (mantine, renderTarget=both)

Mantine Combobox component for advanced select inputs

Fields:
- combobox_options (common, json, default="[{\"value\":\"option1\",\"text\":\"Option 1\"},{\"value\":\"option2\",\"text\":\"Option 2\"}]") — help: Define the stable option codes stored in submitted data. Enter translated display labels in the grid for each CMS language. Example catalog… — placeholder: "Enter JSON array of combobox options"
- description (content, textarea, default="") — help: Description text displayed below the input field
- disabled (common, checkbox, default="0") — help: If `disabled` prop is set Combobox will be disabled. For more information check https://mantine.dev/core/combobox
- is_required (common, checkbox, default="0") — help: If set, the selection becomes required for form submission
- label (content, markdown-inline, default="") — help: Sets the label of the input field. For more information check https://mantine.dev/core/combobox
- mobile_select_presentation (mobile, select, default="") — options: "bottom-sheet" | "dialog" | "popover" — help: How the option list opens on mobile. Leave empty for the default (bottom sheet).
- name (common, text, default="") — help: Field name for form submission
- option_labels (content, json, default="{}") — help: Map each stable code to the translated label for this CMS language. Codes must match the option catalog. Example labels for one language: `…
- placeholder (content, text, default="Select option") — help: Sets the placeholder text for the combobox. For more information check https://mantine.dev/core/combobox
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Combobox component
- value (common, text, default="") — help: Default value for the combobox
- web_combobox_clearable (web, checkbox, default="0") — help: If set, allows clearing the selection in single-select mode.
- web_combobox_creatable (web, checkbox, default="0") — help: If set, allows users to create new options not in the predefined list.
- web_combobox_multi_select (web, checkbox, default="0") — help: If set, allows selecting multiple options. Values will be joined with the separator.
- web_combobox_searchable (web, checkbox, default="1") — help: If set, enables search functionality in the dropdown.
- web_combobox_separator (web, text, default=" ") — help: Separator used to join multiple selected values (only applies when multi-select is enabled).
- web_multi_select_max_values (web, select, default=null) — options: "3" | "5" | "10" | "25" — help: Sets the maximum number of values that can be selected. For more information check https://mantine.dev/core/multi-select

### container (mantine, renderTarget=both) — can_have_children

Mantine Container component for responsive layout containers

Fields:
- size (common, slider, default=null) — options: "sm" | "md" | "lg" — help: Sets the maximum width of the Container component. Choose from predefined responsive breakpoints or enter custom pixel values. For more inf…
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Container component
- web_fluid (web, checkbox, default="0") — help: If `fluid` prop is set Container will take 100% of parent width, ignoring size prop. For more information check https://mantine.dev/core/co…

Allowed children: (any)

### data-container (Wrapper, renderTarget=both) — can_have_children

Data container style which propagate all loaded data to its children.

Fields:
- scope (common, text, default="") — help: If the variable `scope` is defined, it serves as a prefix for naming the variables

Allowed children: (any)

### datepicker (mantine, renderTarget=both)

Mantine DatePicker component for date, time, and datetime input with comprehensive formatting options

Fields:
- datepicker_placeholder (content, text, default=null) — help: Sets the placeholder text for the input field. For more information check https://mantine.dev/dates/getting-started
- description (content, textarea, default=null) — help: Sets the description text displayed below the label. For more information check https://mantine.dev/dates/getting-started
- disabled (common, checkbox, default="0") — help: If set, the date picker will be disabled. For more information check https://mantine.dev/dates/getting-started
- is_required (common, checkbox, default="0") — help: If set, the date picker will be required for form submission. For more information check https://mantine.dev/dates/getting-started
- label (content, markdown-inline, default=null) — help: Sets the label text displayed above the date picker. For more information check https://mantine.dev/dates/getting-started
- name (common, text, default=null) — help: Sets the name attribute for form submission. For more information check https://mantine.dev/dates/getting-started
- spacing (common, spacing, default="") — help: Sets the margin and padding of the DatePicker component
- value (common, text, default=null) — help: Sets the initial date/time value. For more information check https://mantine.dev/dates/getting-started
- web_datepicker_allow_deselect (web, checkbox, default="0") — help: If set, allows deselecting the current date/time. For more information check https://mantine.dev/dates/getting-started
- web_datepicker_clearable (web, checkbox, default="0") — help: If set, allows clearing the selected date/time. For more information check https://mantine.dev/dates/getting-started
- web_datepicker_consistent_weeks (web, checkbox, default="0") — help: If set, every month will have 6 weeks to avoid layout shifts. For more information check https://mantine.dev/dates/getting-started
- web_datepicker_date_format (web, select, default="YYYY-MM-DD") — options: "YYYY-MM-DD" | "MM/DD/YYYY" | "DD/MM/YYYY" | "DD.MM.YYYY" | "MMM DD, YYYY" | "DD MMM YYYY" — help: Sets the date format pattern for form submission. Choose from presets or enter a custom format. For more information check https://mantine.… — placeholder: "YYYY-MM-DD"
- web_datepicker_first_day_of_week (web, segment, default="1") — options: "0" | "1" | "2" | "3" | "4" | "5" | "6" — help: Sets the first day of the week (0=Sunday, 1=Monday, etc.). For more information check https://mantine.dev/dates/getting-started
- web_datepicker_format (web, select, default=null) — options: "YYYY-MM-DD" | "MM/DD/YYYY" | "DD/MM/YYYY" | "DD.MM.YYYY" | "MMM DD, YYYY" | "DD MMM YYYY" — help: Sets the custom format string for date/time display. For more information check https://mantine.dev/dates/getting-started — placeholder: "YYYY-MM-DD"
- web_datepicker_hide_outside_dates (web, checkbox, default="0") — help: If set, hides dates from other months. For more information check https://mantine.dev/dates/getting-started
- web_datepicker_hide_weekends (web, checkbox, default="0") — help: If set, hides weekend days from the calendar. For more information check https://mantine.dev/dates/getting-started
- web_datepicker_locale (web, text, default="en") — help: Sets the locale for date formatting and calendar display. For more information check https://mantine.dev/dates/getting-started
- web_datepicker_max_date (web, text, default=null) — help: Sets the maximum selectable date. For more information check https://mantine.dev/dates/getting-started
- web_datepicker_min_date (web, text, default=null) — help: Sets the minimum selectable date. For more information check https://mantine.dev/dates/getting-started
- web_datepicker_readonly (web, checkbox, default="0") — help: If set, the date picker will be readonly. For more information check https://mantine.dev/dates/getting-started
- web_datepicker_time_format (web, segment, default="24") — options: "12" | "24" — help: Sets the time format (12-hour or 24-hour). For more information check https://mantine.dev/dates/getting-started
- web_datepicker_time_grid_config (web, json, default=null) — help: JSON configuration for TimeGrid layout (e.g., {"cols": {"base": 2, "sm": 3}, "spacing": "xs"}). For more information check https://mantine.…
- web_datepicker_time_step (web, segment, default="15") — options: "1" | "5" | "10" | "15" | "30" | "60" — help: Sets the time step in minutes for time selection. For more information check https://mantine.dev/dates/getting-started
- web_datepicker_type (web, segment, default="date") — options: "date" | "time" | "datetime" — help: Sets the type of date picker (date only, time only, or date & time). For more information check https://mantine.dev/dates/getting-started
- web_datepicker_weekend_days (web, text, default="[0,6]") — help: Sets which days are considered weekends as a JSON array. For more information check https://mantine.dev/dates/getting-started
- web_datepicker_with_seconds (web, checkbox, default="0") — help: If set, includes seconds in time selection. For more information check https://mantine.dev/dates/getting-started
- web_datepicker_with_time_grid (web, checkbox, default="0") — help: If set, shows a time grid for time selection. For more information check https://mantine.dev/dates/getting-started

### divider (mantine, renderTarget=both)

Mantine Divider component for visual separation

Fields:
- color (common, color-picker, default="gray") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the divider. For more information check https://mantine.dev/core/divider
- divider_label (content, text, default=null) — help: Sets the label text for the divider. For more information check https://mantine.dev/core/divider — placeholder: "Divider label"
- divider_label_position (common, select, default="center") — options: "left" | "center" | "right" — help: Sets the position of the divider label. For more information check https://mantine.dev/core/divider
- divider_variant (common, select, default="solid") — options: "solid" | "dashed" | "dotted" — help: Sets the variant of the divider line. For more information check https://mantine.dev/core/divider
- orientation (common, segment, default="horizontal") — options: "horizontal" | "vertical" — help: Sets the orientation of the divider. For more information check https://mantine.dev/core/divider
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the thickness of the divider line. For more information check https://mantine.dev/core/divider
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Divider component

### entry-list (Wrapper, renderTarget=both) — can_have_children

Wrap other styles that later visualize list of entries (inserted via `formUserInput`).

Fields:
- data_table (common, select-data_table, default="") — help: Select a data table which will be linked to the style
- filter (common, entry-filter, default=null) — help: SQL filter fragment (AND ...). Detail pages must include `AND record_id = {{route.record_id}}` so the current row loads.
- load_as_table (common, checkbox, default="0") — help: If enabled, the children are loaded inside a table.
- own_entries_only (common, checkbox, default="1") — help: If selected the entry list will load only the records entered by the user.
- scope (common, text, default="") — help: If the variable `scope` is defined, it serves as a prefix for naming the variables
- selected_columns (common, select-data_table_columns, default="") — help: Optional comma-separated list of data columns to load. Leave empty to load all columns.

Allowed children: (any)

### entry-record (Wrapper, renderTarget=both) — can_have_children

Wrap other styles that later visualize a record from the entry list

Fields:
- data_table (common, select-data_table, default="") — help: Select a data table which will be linked to the style
- filter (common, entry-filter, default=null) — help: SQL filter fragment (AND ...). Must include `AND record_id = {{route.record_id}}` (or the matching route token) so the current row is visible.
- own_entries_only (common, checkbox, default="1") — help: If selected the entry record will load only when it belongs to the current user.
- scope (common, text, default="") — help: If the variable `scope` is defined, it serves as a prefix for naming the variables

Allowed children: (any)

### entry-record-delete (Wrapper, renderTarget=both)

Style that allows the user to delete entry record

Fields:
- confirmation_cancel (content, markdown-inline, default="") — help: Cancel button label on the confirmation modal
- confirmation_continue (content, text, default="OK") — help: Continue button for the modal when the button is clicked
- confirmation_message (content, textarea, default="Do you want to continue?") — help: The message shown on the modal
- confirmation_title (content, text, default="") — help: Confirmation title for the modal when the button is clicked
- label_delete (content, text, default="Delete") — help: The label for the delete button.
- own_entries_only (common, checkbox, default="1") — help: If enabled the `entryRecordDelete` will be able to delete only entries that belong to the user.

### entry-record-form (Form, renderTarget=both) — can_have_children

Route-aware form for CMS and public surfaces: blank route creates a row; a route record id loads that row for edit (permission-gated).

Fields:
- alert_error (content, textarea, default="An error occurred while saving the record") — help: Error message displayed when form submission fails
- alert_error_title (content, text, default="Error") — help: Heading of the error alert shown when a submit fails.
- alert_success (content, text, default="") — help: Success message displayed after form submission
- alert_success_title (content, text, default="Success") — help: Heading of the success alert shown after a successful submit.
- btn_cancel_color (common, color-picker, default="gray") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Color theme for the cancel button
- btn_cancel_label (content, text, default="Cancel") — help: Text displayed on the cancel button
- btn_cancel_url (common, select-page-keyword, default=null) — help: URL to navigate to when cancel button is clicked
- btn_save_color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Color theme for the save button
- btn_save_label (content, text, default="Save") — help: Text displayed on the save button for new records
- btn_update_color (common, color-picker, default="orange") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Color theme for the update button
- btn_update_label (content, text, default="Update") — help: Text displayed on the update button for existing records
- buttons_order (common, segment, default="save-cancel") — options: "save-cancel" | "cancel-save" — help: Order of buttons (which button appears first)
- buttons_position (common, select, default="space-between") — options: "space-between" | "center" | "flex-end" | "flex-start" — help: Positioning of the buttons container — placeholder: "space-between"
- buttons_radius (common, slider, default="sm") — options: "none" | "xs" | "sm" | "md" | "lg" | "xl" — help: Border radius of the form buttons
- buttons_size (common, slider, default="sm") — options: "xs" | "sm" | "md" | "lg" | "xl" — help: Size of the form buttons
- buttons_variant (common, select, default="filled") — options: "filled" | "light" | "outline" | "transparent" | "white" | "subtle" | "gradient" — help: Visual style variant for the buttons — placeholder: "filled"
- close_modal_on_save (common, checkbox, default="0") — help: When enabled, a successful submit closes the surrounding modal (if this form is shown inside one).
- confirm_message (content, textarea, default="Are you sure you want to submit?") — help: Message shown in the confirmation dialog before submit.
- confirm_submit (common, checkbox, default="0") — help: When enabled, a confirmation dialog is shown before the form is submitted.
- data_table (common, select-data_table, default="") — help: Data table for this form. Pick an existing table or leave empty to use the table owned by this section (created automatically).
- description (content, textarea, default="") — help: Optional sub-heading shown below the title.
- load_record_from (common, text, default="record_id") — help: Route parameter carrying the record id (e.g. `record_id` on `/cms/team/{record_id}`). When present the form loads that record; when absent…
- own_entries_only (common, checkbox, default="1") — help: When enabled the form only ever loads and updates the current user's own records. Disable for shared/admin editing: another user's record c…
- redirect_at_end (common, select-page-keyword, default=null) — help: URL to redirect to after successful form submission
- redirect_on_save (common, select-page-keyword, default="") — help: Optional URL to navigate to after a successful submit (the parent list is refreshed). Leave empty to stay/close.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the FormRecord component
- title (content, markdown-inline, default="") — help: Optional heading shown above the form. Leave empty to hide.

Allowed children: (any)

### entry-table (Form, renderTarget=both)

Built-in admin data grid for a form's entries: search, sorting, pagination, CSV export and add/edit/delete row actions.

Fields:
- add_url (common, select-page-keyword, default="") — help: Page or custom URL for the create form. When set, an "Add new" button is shown above the table.
- csv_export (common, checkbox, default="0") — help: Show a button to export the table as CSV.
- data_table (common, select-data_table, default="") — help: The data table whose entries to display.
- delete_entry (common, checkbox, default="1") — help: Show per-row delete buttons (subject to own_entries_only restriction).
- delete_modal_body (content, textarea, default="Are you sure you want to delete this entry?") — help: Confirmation message shown on the delete modal.
- delete_modal_title (content, text, default="Delete entry") — help: Title shown on the delete confirmation modal.
- dt_default_order_column (common, select-data_table_column, default="") — help: Name of the column to sort by default. Leave empty for no default sort.
- dt_default_order_dir (common, select, default="asc") — options: "asc" | "desc" — help: Direction for the default column sort.
- dt_info (common, checkbox, default="0") — help: Show "Showing X–Y of Z entries" footer.
- dt_paginate (common, checkbox, default="0") — help: Group rows into pages.
- dt_searching (common, checkbox, default="0") — help: Show a search box above the table.
- dt_sortable (common, checkbox, default="0") — help: Enable sorting on all columns.
- edit_url (common, select-page-keyword, default="") — help: Page or custom URL template for row edit (use {record_id} as placeholder).
- empty_text (content, textarea, default="No entries found.") — help: Message shown when there are no entries to display.
- fields_map (common, fields-map, default="") — help: Ordered list of field_key values to show in the grid. Header labels are configured in Column header labels.
- fields_map_labels (content, json, default="") — help: Per-language labels keyed by field_key (e.g. {"section_230":"Name"}). The column order comes from Column mapping.
- own_entries_only (common, checkbox, default="1") — help: When enabled, users see only their own submissions. Disabling allows viewing all entries (subject to data-access permissions).
- show_language_preview (common, checkbox, default="0") — help: When enabled, the web table shows a language selector above the grid and reloads translatable column values for the chosen locale. Mainly f…
- show_timestamp (common, checkbox, default="0") — help: When enabled, the leading column shows the submission timestamp instead of the internal record ID.
- spacing (common, spacing, default="") — help: Controls horizontal and vertical cell spacing (xs / sm / md / lg / xl).
- title (content, markdown-inline, default="") — help: Optional heading shown above the entries. Leave empty to hide.
- web_table_caption_side (web, segment, default="") — options: "top" | "bottom" — help: Position of the table caption: top or bottom.
- web_table_highlight_on_hover (web, checkbox, default="1") — help: Highlight the row the cursor is over.
- web_table_sticky_header (web, checkbox, default="0") — help: Keep the header row visible while scrolling.
- web_table_striped (web, checkbox, default="0") — help: Alternate row background colours.
- web_table_with_column_borders (web, checkbox, default="1") — help: Draw borders between columns.
- web_table_with_row_borders (web, checkbox, default="0") — help: Draw borders between rows.
- web_table_with_table_border (web, checkbox, default="1") — help: Draw a border around the entire table.

### fieldset (mantine, renderTarget=both) — can_have_children

Mantine Fieldset component for grouping form elements

Fields:
- disabled (common, checkbox, default="0") — help: If set, disables all inputs and buttons inside the fieldset. For more information check https://mantine.dev/core/fieldset
- label (content, markdown-inline, default=null) — help: Sets the legend/title of the fieldset. For more information check https://mantine.dev/core/fieldset
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the fieldset. For more information check https://mantine.dev/core/fieldset
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Fieldset component
- web_fieldset_variant (web, select, default="default") — options: "default" | "filled" | "unstyled" — help: Sets the variant of the fieldset. For more information check https://mantine.dev/core/fieldset

Allowed children: (any)

### figure (Media, renderTarget=both) — can_have_children

allows to attach a caption to media elements. A figure expects a media style as its immediate child.

Fields:
- alt (content, text, default=null) — help: Alternative text for the built-in image (accessibility).
- caption (content, markdown-inline, default=null) — help: The caption of the figure.
- caption_title (content, text, default=null) — help: The title to be prepended to the text defined in filed `caption`.
- img_src (content, select-image, default=null) — help: Optional built-in image. Leave empty to compose the figure from child sections instead.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Figure component

Allowed children: (any)

### file-input (mantine, renderTarget=both)

Mantine FileInput component for file uploads

Fields:
- description (content, textarea, default=null) — help: Sets the description for the file input. For more information check https://mantine.dev/core/file-input
- disabled (common, checkbox, default="0") — help: If `disabled` prop is set FileInput will be disabled. For more information check https://mantine.dev/core/file-input
- is_required (common, checkbox, default="0") — help: If `is_required` prop is set FileInput will be required. For more information check https://mantine.dev/core/file-input
- label (content, markdown-inline, default=null) — help: Sets the label for the file input. For more information check https://mantine.dev/core/file-input
- name (common, text, default=null) — help: Sets the name attribute for the file input, used for form submission. If not set, falls back to section-${style.id}. For more information c…
- placeholder (content, text, default="Select files") — help: Sets the placeholder text for the file input. For more information check https://mantine.dev/core/file-input
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the file input. For more information check https://mantine.dev/core/file-input
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the file input. For more information check https://mantine.dev/core/file-input
- spacing (common, spacing, default="") — help: Sets the margin and padding of the FileInput component
- web_file_input_accept (web, select, default=null) — options: "image/*" | "image/png,image/jpeg,image/gif" | "image/png" | "image/jpeg" | "image/webp" | "audio/*" | "video/*" | ".pdf" | ".doc,.docx" | ".xls,.xlsx" | ".ppt,.pptx" | ".txt" | ".zip,.rar" | "application/json" | "text/csv" — help: Sets the accepted file types for the file input. Choose from presets or enter custom MIME types separated by commas. For more information c… — placeholder: "image/*"
- web_file_input_clearable (web, checkbox, default="1") — help: If set, displays a clear button when files are selected. For more information check https://mantine.dev/core/file-input
- web_file_input_drag_drop (web, checkbox, default="0") — help: If set, enables drag and drop functionality for file uploads. For more information check https://mantine.dev/core/file-input
- web_file_input_max_files (web, select, default=null) — options: "1" | "3" | "5" | "10" | "20" | "50" | "100" — help: Sets the maximum number of files that can be selected when multiple is enabled. Choose from presets or enter a custom value. For more infor… — placeholder: "5"
- web_file_input_max_size (web, select, default=null) — options: "1024" | "10240" | "102400" | "524288" | "1048576" | "2097152" | "5242880" | "10485760" | "20971520" | "52428800" | "104857600" — help: Sets the maximum file size in bytes. Choose from presets or enter a custom value. For more information check https://mantine.dev/core/file-… — placeholder: "5242880"
- web_file_input_multiple (web, checkbox, default="0") — help: If `multiple` prop is set, multiple files can be selected. For more information check https://mantine.dev/core/file-input
- web_left_icon (web, select-icon, default=null) — help: Sets the icon displayed in the left section of the file input. For more information check https://mantine.dev/core/file-input
- web_right_icon (web, select-icon, default=null) — help: Sets the icon displayed in the right section of the file input. For more information check https://mantine.dev/core/file-input

### flex (mantine, renderTarget=both) — can_have_children

Mantine Flex component for flexible layouts

Fields:
- align (common, select, default=null) — options: "flex-start" | "center" | "flex-end" | "stretch" | "baseline" — help: Sets the align-items property. For more information check https://mantine.dev/core/flex
- direction (common, segment, default="row") — options: "row" | "column" | "row-reverse" | "column-reverse" — help: Sets the flex-direction property. For more information check https://mantine.dev/core/flex
- gap (common, slider, default="sm") — options: "0" | "xs" | "sm" | "md" | "lg" | "xl" — help: Sets the gap between flex items. For more information check https://mantine.dev/core/flex
- justify (common, select, default=null) — options: "flex-start" | "center" | "flex-end" | "space-between" | "space-around" | "space-evenly" — help: Sets the justify-content property. For more information check https://mantine.dev/core/flex
- shared_height (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Height of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- shared_width (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Width of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Flex component
- wrap (common, segment, default="nowrap") — options: "wrap" | "nowrap" | "wrap-reverse" — help: Sets the flex-wrap property. For more information check https://mantine.dev/core/flex

Allowed children: (any)

### form-log (Form, renderTarget=both) — can_have_children

Log form component that clears data after successful submission. Supports multiple entries and form validation.

Fields:
- alert_error (content, textarea, default="An error occurred while submitting the form") — help: Error message displayed when form submission fails
- alert_error_title (content, text, default="Error") — help: Heading of the error alert shown when a submit fails.
- alert_success (content, text, default="") — help: Success message displayed after form submission
- alert_success_title (content, text, default="Success") — help: Heading of the success alert shown after a successful submit.
- btn_cancel_color (common, color-picker, default="gray") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Color theme for the cancel button
- btn_cancel_label (content, text, default="Cancel") — help: Text displayed on the cancel button
- btn_cancel_url (common, select-page-keyword, default=null) — help: URL to navigate to when cancel button is clicked
- btn_save_color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Color theme for the save button
- btn_save_label (content, text, default="Save") — help: Text displayed on the save button for new records
- buttons_order (common, segment, default="save-cancel") — options: "save-cancel" | "cancel-save" — help: Order of buttons (which button appears first)
- buttons_position (common, select, default="space-between") — options: "space-between" | "center" | "flex-end" | "flex-start" — help: Positioning of the buttons container — placeholder: "space-between"
- buttons_radius (common, slider, default="sm") — options: "none" | "xs" | "sm" | "md" | "lg" | "xl" — help: Border radius of the form buttons
- buttons_size (common, slider, default="sm") — options: "xs" | "sm" | "md" | "lg" | "xl" — help: Size of the form buttons
- buttons_variant (common, select, default="filled") — options: "filled" | "light" | "outline" | "transparent" | "white" | "subtle" | "gradient" — help: Visual style variant for the buttons — placeholder: "filled"
- close_modal_on_save (common, checkbox, default="0") — help: When enabled, a successful submit closes the surrounding modal (if this form is shown inside one).
- confirm_message (content, textarea, default="Are you sure you want to submit?") — help: Message shown in the confirmation dialog before submit.
- confirm_submit (common, checkbox, default="0") — help: When enabled, a confirmation dialog is shown before the form is submitted.
- data_table (common, select-data_table, default="") — help: Data table that stores submissions for this form section.
- description (content, textarea, default="") — help: Optional sub-heading shown below the title.
- redirect_at_end (common, select-page-keyword, default=null) — help: URL to redirect to after successful form submission
- redirect_on_save (common, select-page-keyword, default="") — help: Optional URL to navigate to after a successful submit (the parent list is refreshed). Leave empty to stay/close.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the FormLog component
- title (content, markdown-inline, default="") — help: Optional heading shown above the form. Leave empty to hide.

Allowed children: (any)

### form-record (Form, renderTarget=both) — can_have_children

Record form component that preserves data and updates existing records. Pre-populates fields with existing data.

Fields:
- alert_error (content, textarea, default="An error occurred while saving the record") — help: Error message displayed when form submission fails
- alert_error_title (content, text, default="Error") — help: Heading of the error alert shown when a submit fails.
- alert_success (content, text, default="") — help: Success message displayed after form submission
- alert_success_title (content, text, default="Success") — help: Heading of the success alert shown after a successful submit.
- btn_cancel_color (common, color-picker, default="gray") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Color theme for the cancel button
- btn_cancel_label (content, text, default="Cancel") — help: Text displayed on the cancel button
- btn_cancel_url (common, select-page-keyword, default=null) — help: URL to navigate to when cancel button is clicked
- btn_save_color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Color theme for the save button
- btn_save_label (content, text, default="Save") — help: Text displayed on the save button for new records
- btn_update_color (common, color-picker, default="orange") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Color theme for the update button
- btn_update_label (content, text, default="Update") — help: Text displayed on the update button for existing records
- buttons_order (common, segment, default="save-cancel") — options: "save-cancel" | "cancel-save" — help: Order of buttons (which button appears first)
- buttons_position (common, select, default="space-between") — options: "space-between" | "center" | "flex-end" | "flex-start" — help: Positioning of the buttons container — placeholder: "space-between"
- buttons_radius (common, slider, default="sm") — options: "none" | "xs" | "sm" | "md" | "lg" | "xl" — help: Border radius of the form buttons
- buttons_size (common, slider, default="sm") — options: "xs" | "sm" | "md" | "lg" | "xl" — help: Size of the form buttons
- buttons_variant (common, select, default="filled") — options: "filled" | "light" | "outline" | "transparent" | "white" | "subtle" | "gradient" — help: Visual style variant for the buttons — placeholder: "filled"
- close_modal_on_save (common, checkbox, default="0") — help: When enabled, a successful submit closes the surrounding modal (if this form is shown inside one).
- confirm_message (content, textarea, default="Are you sure you want to submit?") — help: Message shown in the confirmation dialog before submit.
- confirm_submit (common, checkbox, default="0") — help: When enabled, a confirmation dialog is shown before the form is submitted.
- description (content, textarea, default="") — help: Optional sub-heading shown below the title.
- name (common, text, default="") — help: Human-readable table name slug (the runtime table is still owned by this section id).
- own_entries_only (common, checkbox, default="1") — help: When enabled the form only ever loads and updates the current user's own records. Disable for shared/admin editing: another user's record c…
- redirect_at_end (common, select-page-keyword, default=null) — help: URL to redirect to after successful form submission
- redirect_on_save (common, select-page-keyword, default="") — help: Optional URL to navigate to after a successful submit (the parent list is refreshed). Leave empty to stay/close.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the FormRecord component
- title (content, markdown-inline, default="") — help: Optional heading shown above the form. Leave empty to hide.

Allowed children: (any)

### grid (mantine, renderTarget=both)

Mantine Grid component for responsive 12 columns grid system

Fields:
- align (common, select, default=null) — options: "flex-start" | "center" | "flex-end" | "stretch" | "baseline" — help: Sets the align-items CSS property for the grid. For more information check https://mantine.dev/core/grid
- cols (common, slider, default="12") — options: "1" | "2" | "3" | "4" | "5" | "6" — help: Sets the total number of columns in the grid (default 12). For more information check https://mantine.dev/core/grid
- gap (common, slider, default="sm") — options: "0" | "xs" | "sm" | "md" | "lg" | "xl" — help: Sets the gutter (spacing) between grid columns. For more information check https://mantine.dev/core/grid
- justify (common, select, default=null) — options: "flex-start" | "center" | "flex-end" | "space-between" | "space-around" | "space-evenly" — help: Sets the justify-content CSS property for the grid. For more information check https://mantine.dev/core/grid
- shared_height (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Height of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- shared_width (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Width of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Grid component
- web_grid_overflow (web, segment, default="visible") — options: "visible" | "hidden" — help: Sets the overflow CSS property for the grid container. For more information check https://mantine.dev/core/grid

Allowed children: grid-column

### grid-column (mantine, renderTarget=both) — can_have_children

Mantine Grid.Col component for grid column with span, offset, and order controls

Fields:
- grid_grow (common, checkbox, default="0") — help: If `grow` prop is set, column will grow to fill the remaining space in the row. For more information check https://mantine.dev/core/grid
- grid_offset (common, slider, default="0") — options: "0" | "1" | "2" | "3" | "4" | "5" | "6" | "7" | "8" | "9" | "10" | "11" — help: Sets the offset (left margin) of the column. Number from 0-11. For more information check https://mantine.dev/core/grid
- grid_order (common, slider, default=null) — options: "1" | "2" | "3" | "4" | "5" | "6" | "7" | "8" | "9" | "10" | "11" | "12" — help: Sets the order of the column for reordering. Number from 1-12. For more information check https://mantine.dev/core/grid
- grid_span (common, slider, default="1") — options: "1" | "2" | "3" | "4" | "5" | "6" | "7" | "8" | "9" | "10" | "11" | "12" | "auto" | "content" — help: Sets the span (width) of the column. Number from 1-12 or "auto"/"content". For more information check https://mantine.dev/core/grid
- shared_height (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Height of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- shared_width (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Width of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the GridColumn component

Allowed children: (any)
Allowed parents: grid

### group (mantine, renderTarget=both) — can_have_children

Mantine Group component for horizontal layouts

Fields:
- align (common, select, default=null) — options: "flex-start" | "center" | "flex-end" | "stretch" | "baseline" — help: Sets the align-items property. For more information check https://mantine.dev/core/group
- gap (common, slider, default="sm") — options: "0" | "xs" | "sm" | "md" | "lg" | "xl" — help: Sets the gap between group items. For more information check https://mantine.dev/core/group
- justify (common, select, default=null) — options: "flex-start" | "center" | "flex-end" | "space-between" | "space-around" | "space-evenly" — help: Sets the justify-content property. For more information check https://mantine.dev/core/group
- shared_height (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Height of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- shared_width (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Width of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Group component
- web_group_grow (web, checkbox, default="0") — help: If `grow` prop is set Group will take all available space. For more information check https://mantine.dev/core/group
- web_group_wrap (web, segment, default="0") — options: "0" | "1" — help: If `wrap` prop is set Group will wrap items to the next line when there is not enough space. For more information check https://mantine.dev…

Allowed children: (any)

### highlight (mantine, renderTarget=both)

Mantine Highlight component for text highlighting

Fields:
- color (common, color-picker, default="yellow") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the highlight color. For more information check https://mantine.dev/core/highlight
- highlight_highlight (content, text, default="highlight") — help: Sets the text to highlight within the content. This is translatable content that can be different in each language.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Highlight component
- text (content, textarea, default="Highlight some text in this content") — help: The main text content where highlighting will be applied. This is translatable content.

### html-tag (Wrapper, renderTarget=both) — can_have_children

Raw HTML tag component for custom flexible UI designs - allows rendering any HTML element with children

Fields:
- html_tag (common, select, default="div") — options: "div" | "span" | "p" | "h1" | "h2" | "h3" | "h4" | "h5" | "h6" | "section" | "article" | "aside" | "header" | "footer" | "nav" | "main" | "ul" | "ol" | "li" | "dl" | "dt" | "dd" | "blockquote" | "pre" | "code" | "em" | "strong" | "b" | "i" | "u" | "mark" | "small" | "sup" | "sub" | "cite" | "q" | "abbr" | "dfn" | "time" | "var" | "samp" | "kbd" | "address" | "del" | "ins" | "s" | "figure" | "figcaption" | "table" | "thead" | "tbody" | "tfoot" | "tr" | "th" | "td" | "caption" | "colgroup" | "col" | "fieldset" | "legend" | "label" | "button" | "output" | "meter" | "details" | "summary" | "dialog" | "canvas" | "svg" | "picture" | "img" | "a" — help: Select the HTML tag to render. This provides raw HTML flexibility for custom UI designs. — placeholder: "div"
- html_tag_content (content, code, default=null) — help: Translatable content to display inside the HTML tag. This field supports multiple languages. — placeholder: "Enter HTML content or text"

Allowed children: (any)

### image (Media, renderTarget=both)

allows to render an image on a page.

Fields:
- alt (content, text, default=null) — help: The alternative text to be shown if the image cannot be loaded.
- fallback_src (content, select-image, default=null) — help: Image shown if the main source fails to load (Mantine Image fallbackSrc).
- img_src (content, select-image, default=null) — help: The image source. If the image is an asset simply use the full name of the asset here.
- is_fluid (common, checkbox, default="1") — help: If enabled the image scales responsively.
- radius (common, slider, default="none") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the image. For more information check https://mantine.dev/core/image
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Image component
- title (content, markdown-inline, default=null) — help: The text to be shown when hovering over the image.
- web_height (web, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "fit-content" | "max-content" | "min-content" — help: Sets the height of the image. Either a custom value or falls back to section-${style.id}
- web_image_fit (web, select, default="contain") — options: "contain" | "cover" | "fill" | "none" | "scale-down" — help: Sets how the image should fit within its container. For more information check https://mantine.dev/core/image
- web_width (web, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "fit-content" | "max-content" | "min-content" — help: Sets the width of the image. Either a custom value or falls back to section-${style.id}

### indicator (mantine, renderTarget=both) — can_have_children

Mantine Indicator component for status indicators

Fields:
- color (common, color-picker, default="red") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the indicator. For more information check https://mantine.dev/core/indicator
- label (content, markdown-inline, default="") — help: Sets the label text displayed in the indicator. For more information check https://mantine.dev/core/indicator
- radius (common, slider, default="lg") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the indicator. For more information check https://mantine.dev/core/indicator
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Indicator component
- web_border (web, checkbox, default="0") — help: If set, adds a white border around the indicator. For more information check https://mantine.dev/core/indicator
- web_indicator_disabled (web, checkbox, default="0") — help: If `disabled` prop is set, indicator will be disabled. For more information check https://mantine.dev/core/indicator
- web_indicator_inline (web, checkbox, default="0") — help: If set, the indicator will use inline-block display instead of block. For more information check https://mantine.dev/core/indicator
- web_indicator_offset (web, select, default="0") — options: "0" | "2" | "4" | "6" | "8" | "10" | "12" — help: Sets the offset distance of the indicator from its position. Choose from preset values or enter a custom value (e.g., 5, 15, 20). For more… — placeholder: "0"
- web_indicator_position (web, select, default="top-end") — options: "top-start" | "top-center" | "top-end" | "middle-start" | "middle-center" | "middle-end" | "bottom-start" | "bottom-center" | "bottom-end" — help: Sets the position of the indicator relative to its children. For more information check https://mantine.dev/core/indicator
- web_indicator_processing (web, checkbox, default="0") — help: If `processing` prop is set, indicator will show processing animation. For more information check https://mantine.dev/core/indicator
- web_indicator_size (web, slider, default="10") — options: "6" | "7" | "8" | "9" | "10" | "11" | "12" | "13" | "14" | "15" | "16" | "17" | "18" | "19" | "20" | "21" | "22" | "23" | "24" | "25" | "26" | "27" | "28" | "29" | "30" | "31" | "32" | "33" | "34" | "35" | "36" | "37" | "38" | "39" | "40" — help: Sets the size of the indicator in pixels (6-40px). For more information check https://mantine.dev/core/indicator

Allowed children: (any)

### input (Input, renderTarget=both)

HTML input component for various input types (text, email, password, etc.). Renders as standard HTML input tag.

Fields:
- disabled (common, checkbox, default="0") — help: If set, the input field will be disabled
- is_required (common, checkbox, default="0") — help: If set, the input field will be required for form submission
- label (content, markdown-inline, default=null) — help: If this field is set, a this text will be rendered next to the input.
- locked_after_submit (common, checkbox, default="0") — help: If selected and if the field is used in a form that is not `is_log`, once the value is set, the field will not be able to be edited anymore.
- max (common, number, default=null) — help: Sets the maximum value (for number inputs) or maximum length (for text inputs)
- min (common, number, default=null) — help: Sets the minimum value (for number inputs) or minimum length (for text inputs)
- name (common, text, default=null) — help: Sets the name attribute for form submission
- placeholder (content, text, default=null) — help: Sets the placeholder text for the input field
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the input. For more information check https://mantine.dev/core/input
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the input. For more information check https://mantine.dev/core/input
- translatable (common, checkbox, default="0") — help: If enabled, this input field will support multi-language translations. When enabled, users can enter values for different languages using t…
- type_input (common, select, default="text") — options: "text" | "email" | "password" | "number" | "checkbox" | "color" | "date" | "time" | "tel" | "url" — help: Sets the input type (text, email, password, number, checkbox, color, date, time, tel, url)
- value (common, text, default=null) — help: Sets the initial value of the input field
- web_left_icon (web, select-icon, default=null) — help: Sets the left icon of the input. For more information check https://mantine.dev/core/input
- web_right_icon (web, select-icon, default=null) — help: Sets the right icon of the input. For more information check https://mantine.dev/core/input
- web_variant (web, select, default="default") — options: "filled" | "light" | "outline" | "subtle" | "default" | "transparent" | "white" — help: Sets the variant of the input. For more information check https://mantine.dev/core/input

### kbd (mantine, renderTarget=both)

Mantine Kbd component for keyboard key display

Fields:
- label (content, markdown-inline, default="") — help: Sets the label text displayed in the keyboard key. For more information check https://mantine.dev/core/kbd
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the keyboard key. For more information check https://mantine.dev/core/kbd
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Kbd component

### link (Link, renderTarget=both) — can_have_children

renders a standard link but allows to open the target in a new tab.

Fields:
- color (common, color-picker, default="") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Link color. Leave empty for the theme default link color.
- label (content, markdown-inline, default=null) — help: Specifies the clickable text. If left empty the URL as specified in the field `url` will be used.
- open_in_new_tab (common, checkbox, default=null) — help: If checked the link will be opened in a new tab. If unchecked the link will open in the current tab.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Link component
- url (common, text, default=null) — help: Links can refer to elements within SelfHelp Use the following syntax to achieve this: - link to back (browser functionality) `#back` - link…
- web_left_icon (web, select-icon, default=null) — help: Optional icon shown before the link label.
- web_link_underline (web, segment, default="hover") — options: "always" | "hover" | "never" — help: When the underline is shown (Mantine Anchor underline).
- web_right_icon (web, select-icon, default=null) — help: Optional icon shown after the link label (e.g. an external-link arrow).

Allowed children: (any)

### list (mantine, renderTarget=both)

Mantine List component for displaying ordered or unordered lists

Fields:
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the list. For more information check https://mantine.dev/core/list
- spacing (common, spacing, default="") — help: Sets the margin and padding of the List component
- web_list_center (web, checkbox, default="0") — help: If set, centers the list item content with the icon. For more information check https://mantine.dev/core/list
- web_list_icon (web, select-icon, default=null) — help: Sets the default icon for all list items. For more information check https://mantine.dev/core/list
- web_list_list_style_type (web, select, default="disc") — options: "disc" | "circle" | "square" | "decimal" | "decimal-leading-zero" | "lower-alpha" | "upper-alpha" | "lower-roman" | "upper-roman" | "none" — help: Sets custom bullet style for the list (e.g., "disc", "circle", "square", "decimal", "lower-alpha"). For more information check https://mant…
- web_list_spacing (web, select, default="md") — options: "xs" | "sm" | "md" | "lg" | "xl" — help: Sets the spacing between list items. For more information check https://mantine.dev/core/list
- web_list_type (web, segment, default="unordered") — options: "unordered" | "ordered" — help: Sets the type of the list. For more information check https://mantine.dev/core/list
- web_list_with_padding (web, checkbox, default="0") — help: If set, adds padding to nested lists for better hierarchy. For more information check https://mantine.dev/core/list

Allowed children: list-item

### list-item (mantine, renderTarget=both) — can_have_children

Mantine List.Item component for individual list items

Fields:
- list_item_content (content, markdown-inline, default=null) — help: The content text for this list item
- spacing (common, spacing, default="") — help: Sets the margin and padding of the ListItem component
- web_list_item_icon (web, select-icon, default=null) — help: Sets the icon for this list item, overrides the parent list icon. For more information check https://mantine.dev/core/list

Allowed children: (any)
Allowed parents: list

### login (Admin, renderTarget=both)

provides a small form where the user can enter his or her email and password to access the WebApp. It also includes a link to reset a password.

Fields:
- alert_fail (content, text, default=null) — help: This text is displayed in a danger-alert-box whenever the login fails.
- color (common, color-picker, default="dark") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Select the color for the submit button. For more information check https://mantine.dev/theming/colors/
- label_login (content, text, default=null) — help: The text on the login button.
- label_pw (content, text, default=null) — help: The placeholder in the password input field.
- label_pw_reset (content, text, default=null) — help: The name of the password reset link.
- label_register (content, text, default="Create account") — help: Label of the link on the login form that opens the registration page.
- label_user (content, text, default=null) — help: The placeholder in the email input field.
- login_title (content, text, default=null) — help: The text displayed in the login card header.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Login component
- subtitle (content, text, default="") — help: Optional subtitle shown under the title. Leave empty to hide.

### loop (Wrapper, renderTarget=both) — can_have_children

A style which takes an array object and loop the rows and load its children passing the values of the rows

Fields:
- loop (common, json, default=null) — help: JSON array where each entry is a row object passed to the child sections; reference a row key with {{key}}. Example: [{"title":"First","val…
- scope (common, text, default="") — help: If the variable `scope` is defined, it serves as a prefix for naming the variables

Allowed children: (any)

### missing (Admin, renderTarget=both)

Page-not-found surface (200, addressable).

Fields:
- button_label (content, text, default="") — help: Label for the primary action button (links back to home).
- color (common, color-picker, default="gray") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Mantine theme color.
- message (content, markdown, default="") — help: Supporting text shown below the title.
- radius (common, slider, default="md") — options: "none" | "sm" | "md" | "lg" | "full" — help: Mantine border radius.
- show_icon (common, checkbox, default="1") — help: Show the large status icon.
- title (content, markdown-inline, default="") — help: Main heading shown on the surface.
- variant (common, select, default="filled") — options: "filled" | "light" | "outline" | "subtle" | "default" | "transparent" | "white" — help: Mantine button variant.
- web_shadow (web, slider, default="") — options: "none" | "xs" | "sm" | "md" | "lg" | "xl" — help: Mantine shadow size. — placeholder: "none"

### no-access (Admin, renderTarget=both)

Access-denied page surface (403).

Fields:
- button_label (content, text, default="") — help: Label for the primary action button (links back to home).
- color (common, color-picker, default="red") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Mantine theme color.
- login_label (content, text, default="") — help: Label for the sign-in button (shown when "Show login" is on).
- message (content, markdown, default="") — help: Supporting text shown below the title.
- radius (common, slider, default="md") — options: "none" | "sm" | "md" | "lg" | "full" — help: Mantine border radius.
- show_icon (common, checkbox, default="1") — help: Show the large status icon.
- show_login (common, checkbox, default="0") — help: Show the sign-in button (used for the guest access-denied surface).
- title (content, markdown-inline, default="") — help: Main heading shown on the surface.
- variant (common, select, default="light") — options: "filled" | "light" | "outline" | "subtle" | "default" | "transparent" | "white" — help: Mantine button variant.
- web_shadow (web, slider, default="") — options: "none" | "xs" | "sm" | "md" | "lg" | "xl" — help: Mantine shadow size. — placeholder: "none"

### not-found (Admin, renderTarget=both)

Global 404 surface.

Fields:
- button_label (content, text, default="Back to home") — help: Label for the primary action button (links back to home).
- color (common, color-picker, default="gray") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Mantine theme color.
- login_label (content, text, default="Sign in") — help: Label for the sign-in button (shown when "Show login" is on).
- message (content, markdown, default="The page you are looking for does not exist or has been moved.") — help: Supporting text shown below the title.
- radius (common, slider, default="md") — options: "none" | "sm" | "md" | "lg" | "full" — help: Mantine border radius.
- show_icon (common, checkbox, default="1") — help: Show the large status icon.
- title (content, markdown-inline, default="") — help: Main heading shown on the surface.
- variant (common, select, default="light") — options: "filled" | "light" | "outline" | "subtle" | "default" | "transparent" | "white" — help: Mantine button variant.
- web_shadow (web, slider, default="") — options: "none" | "xs" | "sm" | "md" | "lg" | "xl" — help: Mantine shadow size. — placeholder: "none"

### notification (mantine, renderTarget=both)

Mantine Notification component for alerts and messages

Fields:
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the notification. For more information check https://mantine.dev/core/notification
- content (content, textarea, default=null) — help: Sets the main content/message of the notification. For more information check https://mantine.dev/core/notification
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the notification. For more information check https://mantine.dev/core/notification
- shared_icon (common, select-icon, default=null) — help: Sets the icon for the notification. If no icon is selected, a default icon matching the color will be used. For more information check http…
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Notification component
- title (content, markdown-inline, default=null) — help: Sets the title for the notification. For more information check https://mantine.dev/core/notification
- web_border (web, checkbox, default="0") — help: If `withBorder` prop is set, notification will have a border. For more information check https://mantine.dev/core/notification
- web_notification_loading (web, checkbox, default="0") — help: If `loading` prop is set, notification will show loading state. For more information check https://mantine.dev/core/notification
- with_close_button (common, checkbox, default="1") — help: If `withCloseButton` prop is set, notification will have a close button. For more information check https://mantine.dev/core/notification

### number-input (mantine, renderTarget=both)

Mantine NumberInput component for numeric input

Fields:
- description (content, textarea, default="") — help: Description text displayed below the input field
- disabled (common, checkbox, default="0") — help: If `disabled` prop is set NumberInput will be disabled. For more information check https://mantine.dev/core/number-input
- is_required (common, checkbox, default="0") — help: If set, the number input becomes required for form submission
- label (content, markdown-inline, default="") — help: Sets the label of the input field. For more information check https://mantine.dev/core/number-input
- name (common, text, default="") — help: Field name for form submission
- placeholder (content, text, default="Enter number") — help: Sets the placeholder text for the number input. For more information check https://mantine.dev/core/number-input
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the number input. For more information check https://mantine.dev/core/number-input
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the number input. For more information check https://mantine.dev/core/number-input
- spacing (common, spacing, default="") — help: Sets the margin and padding of the NumberInput component
- value (common, text, default="") — help: Default numeric value for the number input
- web_number_input_allow_negative (web, checkbox, default="1") — help: Allow negative values.
- web_number_input_clamp_behavior (web, segment, default="strict") — options: "strict" | "blur" — help: Sets the clamp behavior for the number input. For more information check https://mantine.dev/core/number-input
- web_number_input_decimal_scale (web, slider, default="2") — options: "0" | "1" | "2" | "3" | "4" | "5" — help: Sets the number of decimal places for the number input. For more information check https://mantine.dev/core/number-input
- web_number_input_hide_controls (web, checkbox, default="0") — help: Hide the up / down stepper buttons.
- web_number_input_prefix (web, text, default="") — help: Text shown before the number (e.g. $). Leave empty for none.
- web_number_input_suffix (web, text, default="") — help: Text shown after the number (e.g. kg, %). Leave empty for none.
- web_number_input_thousand_separator (web, checkbox, default="0") — help: Group thousands with a separator (e.g. 1,000).
- web_numeric_max (web, select, default=null) — options: "10" | "100" | "1000" | "10000" — help: Sets the maximum value for the number input. For more information check https://mantine.dev/core/number-input
- web_numeric_min (web, select, default=null) — options: "0" | "1" | "10" | "100" — help: Sets the minimum value for the number input. For more information check https://mantine.dev/core/number-input
- web_numeric_step (web, select, default="1") — options: "0.1" | "0.5" | "1" | "5" | "10" — help: Sets the step value for the number input. For more information check https://mantine.dev/core/number-input

### paper (mantine, renderTarget=both) — can_have_children

Mantine Paper component for elevated surfaces

Fields:
- border (common, checkbox, default="0") — help: Show a border around the surface (web + mobile).
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the paper. For more information check https://mantine.dev/core/paper
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Paper component
- title (content, markdown-inline, default="") — help: Optional heading rendered above the content. Leave empty for a plain surface.
- web_paper_shadow (web, slider, default="sm") — options: "none" | "xs" | "sm" | "md" | "lg" | "xl" — help: Sets the shadow of the paper. For more information check https://mantine.dev/core/paper

Allowed children: (any)

### profile (Admin, renderTarget=both)

User profile management component with account settings, password reset, and account deletion

Fields:
- profile_accordion_default_opened (common, select, default="user_info") — options: "user_info" | "username_change" | "password_reset" | "account_delete" — help: Which accordion sections should be opened by default — placeholder: "Select sections to open by default"
- profile_accordion_multiple (common, checkbox, default="1") — help: Allow multiple accordion sections to be open simultaneously
- profile_account_info_title (content, text, default="Account Information") — help: Title for the account information section — placeholder: "Account Information"
- profile_columns (common, select, default="2") — options: "1" | "2" | "3" | "4" — help: Number of columns for non-accordion layout — placeholder: "1"
- profile_communication_preferences_button (content, text, default="Update Preferences") — help: Label of the button that saves the communication preferences.
- profile_communication_preferences_description (content, textarea, default="<p>Choose which messages SelfHelp may send you. Account and security messages are always delivered.</p>") — help: Intro text shown under the communication-preferences heading. Account and security messages are always delivered regardless of these settin…
- profile_communication_preferences_error_general (content, text, default="Failed to update communication preferences. Please try again.") — help: Generic error message shown when saving the communication preferences fails.
- profile_communication_preferences_success (content, text, default="Communication preferences updated successfully!") — help: Success message shown after the communication preferences are saved.
- profile_communication_preferences_title (content, text, default="Communication Preferences") — help: Heading of the communication-preferences card on the profile page.
- profile_delete_alert_text (content, text, default="This action cannot be undone. All your data will be permanently deleted.") — help: Warning text in the delete account alert — placeholder: "This action cannot be undone. All your data will be permanently deleted."
- profile_delete_button (content, text, default="Delete Account") — help: Button text for account deletion — placeholder: "Delete Account"
- profile_delete_description (content, textarea, default="<p>Permanently delete your account and all associated data. This action cannot be undone.</p>") — help: Warning description for account deletion — placeholder: "<p>Permanently delete your account and all associated data. This action cannot…"
- profile_delete_error_email_mismatch (content, text, default="Email does not match your account email") — help: Error when email doesn't match — placeholder: "Email does not match your account email"
- profile_delete_error_email_required (content, text, default="Email confirmation is required") — help: Error when email field is empty — placeholder: "Email confirmation is required"
- profile_delete_error_general (content, text, default="Failed to delete account. Please try again.") — help: General account deletion error — placeholder: "Failed to delete account. Please try again."
- profile_delete_label_email (content, text, default="Confirm Email") — help: Label for email confirmation field — placeholder: "Confirm Email"
- profile_delete_modal_warning (content, textarea, default="<p>Deleting your account will permanently remove all your data, including profile information, preferences, and any content you have created.</p>") — help: Detailed warning text in the delete account modal — placeholder: "Deleting your account will permanently remove all your data, including profile…"
- profile_delete_placeholder_email (content, text, default="Enter your email to confirm") — help: Placeholder for email confirmation — placeholder: "Enter your email to confirm"
- profile_delete_success (content, text, default="Account deleted successfully.") — help: Success message after account deletion — placeholder: "Account deleted successfully."
- profile_delete_title (content, text, default="Delete Account") — help: Section title for account deletion — placeholder: "Delete Account"
- profile_gap (common, select, default="md") — options: "xs" | "sm" | "md" | "lg" | "xl" — help: Spacing between profile sections — placeholder: "md"
- profile_label_created (content, text, default="Account Created") — help: Label for account creation date — placeholder: "Account Created"
- profile_label_email (content, text, default="Email") — help: Label for displaying user email — placeholder: "Email"
- profile_label_last_login (content, text, default="Last Login") — help: Label for last login date — placeholder: "Last Login"
- profile_label_name (content, text, default="Full Name") — help: Label for displaying full name — placeholder: "Full Name"
- profile_label_timezone (content, text, default="Timezone") — help: Label for timezone selection — placeholder: "Timezone"
- profile_label_username (content, text, default="Username") — help: Label for displaying username — placeholder: "Username"
- profile_name_change_button (content, text, default="Update Display Name") — help: Button text for name change — placeholder: "Update Display Name"
- profile_name_change_description (content, textarea, default="<p>Update your display name. This will be visible to other users.</p>") — help: Description explaining name change — placeholder: "<p>Update your display name. This will be visible to other users.</p>"
- profile_name_change_error_general (content, text, default="Failed to update display name. Please try again.") — help: General name change error — placeholder: "Failed to update display name. Please try again."
- profile_name_change_error_invalid (content, text, default="Display name contains invalid characters") — help: Error for invalid name format — placeholder: "Display name contains invalid characters"
- profile_name_change_error_required (content, text, default="Display name is required") — help: Error when name field is empty — placeholder: "Display name is required"
- profile_name_change_label (content, text, default="New Display Name") — help: Label for name input field — placeholder: "New Display Name"
- profile_name_change_placeholder (content, text, default="Enter new display name") — help: Placeholder for name input — placeholder: "Enter new display name"
- profile_name_change_success (content, text, default="Display name updated successfully!") — help: Success message after name change — placeholder: "Display name updated successfully!"
- profile_name_change_title (content, text, default="Change Display Name") — help: Section title for name change — placeholder: "Change Display Name"
- profile_password_reset_button (content, text, default="Update Password") — help: Button text for password change — placeholder: "Update Password"
- profile_password_reset_description (content, textarea, default="<p>Set a new password for your account. Make sure it is strong and secure.</p>") — help: Description explaining password change — placeholder: "<p>Set a new password for your account. Make sure it is strong and secure.</p>"
- profile_password_reset_error_confirm_required (content, text, default="Password confirmation is required") — help: Error when confirmation is empty — placeholder: "Password confirmation is required"
- profile_password_reset_error_current_required (content, text, default="Current password is required") — help: Error when current password is empty — placeholder: "Current password is required"
- profile_password_reset_error_current_wrong (content, text, default="Current password is incorrect") — help: Error when current password is wrong — placeholder: "Current password is incorrect"
- profile_password_reset_error_general (content, text, default="Failed to update password. Please try again.") — help: General password change error — placeholder: "Failed to update password. Please try again."
- profile_password_reset_error_mismatch (content, text, default="New passwords do not match") — help: Error when passwords don't match — placeholder: "New passwords do not match"
- profile_password_reset_error_new_required (content, text, default="New password is required") — help: Error when new password is empty — placeholder: "New password is required"
- profile_password_reset_error_weak (content, text, default="Password is too weak. Please choose a stronger password.") — help: Error for weak password — placeholder: "Password is too weak. Please choose a stronger password."
- profile_password_reset_label_confirm (content, text, default="Confirm New Password") — help: Label for password confirmation field — placeholder: "Confirm New Password"
- profile_password_reset_label_current (content, text, default="Current Password") — help: Label for current password field — placeholder: "Current Password"
- profile_password_reset_label_new (content, text, default="New Password") — help: Label for new password field — placeholder: "New Password"
- profile_password_reset_placeholder_confirm (content, text, default="Confirm new password") — help: Placeholder for password confirmation — placeholder: "Confirm new password"
- profile_password_reset_placeholder_current (content, text, default="Enter current password") — help: Placeholder for current password — placeholder: "Enter current password"
- profile_password_reset_placeholder_new (content, text, default="Enter new password") — help: Placeholder for new password — placeholder: "Enter new password"
- profile_password_reset_success (content, text, default="Password updated successfully!") — help: Success message after password change — placeholder: "Password updated successfully!"
- profile_password_reset_title (content, text, default="Change Password") — help: Section title for password change — placeholder: "Change Password"
- profile_radius (common, slider, default="sm") — options: "xs" | "sm" | "md" | "lg" | "xl" — help: Border radius for profile cards — placeholder: "sm"
- profile_receive_emails_description (content, textarea, default="Allow scheduled (non-essential) emails from SelfHelp.") — help: Helper text under the "receive emails" toggle.
- profile_receive_emails_label (content, text, default="Receive emails") — help: Label of the "receive emails" toggle.
- profile_receive_notifications_description (content, textarea, default="Allow scheduled push notifications from SelfHelp.") — help: Helper text under the "receive notifications" toggle.
- profile_receive_notifications_label (content, text, default="Receive notifications") — help: Label of the "receive notifications" toggle.
- profile_shadow (common, slider, default="sm") — options: "none" | "xs" | "sm" | "md" | "lg" | "xl" — help: Shadow effect for profile cards — placeholder: "none"
- profile_timezone_change_button (content, text, default="Update Timezone") — help: Label for the update-timezone button.
- profile_timezone_change_description (content, textarea, default="<p>Select your preferred timezone. This will affect how dates and times are displayed.</p>") — help: Intro text shown above the timezone selector (HTML allowed).
- profile_timezone_change_error_general (content, text, default="Failed to update timezone. Please try again.") — help: Error message shown when the timezone update fails.
- profile_timezone_change_error_required (content, text, default="Timezone is required") — help: Validation message shown when no timezone is selected.
- profile_timezone_change_label (content, text, default="Timezone") — help: Label for the timezone select input.
- profile_timezone_change_placeholder (content, text, default="Select a timezone") — help: Placeholder for the timezone select input.
- profile_timezone_change_success (content, text, default="Timezone updated successfully!") — help: Success message shown after the timezone is updated.
- profile_timezone_change_title (content, text, default="Change Timezone") — help: Heading for the timezone-change section of the profile page.
- profile_title (content, text, default="My Profile") — help: Main title displayed at the top of the profile page — placeholder: "My Profile"
- profile_use_accordion (common, checkbox, default="0") — help: Wrap profile sections in accordion for collapsible interface
- profile_variant (common, select, default="default") — options: "default" | "filled" | "outline" | "light" | "subtle" — help: Visual style variant for the profile cards — placeholder: "default"
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Profile component

### progress (mantine, renderTarget=both)

Mantine Progress component for basic progress bars

Fields:
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the progress bar. For more information check https://mantine.dev/core/progress
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the progress bar. For more information check https://mantine.dev/core/progress
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the progress bar. Choose from preset sizes or enter a custom value. For more information check https://mantine.dev/core/pr…
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Progress component
- value (common, text, default="0") — help: Sets the progress value (0-100). For more information check https://mantine.dev/core/progress
- web_progress_animated (web, checkbox, default="0") — help: If set, animates the progress bar stripes. For more information check https://mantine.dev/core/progress
- web_progress_striped (web, checkbox, default="0") — help: If set, displays stripes on the progress bar. For more information check https://mantine.dev/core/progress
- web_progress_transition_duration (web, select, default="200") — options: "150" | "200" | "300" | "400" | "0" — help: Sets the transition duration in milliseconds. Choose from preset durations or enter a custom value. For more information check https://mant… — placeholder: "200"

### progress-root (mantine, renderTarget=both)

Mantine Progress.Root component for compound progress bars with multiple sections

Fields:
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Corner radius of the progress bar (web + mobile).
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the progress bar. Choose from preset sizes or enter a custom value. For more information check https://mantine.dev/core/pr…
- spacing (common, spacing, default="") — help: Sets the margin and padding of the ProgressRoot component
- web_progress_auto_contrast (web, checkbox, default="0") — help: If set, colors will be adjusted for better contrast. For more information check https://mantine.dev/core/progress

Allowed children: progress-section

### progress-section (mantine, renderTarget=both)

Mantine Progress.Section component for individual progress sections

Fields:
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of this progress section. For more information check https://mantine.dev/core/progress
- label (content, markdown-inline, default=null) — help: Sets the label text for this progress section. For more information check https://mantine.dev/core/progress
- spacing (common, spacing, default="") — help: Sets the margin and padding of the ProgressSection component
- tooltip_label (content, text, default=null) — help: Sets the tooltip text for this progress section. Leave empty to disable tooltip. For more information check https://mantine.dev/core/tooltip
- value (common, text, default="0") — help: Sets the value for this progress section (0-100). For more information check https://mantine.dev/core/progress
- web_progress_animated (web, checkbox, default="0") — help: If set, animates this progress section stripes. For more information check https://mantine.dev/core/progress
- web_progress_striped (web, checkbox, default="0") — help: If set, displays stripes on this progress section. For more information check https://mantine.dev/core/progress
- web_tooltip_position (web, select, default="top") — options: "top" | "bottom" | "left" | "right" | "top-start" | "top-end" | "bottom-start" | "bottom-end" | "left-start" | "left-end" | "right-start" | "right-end" — help: Sets the position of the tooltip. For more information check https://mantine.dev/core/tooltip
Allowed parents: progress-root

### radio (mantine, renderTarget=both) — can_have_children

Unified Radio component that can render as single radio or radio group based on options

Fields:
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the radio button or radio group. For more information check https://mantine.dev/core/radio
- description (content, textarea, default=null) — help: Sets the description for the radio button. For more information check https://mantine.dev/core/radio
- disabled (common, checkbox, default="0") — help: If `disabled` prop is set Radio will be disabled. For more information check https://mantine.dev/core/radio
- is_required (common, checkbox, default="0") — help: Makes the radio button or radio group required for form submission. For more information check https://mantine.dev/core/radio
- label (content, markdown-inline, default=null) — help: Sets the label for the radio button or radio group. For more information check https://mantine.dev/core/radio
- locked_after_submit (common, checkbox, default="0") — help: If selected and if the field is used in a form that is not `is_log`, once the value is set, the field will not be able to be edited anymore.
- name (common, text, default=null) — help: Sets the form field name for the radio button or radio group. For more information check https://mantine.dev/core/radio
- option_labels (content, json, default="{}") — help: Map each stable code to the translated label for this CMS language. Codes must match the option catalog. Example labels for one language: `…
- orientation (common, segment, default="vertical") — options: "horizontal" | "vertical" — help: Sets the orientation of the radio group (when options are provided). For more information check https://mantine.dev/core/radio
- radio_options (common, json, default="[{\"value\":\"option1\",\"text\":\"Option 1\",\"description\":\"First choice description\"},{\"value\":\"option2\",\"text\":\"Option 2\",\"description\":\"Second choice description\"},{\"value\":\"option3\",\"text\":\"Option 3\",\"description\":\"Third choice description\"}]") — help: Define the stable option codes stored in submitted data. Enter translated display labels in the grid for each CMS language. Example catalog… — placeholder: "Enter JSON array of radio options"
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the radio button or radio group. For more information check https://mantine.dev/core/radio
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Radio component
- tooltip_label (content, text, default=null) — help: Sets the tooltip text for the radio component. Leave empty to disable tooltip. For more information check https://mantine.dev/core/tooltip
- value (common, text, default=null) — help: Sets the initial selected value for the radio button or radio group. For more information check https://mantine.dev/core/radio
- web_radio_card (web, checkbox, default="0") — help: If set, renders radio options as card components instead of standard radio buttons. For more information check https://mantine.dev/core/rad…
- web_radio_label_position (web, select, default="right") — options: "right" | "left" — help: Sets the position of the label relative to the radio button. For more information check https://mantine.dev/core/radio
- web_radio_variant (web, select, default="default") — options: "default" | "outline" — help: Sets the visual variant of the radio component. For more information check https://mantine.dev/core/radio
- web_tooltip_position (web, select, default="top") — options: "top" | "bottom" | "left" | "right" | "top-start" | "top-end" | "bottom-start" | "bottom-end" | "left-start" | "left-end" | "right-start" | "right-end" — help: Sets the position of the tooltip. For more information check https://mantine.dev/core/tooltip
- web_use_input_wrapper (web, checkbox, default="1") — help: When enabled, uses Input.Wrapper for proper label and description handling. When disabled, renders label and description inline next to the…

Allowed children: (any)

### range-slider (mantine, renderTarget=both)

Mantine range-slider component for range selection

Fields:
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the range slider. For more information check https://mantine.dev/core/range-slider
- description (content, textarea, default="") — help: Sets the description text displayed below the range slider input field
- disabled (common, checkbox, default="0") — help: If `disabled` prop is set range-slider will be disabled. For more information check https://mantine.dev/core/range-slider
- label (content, markdown-inline, default="") — help: Sets the label text for the range slider input field
- mobile_range_slider_show_value (mobile, checkbox, default="1") — help: Show the current range value above the slider on mobile.
- name (common, text, default="") — help: Sets the name attribute for the range slider input field, used for form integration
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the range slider. For more information check https://mantine.dev/core/range-slider
- range_slider_marks_values (content, json, default="") — help: Translatable values for range slider marks in JSON format. Example: [{"value":25,"label":"Low"},{"value":50,"label":"Medium"},{"value":75,"…
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the range slider. For more information check https://mantine.dev/core/range-slider
- spacing (common, spacing, default="") — help: Sets the margin and padding of the RangeSlider component
- value (common, text, default="") — help: Sets the value attribute for the range slider input field, used for form integration. Example: [20, 40]
- web_numeric_max (web, select, default="100") — options: "10" | "100" | "1000" | "10000" — help: Sets the maximum value for the range slider. For more information check https://mantine.dev/core/range-slider
- web_numeric_min (web, select, default="0") — options: "0" | "1" | "10" | "100" — help: Sets the minimum value for the range slider. For more information check https://mantine.dev/core/range-slider
- web_numeric_step (web, select, default="1") — options: "0.1" | "0.5" | "1" | "5" | "10" — help: Sets the step value for the range slider. For more information check https://mantine.dev/core/range-slider
- web_range_slider_inverted (web, checkbox, default="0") — help: If enabled, inverts the range slider track and thumb colors. For more information check https://mantine.dev/core/range-slider
- web_range_slider_labels_always_on (web, checkbox, default="0") — help: If enabled, labels are always visible on the range slider. For more information check https://mantine.dev/core/range-slider
- web_range_slider_show_label (web, checkbox, default="1") — help: If enabled, shows label on hover for range slider. For more information check https://mantine.dev/core/range-slider

### rating (mantine, renderTarget=both)

Mantine Rating component for star ratings

Fields:
- color (common, color-picker, default="yellow") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the rating. For more information check https://mantine.dev/core/rating
- description (content, textarea, default=null) — help: Description text for the rating input field
- disabled (common, checkbox, default="0") — help: If set, the rating will be disabled and cannot be interacted with
- label (content, markdown-inline, default=null) — help: Label text for the rating input field
- name (common, text, default=null) — help: Name attribute for the rating input field (required for form submission)
- readonly (common, checkbox, default="0") — help: If set, the rating will be read-only and cannot be changed
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the rating. For more information check https://mantine.dev/core/rating
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Rating component
- value (common, text, default=null) — help: Initial value for the rating (number between 0 and count)
- web_rating_count (web, slider, default="5") — options: "3" | "4" | "5" | "6" | "7" | "8" | "9" | "10" — help: Sets the number of stars in the rating. For more information check https://mantine.dev/core/rating
- web_rating_empty_icon (web, select-icon, default=null) — help: Sets the icon for unselected rating items. For more information check https://mantine.dev/core/rating
- web_rating_fractions (web, slider, default="1") — options: "1" | "2" | "3" | "4" | "5" — help: Sets the fraction precision for the rating. For more information check https://mantine.dev/core/rating
- web_rating_full_icon (web, select-icon, default=null) — help: Sets the icon for selected rating items. For more information check https://mantine.dev/core/rating
- web_rating_highlight_selected_only (web, checkbox, default="0") — help: If enabled, only selected items will be highlighted, unselected items will be dimmed. For more information check https://mantine.dev/core/r…
- web_rating_use_smiles (web, checkbox, default="0") — help: If enabled, uses smiley face icons (sad to happy) instead of stars. When enabled, the count is automatically fixed to 5. For more informati…

### ref-container (Wrapper, renderTarget=both) — can_have_children

Structural container for reusable section blocks. Passes children through without adding any visual styling, layout, or presentation of its own. Use this style when a section must be referenced from multiple pages.

Allowed children: (any)

### register (Admin, renderTarget=both)

provides a small form to allow a user to register for the WebApp. In order to register a user must provide a valid email and activation code. Activation codes can be generated in the admin section of the WebApp. The list of available codes can be exported.

Fields:
- alert_fail (content, text, default=null) — help: This text is displayed in a danger-alert-box whenever the registration fails.
- alert_success (content, text, default=null) — help: Upon successful registration the registration form is replaced with a `jumbotron` which hold this text.
- anonymous_users_registration (content, textarea, default="Please describe the process to the user") — help: The text is shown for the user when they register an anonymous user. Please use the field to describe the process to the user.
- code_placeholder (content, text, default="Enter your code") — help: Placeholder shown inside the validation-code input on the registration form.
- color (common, color-picker, default="success") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Select the color for the submit button. For more information check https://mantine.dev/theming/colors/
- group (common, select-group, default="3") — help: Select the default group in which evey new user is assigned.
- label_code (content, text, default="Validation Code") — help: Label shown above the validation-code input on the registration form (code-required mode).
- label_go_home (content, text, default="Go Home") — help: Label of the button that returns to the home page after a successful registration.
- label_go_to_login (content, text, default="Go to Login") — help: Label of the button that opens the login page after a successful registration.
- label_pw (content, text, default=null) — help: The placeholder in the validation code input field.
- label_submit (content, text, default=null) — help: The text on the registration button.
- label_user (content, text, default=null) — help: The placeholder in the email input field.
- open_registration (common, checkbox, default="0") — help: If checked any user can register without a registration code. The code will be automatically generated upon registration
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Register component
- success (content, text, default=null) — help: Upon successful registration the registration form is replaced with a `jumbotron` which holds this text as a heading.
- title (content, markdown-inline, default=null) — help: The text displayed in the register card header.

### reset-password (intern, renderTarget=both)

Fields:
- alert_success (content, text, default=null) — help: The success message to be shown when an email address was successfully stored in the database (if enabled) and the automatic emails were se…
- color (common, color-picker, default=null) — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Select the color for the submit button. For more information check https://mantine.dev/theming/colors/
- label_pw_reset (content, text, default=null) — help: The label on the submit button.
- placeholder (content, text, default=null) — help: The placeholder in the email input field.
- reset_alert_success (content, text, default="Your password has been reset.") — help: Alert body shown after the password has been reset successfully.
- reset_error_invalid_token (content, text, default="This reset link is invalid or has expired. Please request a new one.") — help: Fallback message shown when the recovery token is invalid or has expired.
- reset_error_pw_mismatch (content, text, default="The two passwords do not match.") — help: Validation message shown when the two entered passwords do not match.
- reset_error_pw_short (content, text, default="Your new password must be at least 8 characters long.") — help: Validation message shown when the new password is shorter than the minimum length.
- reset_label_pw (content, text, default="New password") — help: Label shown above the new-password input on the set-password form.
- reset_label_pw_confirm (content, text, default="Confirm new password") — help: Label shown above the confirm-password input on the set-password form.
- reset_label_submit (content, text, default="Set new password") — help: Label of the button that submits the new password.
- reset_pw_confirm_placeholder (content, text, default="Repeat your new password") — help: Placeholder shown inside the confirm-password input on the set-password form.
- reset_pw_placeholder (content, text, default="Choose a new password") — help: Placeholder shown inside the new-password input on the set-password form.
- reset_redirect_text (content, text, default="Redirecting to sign in in {seconds}s...") — help: Shown after a successful password reset while redirecting to login. Use {seconds} as the countdown placeholder.
- reset_success_title (content, text, default="Password updated") — help: Alert title shown after the password has been reset successfully.
- reset_title (content, text, default="Set a new password") — help: Heading shown above the set-new-password form after the user opens a valid recovery link.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the ResetPassword component

### rich-text-editor (mantine, renderTarget=both)

Rich text editor component based on Tiptap with toolbar controls for formatting. It supports controlled input for form submission.

Fields:
- description (content, textarea, default=null) — help: Sets the description text displayed below the label. For more information check https://mantine.dev/x/tiptap
- disabled (common, checkbox, default="0") — help: If set, the rich text editor will be disabled.
- is_required (common, checkbox, default="0") — help: If set, the rich text editor will be required for form submission.
- label (content, markdown-inline, default=null) — help: Sets the label text displayed above the rich text editor. For more information check https://mantine.dev/x/tiptap
- name (common, text, default=null) — help: Sets the name attribute for form submission. For more information check https://mantine.dev/x/tiptap
- rich_text_editor_placeholder (content, text, default="Start writing...") — help: Sets the placeholder text shown when the editor is empty. For more information check https://tiptap.dev/docs/editor/extensions/functionalit…
- spacing (common, spacing, default="") — help: Sets the margin and padding of the RichTextEditor component
- translatable (common, checkbox, default="0") — help: If enabled, this rich text editor field will support multi-language translations. When enabled, users can enter values for different langua…
- value (common, text, default=null) — help: Sets the initial HTML content of the rich text editor. For more information check https://mantine.dev/x/tiptap
- web_rich_text_editor_bubble_menu (web, checkbox, default="0") — help: If set, enables a bubble menu that appears when text is selected for quick formatting. For more information check https://tiptap.dev/docs/e…
- web_rich_text_editor_task_list (web, checkbox, default="0") — help: If set, enables task list functionality with checkboxes. For more information check https://tiptap.dev/docs/editor/extensions/functionality…
- web_rich_text_editor_text_color (web, checkbox, default="0") — help: If set, enables text color controls in the toolbar. For more information check https://tiptap.dev/docs/editor/extensions/functionality/color
- web_rich_text_editor_variant (web, segment, default="default") — options: "default" | "subtle" — help: Sets the variant of the rich text editor.

### scroll-area (mantine, renderTarget=both) — can_have_children

Mantine scroll-area component for custom scrollbars

Fields:
- shared_height (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Height of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the ScrollArea component
- web_scroll_area_offset_scrollbars (web, checkbox, default="0") — help: If `offsetScrollbars` prop is set, scrollbars will be offset from the container edge. For more information check https://mantine.dev/core/s…
- web_scroll_area_scrollbar_size (web, select, default="8") — options: "6" | "8" | "10" | "12" | "16" — help: Sets the size of the scrollbar. For more information check https://mantine.dev/core/scroll-area
- web_scroll_area_scroll_hide_delay (web, select, default="1000") — options: "0" | "300" | "500" | "1000" | "1500" | "2000" | "3000" — help: Sets the delay in milliseconds before hiding scrollbars after scrolling stops. Only applies when scrollbar type is hover. For more informat… — placeholder: "1000"
- web_scroll_area_type (web, segment, default="hover") — options: "hover" | "always" | "never" — help: Sets when to show the scrollbar. For more information check https://mantine.dev/core/scroll-area

Allowed children: (any)

### segmented-control (mantine, renderTarget=both)

Mantine segmented-control component for segmented controls

Fields:
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the segmented control. For more information check https://mantine.dev/core/segmented-control
- description (content, textarea, default=null) — help: Sets the description text for the segmented control input wrapper. For more information check https://mantine.dev/core/segmented-control
- disabled (common, checkbox, default="0") — help: If `disabled` prop is set segmented-control will be disabled. For more information check https://mantine.dev/core/segmented-control
- is_required (common, checkbox, default="0") — help: If set, the segmented control will be required for form validation. For more information check https://mantine.dev/core/segmented-control
- label (content, markdown-inline, default=null) — help: Sets the label text for the segmented control input wrapper. For more information check https://mantine.dev/core/segmented-control
- name (common, text, default=null) — help: Sets the name for the segmented control input wrapper. For more information check https://mantine.dev/core/segmented-control
- option_labels (content, json, default="{}") — help: Map each stable code to the translated label for this CMS language. Codes must match the option catalog. Example labels for one language: `…
- orientation (common, segment, default="horizontal") — options: "horizontal" | "vertical" — help: Sets the orientation of the segmented control. For more information check https://mantine.dev/core/segmented-control
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the segmented control. For more information check https://mantine.dev/core/segmented-control
- readonly (common, checkbox, default="0") — help: If set, the segmented control will be readonly. For more information check https://mantine.dev/core/segmented-control
- segmented_control_data (common, json, default="[{\"value\":\"option1\",\"label\":\"Option 1\"},{\"value\":\"option2\",\"label\":\"Option 2\"},{\"value\":\"option3\",\"label\":\"Option 3\"}]") — help: Define the stable option codes stored in submitted data. Enter translated display labels in the grid for each CMS language. Example catalog… — placeholder: "Enter JSON array of segmented control options"
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the segmented control. For more information check https://mantine.dev/core/segmented-control
- spacing (common, spacing, default="") — help: Sets the margin and padding of the SegmentedControl component
- value (common, text, default=null) — help: Sets the default selected value for the segmented control. Either a custom value or falls back to section-${style.id}. For more information…
- web_segmented_control_item_border (web, checkbox, default="0") — help: If set, adds border around each segmented control item. For more information check https://mantine.dev/core/segmented-control

### select (Input, renderTarget=both)

HTML select component for dropdown selections. Supports single and multiple selections.

Fields:
- clearable (common, checkbox, default="0") — help: If `clearable` prop is set, user can clear selected value. For more information check https://mantine.dev/core/select
- disabled (common, checkbox, default="0") — help: If set, the select field will be disabled
- is_multiple (common, checkbox, default="0") — help: If set, allows multiple selections
- is_required (common, checkbox, default="0") — help: If set, the select field will be required for form submission
- label (content, markdown-inline, default=null) — help: If this field is set, a this text will be rendered next to the select.
- locked_after_submit (common, checkbox, default="0") — help: If selected and if the field is used in a form that is not `is_log`, once the value is set, the field will not be able to be edited anymore.
- max (common, number, default=null) — help: Sets the maximum number of selections allowed when multiple is enabled
- mobile_select_presentation (mobile, select, default="") — options: "bottom-sheet" | "dialog" | "popover" — help: How the option list opens on mobile. Leave empty for the default (bottom sheet).
- name (common, text, default=null) — help: Sets the name attribute for form submission
- options (common, json, default="[{\"value\":\"option1\",\"label\":\"Option 1\"}, {\"value\":\"option2\",\"label\":\"Option 2\"}]") — help: Define the stable option codes stored in submitted data. Enter translated display labels in the grid for each CMS language. Example catalog… — placeholder: "Enter JSON array of select options"
- option_labels (content, json, default="{}") — help: Map each stable code to the translated label for this CMS language. Codes must match the option catalog. Example labels for one language: `…
- placeholder (content, text, default="Select an option") — help: Sets the placeholder text for the select field
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the select. For more information check https://mantine.dev/core/select
- searchable (common, checkbox, default="0") — help: If `searchable` prop is set, user can filter options by typing. For more information check https://mantine.dev/core/select
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the select. For more information check https://mantine.dev/core/select
- value (common, text, default=null) — help: Sets the initial selected value of the select field

### simple-grid (mantine, renderTarget=both) — can_have_children

Mantine simple-grid component for responsive grid layouts

Fields:
- cols (common, slider, default="3") — options: "1" | "2" | "3" | "4" | "5" | "6" — help: Sets the number of columns in the grid (1-6). For more information check https://mantine.dev/core/simple-grid
- gap (common, slider, default="md") — options: "0" | "xs" | "sm" | "md" | "lg" | "xl" — help: Horizontal spacing between columns.
- shared_height (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Height of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- shared_width (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Width of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the SimpleGrid component
- vertical_spacing (common, slider, default=null) — options: "0" | "xs" | "sm" | "md" | "lg" | "xl" — help: Sets the vertical spacing between grid items. For more information check https://mantine.dev/core/simple-grid
- web_cols_lg (web, select, default=null) — options: "1" | "2" | "3" | "4" | "5" | "6" — help: Columns on large screens (web responsive). Leave empty to inherit.
- web_cols_md (web, select, default=null) — options: "1" | "2" | "3" | "4" | "5" | "6" — help: Columns on medium screens (web responsive). Leave empty to inherit.
- web_cols_sm (web, select, default=null) — options: "1" | "2" | "3" | "4" | "5" | "6" — help: Columns on small screens (web responsive). Leave empty to inherit.

Allowed children: (any)

### slider (mantine, renderTarget=both)

Mantine slider component for single value selection

Fields:
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the slider. For more information check https://mantine.dev/core/slider
- description (content, textarea, default="") — help: Sets the description text displayed below the slider input field
- disabled (common, checkbox, default="0") — help: If set, the slider will be disabled. For more information check https://mantine.dev/core/slider
- is_required (common, checkbox, default="0") — help: If enabled the form can only be submitted if the slider has a value
- label (content, markdown-inline, default="") — help: Sets the label text for the slider input field
- locked_after_submit (common, checkbox, default="0") — help: If selected and if the field is used in a form that is not `is_log`, once the value is set, the field will not be able to be edited anymore.
- mobile_slider_show_value (mobile, checkbox, default="1") — help: Show the current value bubble above the slider on mobile.
- name (common, text, default="") — help: Sets the name attribute for the slider input field, used for form integration
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the slider. For more information check https://mantine.dev/core/slider
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the slider. For more information check https://mantine.dev/core/slider
- slider_marks_values (content, json, default="") — help: Translatable values for slider marks in JSON format. Example: [{"value":25,"label":"Low"},{"value":50,"label":"Medium"},{"value":75,"label"…
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Slider component
- value (common, text, default="") — help: Sets the value attribute for the slider input field, used for form integration. Example: 50
- web_numeric_max (web, select, default="100") — options: "10" | "100" | "1000" | "10000" — help: Sets the maximum value for the slider. For more information check https://mantine.dev/core/slider
- web_numeric_min (web, select, default="0") — options: "0" | "1" | "10" | "100" — help: Sets the minimum value for the slider. For more information check https://mantine.dev/core/slider
- web_numeric_step (web, select, default="1") — options: "0.1" | "0.5" | "1" | "5" | "10" — help: Sets the step value for the slider. For more information check https://mantine.dev/core/slider
- web_slider_inverted (web, checkbox, default="0") — help: If enabled, inverts the slider track and thumb colors. For more information check https://mantine.dev/core/slider
- web_slider_labels_always_on (web, checkbox, default="0") — help: If enabled, labels are always visible on the slider. For more information check https://mantine.dev/core/slider
- web_slider_required (web, checkbox, default="0") — help: If enabled, the slider will be required for form submission. For more information check https://mantine.dev/core/slider
- web_slider_show_label (web, checkbox, default="1") — help: If enabled, shows label on hover for slider. For more information check https://mantine.dev/core/slider

### space (mantine, renderTarget=both)

Mantine Space component for adding spacing

Fields:
- orientation (common, segment, default="vertical") — options: "horizontal" | "vertical" — help: Direction the empty space is added (vertical or horizontal).
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the space. For more information check https://mantine.dev/core/space
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Space component

### spoiler (mantine, renderTarget=both) — can_have_children

Mantine Spoiler component for collapsible text

Fields:
- color (common, color-picker, default="") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Color of the show/hide control. Leave empty for the theme default.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Spoiler component
- spoiler_hide_label (content, text, default="Hide") — help: Sets the label for the hide button. For more information check https://mantine.dev/core/spoiler — placeholder: "Enter hide label"
- spoiler_show_label (content, text, default="Show more") — help: Sets the label for the show button. For more information check https://mantine.dev/core/spoiler — placeholder: "Enter show label"
- web_height (web, select, default="200") — options: "25%" | "50%" | "75%" | "100%" | "auto" | "fit-content" | "max-content" | "min-content" — help: Sets the maximum height before showing the spoiler. For more information check https://mantine.dev/core/spoiler

Allowed children: (any)

### stack (mantine, renderTarget=both) — can_have_children

Mantine Stack component for vertical layouts

Fields:
- align (common, select, default=null) — options: "flex-start" | "center" | "flex-end" | "stretch" | "baseline" — help: Sets the align-items property. For more information check https://mantine.dev/core/stack
- gap (common, slider, default="sm") — options: "0" | "xs" | "sm" | "md" | "lg" | "xl" — help: Sets the gap between stack items. For more information check https://mantine.dev/core/stack
- justify (common, select, default=null) — options: "flex-start" | "center" | "flex-end" | "space-between" | "space-around" | "space-evenly" — help: Sets the justify-content property. For more information check https://mantine.dev/core/stack
- shared_height (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Height of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- shared_width (common, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "200px" | "300px" | "400px" — help: Width of the element (e.g. 100%, 320px, auto). Applies on web and mobile.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Stack component

Allowed children: (any)

### switch (mantine, renderTarget=both)

Mantine Switch component for toggle switches

Fields:
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the switch. For more information check https://mantine.dev/core/switch
- description (content, textarea, default=null) — help: Sets the description for the switch. For more information check https://mantine.dev/core/switch
- disabled (common, checkbox, default="0") — help: If `disabled` prop is set Switch will be disabled. For more information check https://mantine.dev/core/switch
- is_required (common, checkbox, default="0") — help: If set, the switch will be marked as required for form validation. For more information check https://mantine.dev/core/switch
- label (content, markdown-inline, default=null) — help: Sets the label for the switch. For more information check https://mantine.dev/core/switch
- name (common, text, default=null) — help: Sets the name attribute for the switch input field, used for form integration. For more information check https://mantine.dev/core/switch
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius for the switch. For more information check https://mantine.dev/core/switch
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the switch. For more information check https://mantine.dev/core/switch
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Switch component
- switch_off_label (content, text, default="Off") — help: Sets the label when switch is off. For more information check https://mantine.dev/core/switch — placeholder: "Enter off label"
- switch_on_label (content, text, default="On") — help: Sets the label when switch is on. For more information check https://mantine.dev/core/switch — placeholder: "Enter on label"
- value (common, text, default=null) — help: Sets the current value of the switch for form integration. For more information check https://mantine.dev/core/switch
- web_label_position (web, segment, default="left") — options: "left" | "right" — help: Sets the position of the label relative to the switch. For more information check https://mantine.dev/core/switch
- web_switch_on_value (web, text, default="1") — help: Sets the value that represents the on/checked state of the switch. When the current value equals this value, the switch will be checked. Fo… — placeholder: "Enter value for on state (e.g., 1, true, yes)"
- web_switch_thumb_icon (web, select-icon, default="") — help: Optional icon shown inside the switch thumb.
- web_switch_with_thumb_indicator (web, checkbox, default="1") — help: Show a coloured dot inside the switch thumb.
- web_use_input_wrapper (web, checkbox, default="0") — help: When enabled, uses Input.Wrapper for proper label and description handling. When disabled, renders label and description inline next to the…

### tab (mantine, renderTarget=both) — can_have_children

Mantine Tabs.Tab component for individual tab items within a tabs component. Can contain child components for tab panel content.

Fields:
- label (content, markdown-inline, default=null) — help: Sets the label/content of the tab that will be displayed to users. For more information check https://mantine.dev/core/tabs
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Tab component
- web_height (web, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "fit-content" | "max-content" | "min-content" — help: Sets the height of the Tabs.Tab component. For more information check https://mantine.dev/core/tabs
- web_left_icon (web, select-icon, default=null) — help: Sets the left section (icon) of the tab. For more information check https://mantine.dev/core/tabs
- web_right_icon (web, select-icon, default=null) — help: Sets the right section (icon) of the tab. For more information check https://mantine.dev/core/tabs
- web_tab_disabled (web, checkbox, default="0") — help: If `disabled` prop is set, tab will be disabled. For more information check https://mantine.dev/core/tabs
- web_width (web, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "fit-content" | "max-content" | "min-content" — help: Sets the width of the Tabs.Tab component. For more information check https://mantine.dev/core/tabs

Allowed children: (any)
Allowed parents: tabs

### tabs (mantine, renderTarget=both)

Mantine Tabs component for switching between different views

Fields:
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the tabs. For more information check https://mantine.dev/core/tabs
- orientation (common, segment, default="horizontal") — options: "horizontal" | "vertical" — help: Sets the orientation of the tabs. For more information check https://mantine.dev/core/tabs
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the tabs. For more information check https://mantine.dev/core/tabs
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Tabs component
- web_height (web, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "fit-content" | "max-content" | "min-content" — help: Sets the height of the Tabs component. For more information check https://mantine.dev/core/tabs
- web_tabs_grow (web, checkbox, default="0") — help: Stretch the tabs to fill the available width.
- web_tabs_justify (web, select, default="") — options: "flex-start" | "center" | "flex-end" | "space-between" | "space-around" — help: Alignment of the tab list. Leave empty for the default (start).
- web_tabs_keep_mounted (web, checkbox, default="1") — help: Keep inactive tab panels mounted (turn off to unmount hidden panels).
- web_tabs_placement (web, segment, default="left") — options: "left" | "right" — help: Tab list side when the orientation is vertical.
- web_tabs_variant (web, select, default="default") — options: "default" | "outline" | "pills" — help: Sets the variant of the tabs. For more information check https://mantine.dev/core/tabs
- web_width (web, select, default=null) — options: "25%" | "50%" | "75%" | "100%" | "auto" | "fit-content" | "max-content" | "min-content" — help: Sets the width of the Tabs component. For more information check https://mantine.dev/core/tabs

Allowed children: tab

### text (mantine, renderTarget=both)

Mantine Text component for displaying text with various styling options

Fields:
- color (common, color-picker, default="") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the text. For more information check https://mantine.dev/core/text
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the font size of the text. For more information check https://mantine.dev/core/text
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Text component
- text (content, textarea, default=null) — help: The text content to display. For more information check https://mantine.dev/core/text
- text_align (common, segment, default="left") — options: "left" | "center" | "right" | "justify" — help: Sets the text alignment. For more information check https://mantine.dev/core/text
- web_text_font_style (web, segment, default="normal") — options: "normal" | "italic" — help: Sets the font style of the text. For more information check https://mantine.dev/core/text
- web_text_font_weight (web, select, default=null) — options: "100" | "200" | "300" | "400" | "500" | "600" | "700" | "800" | "900" — help: Sets the font weight of the text. Choose from preset weights or enter a custom value (100-900). For more information check https://mantine.… — placeholder: "400"
- web_text_gradient (web, json, default=null) — help: Sets the gradient configuration for gradient variant. Only used when variant is "gradient". Format: {"from": "blue", "to": "cyan", "deg": 9…
- web_text_inherit (web, checkbox, default="0") — help: If set, Text will inherit parent styles (font-size, font-family, line-height). For more information check https://mantine.dev/core/text
- web_text_line_clamp (web, select, default=null) — options: "2" | "3" | "4" | "5" — help: Limits the number of lines to display. Choose from preset values or enter a custom number. For more information check https://mantine.dev/c… — placeholder: "3"
- web_text_span (web, checkbox, default="0") — help: If set, Text will render as a span element instead of p. For more information check https://mantine.dev/core/text
- web_text_text_decoration (web, segment, default="none") — options: "none" | "underline" | "line-through" — help: Sets the text decoration of the text. For more information check https://mantine.dev/core/text
- web_text_text_transform (web, segment, default="none") — options: "none" | "uppercase" | "capitalize" | "lowercase" — help: Sets the text transform of the text. For more information check https://mantine.dev/core/text
- web_text_truncate (web, segment, default=null) — options: "none" | "end" | "start" — help: Truncates the text with ellipsis. For more information check https://mantine.dev/core/text
- web_text_variant (web, segment, default="default") — options: "default" | "gradient" — help: Sets the text variant. Use "gradient" for gradient text. For more information check https://mantine.dev/core/text

### text-input (mantine, renderTarget=both)

Mantine TextInput component for controlled text input with validation and sections

Fields:
- description (content, textarea, default=null) — help: Sets the description text displayed below the label. For more information check https://mantine.dev/core/text-input
- disabled (common, checkbox, default="0") — help: If set, the input field will be disabled. For more information check https://mantine.dev/core/text-input
- is_required (common, checkbox, default="0") — help: If set, the input field will be required for form submission. For more information check https://mantine.dev/core/text-input
- label (content, markdown-inline, default=null) — help: Sets the label text displayed above the input field. For more information check https://mantine.dev/core/text-input
- max_length (common, number, default="") — help: Maximum number of characters allowed (web + mobile). Leave empty for no limit.
- mobile_auto_capitalize (mobile, select, default="") — options: "none" | "sentences" | "words" | "characters" — help: Auto-capitalization behaviour on mobile.
- mobile_input_variant (mobile, segment, default="primary") — options: "primary" | "secondary" — help: Native field style on mobile: primary (bordered) or secondary (filled).
- mobile_keyboard_type (mobile, select, default="") — options: "default" | "email-address" | "numeric" | "phone-pad" | "url" — help: Native keyboard type shown on mobile. Leave empty for the default keyboard.
- mobile_secure_entry (mobile, checkbox, default="0") — help: Mask the entered text (password-style) on mobile.
- name (common, text, default=null) — help: Sets the name attribute for form submission. For more information check https://mantine.dev/core/text-input
- placeholder (content, text, default=null) — help: Sets the placeholder text for the input field. For more information check https://mantine.dev/core/text-input
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the input field. For more information check https://mantine.dev/core/text-input
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the input field. For more information check https://mantine.dev/core/text-input
- spacing (common, spacing, default="") — help: Sets the margin and padding of the TextInput component
- translatable (common, checkbox, default="0") — help: If enabled, this input field will support multi-language translations. When enabled, users can enter values for different languages using t…
- value (common, text, default=null) — help: Sets the initial value of the input field. For more information check https://mantine.dev/core/text-input
- web_left_icon (web, select-icon, default=null) — help: Sets the content for the left section (typically an icon). For more information check https://mantine.dev/core/text-input
- web_right_icon (web, select-icon, default=null) — help: Sets the content for the right section (typically an icon). For more information check https://mantine.dev/core/text-input
- web_text_input_variant (web, segment, default="default") — options: "default" | "filled" | "unstyled" — help: Sets the variant of the input field. For more information check https://mantine.dev/core/text-input

### textarea (Input, renderTarget=both)

Textarea component for multi-line text input with autosize and resize options. It supports Mantine styling.

Fields:
- autosize (common, checkbox, default="1") — help: If set, the textarea will automatically adjust its height based on content. For more information check https://mantine.dev/core/textarea
- description (content, textarea, default=null) — help: Sets the description text displayed below the label. For more information check https://mantine.dev/core/textarea
- disabled (common, checkbox, default="0") — help: If set, the textarea field will be disabled. For more information check https://mantine.dev/core/textarea
- is_required (common, checkbox, default="0") — help: If set, the textarea field will be required for form submission. For more information check https://mantine.dev/core/textarea
- label (content, markdown-inline, default=null) — help: Sets the label text displayed above the textarea field. For more information check https://mantine.dev/core/textarea
- locked_after_submit (common, checkbox, default="0") — help: If selected and if the field is used in a form that is not `is_log`, once the value is set, the field will not be able to be edited anymore.
- max_length (common, number, default="") — help: Maximum number of characters allowed (web + mobile). Leave empty for no limit.
- max_rows (common, select, default="8") — options: "5" | "8" | "10" | "15" | "20" — help: Sets the maximum number of rows when autosize is enabled. For more information check https://mantine.dev/core/textarea — placeholder: "8"
- min_rows (common, select, default="3") — options: "1" | "2" | "3" | "4" | "5" — help: Sets the minimum number of rows when autosize is enabled. For more information check https://mantine.dev/core/textarea — placeholder: "3"
- mobile_auto_capitalize (mobile, select, default="") — options: "none" | "sentences" | "words" | "characters" — help: Auto-capitalization behaviour on mobile.
- mobile_textarea_variant (mobile, segment, default="primary") — options: "primary" | "secondary" — help: Native field style on mobile: primary (bordered) or secondary (filled).
- name (common, text, default=null) — help: Sets the name attribute for form submission. For more information check https://mantine.dev/core/textarea
- placeholder (content, text, default=null) — help: Sets the placeholder text for the textarea field. For more information check https://mantine.dev/core/textarea
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the textarea field. For more information check https://mantine.dev/core/textarea
- rows (common, select, default="4") — options: "3" | "4" | "5" | "6" | "8" | "10" — help: Sets the number of visible text lines for the textarea control. For more information check https://mantine.dev/core/textarea
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the textarea field. For more information check https://mantine.dev/core/textarea
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Textarea component
- translatable (common, checkbox, default="0") — help: If enabled, this textarea field will support multi-language translations. When enabled, users can enter values for different languages usin…
- value (common, text, default=null) — help: Sets the initial value of the textarea field. For more information check https://mantine.dev/core/textarea
- web_left_icon (web, select-icon, default=null) — help: Sets the content for the left section (typically an icon). For more information check https://mantine.dev/core/textarea
- web_right_icon (web, select-icon, default=null) — help: Sets the content for the right section (typically an icon). For more information check https://mantine.dev/core/textarea
- web_textarea_resize (web, segment, default="none") — options: "none" | "vertical" | "both" — help: Sets the resize behavior of the textarea. For more information check https://mantine.dev/core/textarea
- web_textarea_variant (web, segment, default="default") — options: "default" | "filled" | "unstyled" — help: Sets the variant of the textarea field. For more information check https://mantine.dev/core/textarea
- web_variant (web, select, default="default") — options: "filled" | "light" | "outline" | "subtle" | "default" | "transparent" | "white" — help: Sets the variant of the textarea. For more information check https://mantine.dev/core/textarea

### theme-icon (mantine, renderTarget=both)

Mantine ThemeIcon component for themed icon containers

Fields:
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the theme icon. For more information check https://mantine.dev/core/theme-icon
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Sets the border radius of the theme icon. For more information check https://mantine.dev/core/theme-icon
- size (common, slider, default="sm") — options: "sm" | "md" | "lg" — help: Sets the size of the theme icon. For more information check https://mantine.dev/core/theme-icon
- spacing (common, spacing, default="") — help: Sets the margin and padding of the ThemeIcon component
- web_left_icon (web, select-icon, default=null) — help: Sets the icon for the theme icon. For more information check https://mantine.dev/core/theme-icon
- web_variant (web, select, default="filled") — options: "filled" | "light" | "outline" | "subtle" | "default" | "transparent" | "white" — help: Sets the variant of the theme icon. For more information check https://mantine.dev/core/theme-icon

### timeline (mantine, renderTarget=both)

Mantine Timeline component for chronological displays

Fields:
- color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the timeline. For more information check https://mantine.dev/core/timeline
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Timeline component
- web_timeline_active (web, select, default="0") — options: "-1" | "0" | "1" | "3" | "4" | "5" — help: Index of current active element, all elements before this index will be highlighted with color. For more information check https://mantine.…
- web_timeline_align (web, segment, default="left") — options: "left" | "right" — help: Defines line and bullets position relative to content, also sets text-align. For more information check https://mantine.dev/core/timeline
- web_timeline_bullet_size (web, select, default="24") — options: "12" | "16" | "20" | "24" | "32" — help: Sets the size of the timeline bullets. For more information check https://mantine.dev/core/timeline
- web_timeline_line_width (web, select, default="2") — options: "1" | "2" | "3" | "4" — help: Sets the width of the timeline line. For more information check https://mantine.dev/core/timeline

Allowed children: timeline-item

### timeline-item (mantine, renderTarget=both) — can_have_children

Mantine Timeline.Item component for individual timeline entries

Fields:
- color (common, color-picker, default="gray") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Sets the color of the timeline item. For more information check https://mantine.dev/core/timeline
- title (content, markdown-inline, default=null) — help: Sets the title for the timeline item. For more information check https://mantine.dev/core/timeline
- web_timeline_item_bullet (web, select-icon, default=null) — help: Sets the bullet icon for the timeline item. For more information check https://mantine.dev/core/timeline
- web_timeline_item_line_variant (web, select, default="solid") — options: "solid" | "dashed" | "dotted" — help: Sets the line variant for the timeline item. For more information check https://mantine.dev/core/timeline

Allowed children: (any)
Allowed parents: timeline

### title (mantine, renderTarget=both)

Mantine Title component for headings and titles

Fields:
- color (common, color-picker, default="") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Text colour of the title from the theme palette. Mapped to web and mobile.
- content (content, textarea, default=null) — help: The text content of the title. This field supports multiple languages.
- line_clamp (common, select, default=null) — options: "1" | "2" | "3" | "4" | "5" — help: Maximum number of lines before truncating with an ellipsis. Web uses lineClamp; mobile uses numberOfLines. — placeholder: "3"
- size (common, slider, default="lg") — options: "sm" | "md" | "lg" — help: Sets the size of the title. For more information check https://mantine.dev/core/title
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Title component
- title_order (common, segment, default="1") — options: "1" | "2" | "3" | "4" | "5" | "6" — help: Heading level (H1-H6). Sets the semantic heading + default size on web and the heading scale on mobile.
- web_title_text_wrap (web, segment, default="wrap") — options: "wrap" | "balance" | "nowrap" — help: Sets the text-wrap CSS property for the title. For more information check https://mantine.dev/core/title

### two-factor-auth (Admin, renderTarget=both)

Provides a form for two-factor authentication where users can enter their verification code.

Fields:
- alert_fail (content, text, default="Invalid verification code. Please try again.") — help: The alert text that appears when the user enters invalid code.
- label_code (content, text, default="Code") — help: Label for the 2FA code input.
- label_expiration_2fa (content, markdown-inline, default="Code expires in") — help: The text that appears before the timer showing how much time is left before the verification code expires.
- label_submit (content, text, default="Verify") — help: Label for the verify / submit button.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the TwoFactorAuth component
- text_md (content, markdown, default="Please enter the 6-digit code sent to your email") — help: The instruction text shown to users explaining what they need to do to complete the two-factor authentication process.
- title (content, markdown-inline, default="Two-Factor Authentication") — help: Heading for the two-factor authentication form.

### typography (mantine, renderTarget=both) — can_have_children

Mantine Typography component for consistent typography styles

Fields:
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Typography component

Allowed children: (any)

### validate (Admin, renderTarget=both) — can_have_children

User account validation form that accepts user ID and token from URL, validates and activates account. Can have children for additional form fields.

Fields:
- alert_fail (content, text, default="Validation failed. Please check your information and try again.") — help: Error message displayed when validation fails
- alert_success (content, text, default="Account validated successfully! Welcome to our platform.") — help: Success message displayed after successful validation
- anonymous_user_name_description (content, markdown, default="This name will be visible to other users") — help: Description text for anonymous user name field
- btn_cancel_color (common, color-picker, default="gray") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Color theme for the cancel button
- btn_cancel_url (common, select-page-keyword, default=null) — help: URL to navigate to when cancel button is clicked
- btn_save_color (common, color-picker, default="blue") — options: "gray" | "red" | "grape" | "violet" | "blue" | "cyan" | "green" | "lime" | "yellow" | "orange" — help: Color theme for the activate button
- buttons_order (common, segment, default="save-cancel") — options: "save-cancel" | "cancel-save" — help: Order of buttons (activate appears first)
- buttons_position (common, select, default="space-between") — options: "space-between" | "center" | "flex-end" | "flex-start" — help: Positioning of the buttons container — placeholder: "space-between"
- buttons_radius (common, slider, default="sm") — options: "none" | "xs" | "sm" | "md" | "lg" | "xl" — help: Border radius of the form buttons
- buttons_size (common, slider, default="sm") — options: "xs" | "sm" | "md" | "lg" | "xl" — help: Size of the form buttons
- buttons_variant (common, select, default="filled") — options: "filled" | "light" | "outline" | "transparent" | "white" | "subtle" | "gradient" — help: Visual style variant for the buttons — placeholder: "filled"
- error_heading (content, text, default="Account validation failed") — help: Bold heading inside the invalid-activation-link alert.
- error_text (content, textarea, default="This validation link is invalid or has expired. Please request a new validation email.") — help: Fallback message shown when the activation link cannot be validated.
- error_title (content, text, default="Invalid Validation Link") — help: Alert title shown when the activation link is invalid or has expired.
- label_activate (content, text, default="Activate Account") — help: Text for the account activation button
- label_cancel (content, text, default="Cancel") — help: Text for the cancel button
- label_name (content, text, default="Name") — help: Label for the name input field
- label_pw (content, text, default="Password") — help: Label for the password input field
- label_pw_confirm (content, text, default="Confirm Password") — help: Label for the password confirmation field
- label_save (content, text, default="Save") — help: Text for the save button (fallback) — placeholder: "Save"
- label_timezone (content, text, default="Timezone") — help: Label for the timezone selection field — placeholder: "Timezone"
- label_update (content, text, default="Update") — help: Text for the update button (fallback) — placeholder: "Update"
- loading_text (content, textarea, default="Please wait while we validate your account activation link...") — help: Body text shown while the account-activation link is being verified.
- loading_title (content, text, default="Validating Link") — help: Heading shown while the account-activation link is being verified.
- name (common, text, default="validate_form") — help: Sets the form name for identification and API calls
- name_description (content, markdown-inline, default=null) — help: Help text displayed below the name field
- name_placeholder (content, text, default="Enter your full name") — help: Placeholder text for the name input field
- pw_placeholder (content, text, default="Enter your password") — help: Placeholder text for the password field
- radius (common, slider, default="sm") — options: "none" | "sm" | "md" | "lg" | "full" — help: Border radius for the validation card
- redirect_at_end (common, select-page-keyword, default="login") — help: URL to redirect to after successful account validation
- redirect_text (content, text, default="Redirecting to login in {seconds}s...") — help: Shown after activation while redirecting to login. Use {seconds} as the countdown placeholder.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Validate component
- subtitle (content, text, default="Please complete your account setup to activate your account") — help: Subtitle text displayed below the title
- success_title (content, text, default="Success") — help: Alert title shown after the account is successfully activated.
- title (content, markdown-inline, default="Account Validation") — help: Main heading displayed at the top of the validation form
- web_border (web, checkbox, default="1") — help: Show border around the validation card
- web_card_padding (web, slider, default="lg") — options: "none" | "xs" | "sm" | "md" | "lg" | "xl" — help: Padding inside the validation card
- web_card_shadow (web, slider, default="sm") — options: "none" | "xs" | "sm" | "md" | "lg" | "xl" — help: Shadow effect for the validation card

Allowed children: (any)

### version (Admin, renderTarget=both)

Add information about the DB version and for the git version of Selfhelp

### video (Media, renderTarget=both)

allows to load and display a video on a page.

Fields:
- alt (content, text, default=null) — help: The alternative text to be displayed if the video cannot be loaded.
- has_controls (common, checkbox, default="1") — help: Show the native playback controls.
- is_fluid (common, checkbox, default="1")
- media_autoplay (common, checkbox, default="0") — help: Start playing automatically (browsers require muted for autoplay).
- media_loop (common, checkbox, default="0") — help: Restart the video automatically when it ends.
- media_muted (common, checkbox, default="0") — help: Start muted.
- poster_src (content, select-image, default=null) — help: Poster image shown before the video plays.
- spacing (common, spacing, default="") — help: Sets the margin and padding of the Video component
- video_src (common, select-video, default=null)

<!-- CATALOG:END -->
