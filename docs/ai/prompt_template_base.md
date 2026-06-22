# SH-SelfHelp — AI Section Generation Prompt (BASE)

> This is the **hand-maintained base** of the prompt template. The final
> user-facing prompt is rendered on demand by
> `PromptTemplateService::render()` from this file plus the live
> `StyleSchemaService::getSchema()` catalog (injected between the
> `CATALOG:BEGIN` and `CATALOG:END` HTML-comment markers at the very
> bottom of this file) and served by
> `GET /cms-api/v1/admin/ai/section-prompt-template`. Edit this file and
> the next call to the endpoint reflects the change — no build step is
> required. The optional snapshot
> `<ai_prompt_template_dir>/ai_section_generation_prompt.md` written by
> `bin/console app:prompt-template:build` is gitignored, never served,
> and exists only as a human inspection artefact.

---

## Your task

You generate **CMS content for SH-SelfHelp** as a single JSON **array of sections**.
The JSON you produce will be imported as-is via the admin UI's **Import Sections**
flow (`POST /cms-api/v1/admin/pages/{page_id}/sections/import`).

Replace the two placeholders below and return **only valid JSON** (no prose, no
Markdown fences):

- `<LOCALES>` — one or more **real** locales (never `all`), comma-separated.
  Examples: `en-GB` or `en-GB,de-CH`. These are the languages **translatable**
  fields (`locale=en-GB|de-CH|...` in the catalog) will be rendered in.
  Property fields are **separate** and always use the locale `all`
  regardless of what you pass here.
- `<DESCRIBE_WHAT_YOU_WANT>` — freeform English description of what to build
  (e.g. "a hero section with a title, subtitle, and a primary CTA, followed
  by a 3-column team grid with 6 members, then a contact form").

---

## Output contract (STRICT)

1. The top-level value MUST be a JSON **array** of section objects.
2. Every section object MUST include a `style_name` that exists in the style
   catalog below. `section_name` is **optional** — the backend auto-names
   sections from the style when omitted.
3. Omit any field that would equal the DB default. The importer
   restores defaults automatically. **Smaller is better.**
4. `fields` is an object keyed by the CMS field name. Each field is keyed by
   **locale**; each locale has `{ "content": "...", "meta": "..." }`. Omit
   `meta` when null/empty.
5. Locale keys follow the `languages` table. The system ships three locales:
   - `all` (id 1, "Independent") — **MUST** be the only locale used for
     non-translatable **property** fields (catalog tag `locale=all`). These
     are technical/configuration values.
   - `en-GB` (id 3) and `de-CH` (id 2) — real human languages used only for
     **translatable** fields (catalog tag `locale=en-GB|de-CH|...`).
   - Mixing is illegal: never put a property field under `en-GB`; never put
     a translatable field under `all`. Pre-validation rejects unknown locales
     but silently accepts the wrong locale for a field — the resulting page
     will render incorrectly.
6. `global_fields` holds `condition`, `data_config`, `css`, `css_mobile`,
   `debug`. Omit any key that's null/empty. `debug` only appears when `true`.
   Omit `global_fields` entirely when all keys would be omitted.
7. `children` is an array; **omit it when empty**.
8. Booleans are ALWAYS strings (`"0"` / `"1"`) when stored in a translation
   field. Real JSON booleans are only allowed inside `global_fields.debug`.
9. CSS uses **Tailwind utility classes**. Every visual element that can be
   themed MUST include `dark:` variants. Use **two complementary mechanisms**
   for mobile responsiveness — pick whichever fits the situation, not both
   for the same property:
   - **Tailwind responsive prefixes inside `global_fields.css`** —
     `px-4 sm:px-6 lg:px-8`, `text-2xl md:text-3xl`. This is the default.
   - **`global_fields.css_mobile`** — every token here is automatically
     prefixed with `max-md:` by the web renderer, so the classes only apply
     below the `md` breakpoint (typically <768px). Use this for clarity when
     the override is genuinely mobile-only and the desktop variant would be
     noisy. Native (react-expo) renderers will read this field directly.
   - Do **not** put a token under `css_mobile` that already starts with a
     viewport prefix (`sm:`, `md:`, `max-md:`, …). Strip the prefix first.
10. **Responsive layout grids use the v9 object syntax.** `simple-grid`'s
    `web_cols` and `grid-column`'s `web_grid_span` accept either:
    - a fixed number string (`"3"`) — same column count on every viewport, or
    - a **JSON object** keyed by Mantine breakpoints
      (`base`, `xs`, `sm`, `md`, `lg`, `xl`) stored as a stringified JSON in
      the `content` field. Examples:
      ```json
      "web_cols": {
        "all": { "content": "{\"base\":1,\"sm\":2,\"lg\":3}" }
      }
      "web_grid_span": {
        "all": { "content": "{\"base\":12,\"sm\":6,\"md\":4}" }
      }
      ```
    `web_breakpoints` (legacy) is honoured only when `web_cols` is
    omitted; new content should ignore it.
11. Image fields use `img_src`. For placeholder/illustrative images, use the
    canonical URL `/assets/image-holder.png` (relative path; the backend
    serves the placeholder).
12. **Enum options are pre-validated implicitly.** When a field advertises
    `options: "a" | "b" | "c"` in the catalog, the rendered page will only
    behave correctly if you write one of those exact `value` strings. Do not
    invent new values. Free-form fields (`text`, `textarea`, `markdown-inline`,
    `json`) have no options list — write what makes sense.
13. **Mantine spacing fields are JSON objects, NOT Tailwind strings.** Any
    field whose catalog type is `web_spacing_margin`,
    `web_spacing_padding`, or `web_spacing_margin_padding` expects a
    JSON-encoded object keyed by Mantine spacing keys with Mantine size
    tokens as values. Tailwind utility classes belong in
    `global_fields.css` / `global_fields.css_mobile`, never inside these
    fields. The keys are (margin first, then padding):
    - `mt` — margin-top
    - `mb` — margin-bottom
    - `ms` — margin-inline-start (left in LTR)
    - `me` — margin-inline-end   (right in LTR)
    - `pt` — padding-top
    - `pb` — padding-bottom
    - `ps` — padding-inline-start
    - `pe` — padding-inline-end

    Valid values: the Mantine size tokens `"none"`, `"xs"`, `"sm"`, `"md"`,
    `"lg"`, `"xl"`, or a numeric pixel string (e.g. `"0"`, `"8"`, `"24"`).
    `web_spacing_padding` only accepts the four `p*` keys;
    `web_spacing_margin` only accepts the four `m*` keys;
    `web_spacing_margin_padding` accepts all eight. Omit keys you do
    not set rather than writing empty strings.

    Correct shape (stringified JSON stored under the `all` locale — always
    a property field):

    ```json
    "web_spacing_margin_padding": {
      "all": {
        "content": "{\"mt\":\"md\",\"mb\":\"md\",\"pt\":\"lg\",\"pb\":\"lg\"}"
      }
    }
    ```

    WRONG — do NOT put Tailwind classes here (they will crash the frontend):

    ```json
    "web_spacing_margin_padding": {
      "all": { "content": "pt-10" }
    }
    "web_spacing_margin_padding": {
      "all": { "content": "mx-auto max-w-sm" }
    }
    ```

    Rule of thumb: if the value you want is a Mantine size token use the
    spacing field, otherwise omit it entirely and express the spacing with
    Tailwind classes inside `global_fields.css`.
14. Do NOT include `id`, `position`, `timestamp`, or any ID-like key — the
    backend assigns those.

15. **Field-name landmines (the recurring import-time 422s).** The catalog
    below is the source of truth, but these mistakes show up in nearly
    every freshly generated page. Fix them before emitting:

    - `group` uses **`web_group_wrap`** with `"0"` (no wrap) /
      `"1"` (wrap). It does **not** use `web_wrap` (that's only on
      `flex` and takes Mantine's keyword strings `"wrap"`/`"nowrap"`).
    - `simple-grid` exposes **`web_vertical_spacing`** for its
      `verticalSpacing` prop. There is **no** `web_spacing` field on
      `simple-grid`; horizontal gap defaults to `sm` and is overridden
      via the standard `web_spacing_margin_padding` field if needed.
    - `card` does **not** carry a `web_card_padding` field. Its inner
      padding is the fixed Mantine default; tune it with the shared
      `Spacing` field (`spacing` padding side), or use a
      `card-segment` child for full-bleed content.
    - Native form-record/log fields are `name`, `alert_success`,
      `btn_save_label`, etc. Always read the catalog entry for the
      `form-*` style — its required fields differ from generic styles.
    - Image fields are `img_src`/`alt`. Audio is `web_audio_*`,
      Video is `web_video_*`. Mixing these (e.g. `video_src` on
      `audio`) yields `unknown_field`.
    - Any field name your draft uses that the catalog below does not
      list — drop it. The importer's `unknown_field` and
      `invalid_field_for_style` errors are not recoverable; the entire
      import fails until every offending key is removed.

---

## JSON shape — minimal round-trip example

Single locale (`<LOCALES>` = `en-GB`), hero container with one title:

```json
[
  {
    "section_name": "hero",
    "style_name": "container",
    "fields": {
      "web_size": {
        "all": { "content": "md" }
      }
    },
    "global_fields": {
      "css": "py-16 px-4 bg-white dark:bg-gray-900"
    },
    "children": [
      {
        "style_name": "title",
        "fields": {
          "content": {
            "en-GB": { "content": "Welcome" }
          },
          "web_title_order": {
            "all": { "content": "1" }
          }
        }
      }
    ]
  }
]
```

Multi-locale (`<LOCALES>` = `en-GB,de-CH`):

```json
[
  {
    "style_name": "title",
    "fields": {
      "content": {
        "en-GB": { "content": "Welcome" },
        "de-CH": { "content": "Willkommen" }
      },
      "web_title_order": {
        "all": { "content": "1" }
      }
    }
  }
]
```

Notice how the translatable `content` field is replicated across every real
locale while the property `web_title_order` uses the `all` locale exactly
once. The style/field catalog below tells you which is which (`locale=all` for
property, `locale=en-GB|de-CH|...` for translatable).

**Responsive feature grid (the canonical mobile-friendly pattern):**

```json
[
  {
    "style_name": "container",
    "global_fields": {
      "css": "px-4 py-12 sm:px-6 sm:py-16 lg:py-20 max-w-7xl mx-auto"
    },
    "children": [
      {
        "style_name": "simple-grid",
        "fields": {
          "web_cols": {
            "all": { "content": "{\"base\":1,\"sm\":2,\"lg\":3}" }
          },
          "web_vertical_spacing": { "all": { "content": "md" } }
        },
        "children": [
          {
            "style_name": "card",
            "fields": {
              "web_border": { "all": { "content": "1" } },
              "web_radius": { "all": { "content": "lg" } }
            },
            "global_fields": {
              "css": "min-w-0 overflow-hidden bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-800"
            },
            "children": [
              {
                "style_name": "card-segment",
                "global_fields": { "css": "p-4 sm:p-6" },
                "children": [
                  {
                    "style_name": "title",
                    "fields": {
                      "content": {
                        "en-GB": { "content": "Fast" }
                      },
                      "web_title_order": { "all": { "content": "3" } }
                    },
                    "global_fields": {
                      "css": "text-lg font-semibold text-gray-900 dark:text-gray-50"
                    }
                  },
                  {
                    "style_name": "text",
                    "fields": {
                      "text": {
                        "en-GB": {
                          "content": "Pre-rendered pages reach the user in milliseconds."
                        }
                      }
                    },
                    "global_fields": {
                      "css": "mt-2 text-sm text-gray-600 dark:text-gray-400 break-words"
                    }
                  }
                ]
              }
            ]
          }
        ]
      }
    ]
  }
]
```

Notes on the snippet above:

- `web_cols` is a stringified JSON object for true responsive behaviour
  (1 col on mobile, 2 on tablet, 3 on desktop).
- Each card carries `min-w-0 overflow-hidden` so long text can break instead
  of pushing the grid sideways.
- Padding is responsive (`p-4 sm:p-6`) via `css` / `spacing`
  instead of a single fixed value.
- The text body has `break-words` so unbroken words/URLs do not overflow on
  narrow screens.

---

## Structural rules (HARD constraints — pre-validation will reject otherwise)

### Container-only styles

These styles may **only** appear as direct children of the listed parent.
Do not use them as top-level sections or under any other parent:

| Style            | Required parent   |
|------------------|-------------------|
| `accordion-item` | `accordion`       |
| `tab`            | `tabs`            |
| `card-segment`   | `card`            |
| `grid-column`    | `grid`            |
| `list-item`      | `list`            |
| `progress-section` | `progress-root` |
| `timeline-item`  | `timeline`        |

### Parent styles that require their slot children

When you use any of the following parents, their `children` array must be
composed of the matching slot style (you may still nest other styles inside
each slot):

| Parent          | Child slot style |
|-----------------|------------------|
| `accordion`     | `accordion-item` |
| `tabs`          | `tab`            |
| `card`          | `card-segment`   |
| `grid`          | `grid-column`    |
| `list`          | `list-item`      |
| `progress-root` | `progress-section` |
| `timeline`      | `timeline-item`  |

### HTML nesting rules (avoid hydration errors)

Several text-rendering styles resolve to a `<p>` under the hood
(`text`, `blockquote`, `highlight`). React will throw a hydration error
if a `<p>` contains another `<p>`, so follow these rules:

- Do **not** place `text`, `blockquote`, or `highlight` inside an
  `html-tag` whose `html_tag` is `"p"`. If you want a paragraph with
  inline text, put the text directly into the `html-tag`'s
  `html_tag_content` field instead of using child styles, OR pick an
  `html_tag` that is not a paragraph (`"div"`, `"section"`, etc.).
- Same rule applies when wrapping text in a `blockquote`: only put
  inline content there, never another block-level text style.
- `title` renders as `<h1>`…`<h6>` depending on `web_title_order`,
  so it is always safe to nest inside `<div>`-like containers.
- Never put another `button`, `a`, or form control inside a `button`.

### Form composition

- `form-log` (append-only submissions) and `form-record` (upsert a single
  record per user) are the canonical wrappers for data collection. Place
  every input control (`text-input`, `textarea`, `select`, `checkbox`,
  `radio`, `switch`, `chip`, `combobox`, `number-input`, `slider`,
  `range-slider`, `rating`, `color-input`, `color-picker`, `datepicker`,
  `file-input`, `rich-text-editor`) inside their `children`.
- Group logically-related fields inside a `fieldset` (itself a child of the
  form) for visual grouping.
- `text-input` and `select` render the control **without** a label. Always
  precede them with a separate `text` or `title` sibling that acts as a
  visual label, or rely on the control's `label` field when present.
- `textarea` ships with its own built-in label when
  `use_web_style` is `"1"` (the default).
- Wrap multi-input rows in a `simple-grid` with
  `web_cols="{\"base\":1,\"md\":2}"` so they collapse to one column on
  mobile. Plain `flex` rows with hard-coded `gap-6` will overflow.

### Reading the style catalog's relationship lines

Every style in the catalog below ends with:

- `Allowed children: (any)` — you may place any style inside (structural
  rules above still apply).
- `Allowed children: accordion-item` (etc.) — only the listed styles are
  valid children.
- `Allowed parents: card` — this style is a container-only slot and may
  only appear under the listed parent(s).

If these lines are missing, there are no relationship constraints.

---

## Tailwind design system (use these first, extend only when needed)

Every `css`/`css_mobile` string is just a space-separated Tailwind utility
list. Pair color classes with `dark:` variants on every visual element that
sits on a themed surface. Pick from the curated palette below before
inventing new combinations — it keeps pages consistent.

### Layout

- Section frame: `py-16 px-4` (desktop) with `py-10 px-4` in `css_mobile`.
- Content width: `max-w-7xl mx-auto` (marketing), `max-w-5xl mx-auto`
  (article), `max-w-3xl mx-auto` (prose/form), `max-w-xl mx-auto` (auth).
- Horizontal flow: `flex items-center justify-between gap-4`.
- Vertical rhythm: `space-y-6` (default), `space-y-10` (between major
  blocks). For Mantine `stack` use `web_gap`.
- Responsive grid of cards:
  `grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6`.

### Surfaces & borders

- Page background: `bg-white dark:bg-gray-950` or
  `bg-gray-50 dark:bg-gray-950`.
- Card surface:
  `bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800
   rounded-xl shadow-sm hover:shadow-lg transition-shadow`.
- Subtle panel: `bg-gray-50 dark:bg-gray-900 rounded-lg p-6`.
- Radii: `rounded-md` (inputs), `rounded-lg` (cards), `rounded-2xl` (hero),
  `rounded-full` (avatars, chips).

### Typography

- Display title: `text-4xl md:text-5xl font-bold tracking-tight
   text-gray-900 dark:text-gray-50`.
- Section title: `text-2xl md:text-3xl font-semibold text-gray-900
   dark:text-gray-100`.
- Card title: `text-lg font-semibold text-gray-900 dark:text-gray-100`.
- Body: `text-base text-gray-700 dark:text-gray-300 leading-relaxed`.
- Caption / meta: `text-sm text-gray-500 dark:text-gray-400`.
- Gradient accent (sparingly):
  `bg-gradient-to-r from-blue-600 to-violet-600 dark:from-blue-400
   dark:to-violet-400 bg-clip-text text-transparent`.

### Color pairs (always both sides)

- Neutral: `text-gray-900 dark:text-gray-100`,
  `text-gray-600 dark:text-gray-400`.
- Primary action: `bg-blue-600 hover:bg-blue-700 text-white
   dark:bg-blue-500 dark:hover:bg-blue-400`.
- Success: `text-emerald-700 dark:text-emerald-400`.
- Warning: `text-amber-700 dark:text-amber-400`.
- Danger: `text-rose-700 dark:text-rose-400`.
- Muted accent strip: `bg-indigo-50 dark:bg-indigo-950/40
   text-indigo-700 dark:text-indigo-300`.

### Motion & interaction

- `transition-colors duration-200`,
  `transition-transform duration-300 hover:-translate-y-0.5`,
  `hover:shadow-lg`, `focus-visible:ring-2 focus-visible:ring-blue-500`.
- Keep animations subtle — avoid `animate-bounce`/`animate-pulse` for
  everyday content.

### Dark-mode rules of thumb

- Use `dark:` with **every** background, text, border, and shadow-on-tint
  class you write. A style that reads well in light mode but vanishes in
  dark mode will fail design review.
- Prefer `bg-gray-900`/`bg-gray-950` for dark surfaces over pure black.
- Mute saturated colors in dark mode by one shade (e.g. `text-blue-700` →
  `dark:text-blue-400`).

### Mobile overrides (`global_fields.css_mobile`)

The web renderer auto-prefixes every token in `css_mobile` with `max-md:`,
so they apply only below the `md` breakpoint (<768px). Use it when the
mobile classes would clutter the desktop string, or when the override
truly only makes sense on mobile (different layout direction, hidden
content, smaller image, etc.).

For one-off responsive tweaks, **prefer responsive prefixes inside `css`**
(`py-16 sm:py-12`, `text-3xl md:text-5xl`). Reach for `css_mobile` when:

- The mobile rules are several tokens long and would dominate the `css`
  string visually (`flex-col items-stretch gap-3 p-4` vs. `flex-row items-center gap-6 p-8`).
- A native (react-expo) renderer needs an unambiguous mobile-only field.

**Never** put already-prefixed tokens in `css_mobile`
(`md:hidden`, `sm:px-6` …) — the renderer strips known viewport prefixes
to avoid generating broken selectors.

**Use only the curated mobile-safe allow-list** in `css_mobile`. The
native renderer (Uniwind on react-expo) cannot compile arbitrary
Tailwind, so `css_mobile` is filtered through
`@selfhelp/shared/cms-classes/allow-list` at render time. Anything outside
the allow-list is dropped on native with a dev warning, even though it
would still work on web. Stick to:

- Spacing: `m{,t,b,l,r,s,e,x,y}-{0..12,xs,sm,md,lg,xl,auto}`,
  `p{,t,b,l,r,s,e,x,y}-{0..12,xs,sm,md,lg,xl}`, `gap-{0..12,xs..xl}`.
- Sizing: `w-{full,auto,fit,1/2,1/3,2/3,1/4,3/4}`, `h-{auto,full,fit}`,
  `min-w-0`, `min-h-{0,full,fit,screen}`,
  `max-w-{xs..xl,full,fit,screen,none}`.
- Typography: `text-{xs..xl,left,center,right,justify,<color>-<0..9>}`,
  `font-{thin..black}`, `leading-{tight..loose}`,
  `tracking-{tighter..widest}`.
- Background / border: `bg-{transparent,white,black,<color>-<0..9>}`,
  `border-{0,1,2,4,<color>-<0..9>}`, `rounded-{none,xs..xl,full}`.
- Flex / grid: `flex-{row,col,row-reverse,col-reverse,wrap,nowrap,1,auto,none}`,
  `items-{start,center,end,baseline,stretch}`,
  `justify-{start,center,end,between,around,evenly}`,
  `col-span-1..12`, `row-span-1..12`.
- Atomic literals: `flex`, `inline-flex`, `block`, `inline-block`,
  `inline`, `hidden`, `absolute`, `relative`, `sticky`, `fixed`,
  `overflow-hidden`, `overflow-auto`, `rounded`, `border`, `shadow`,
  `shadow-{sm,md,lg,xl}`, `italic`, `underline`, `truncate`,
  `whitespace-nowrap`, `pointer-events-none`, `select-none`.

Hover / focus / cursor / responsive prefixes belong in `css`, not in
`css_mobile` — the native renderer drops them either way.

Examples:

```json
"global_fields": {
  "css": "flex flex-row items-center gap-6 px-8 py-12",
  "css_mobile": "flex-col items-stretch gap-3 px-4 py-8"
}
```

---

## Mobile-first guardrails (apply on every page)

The web renderer is consumed inside cards, drawers and narrow viewports.
Skip these and the page breaks the moment you open it on a phone.

1. **Container width**: top-level `container`/`box` should be a single
   column on mobile. Use `web_cols="{\"base\":1,\"sm\":2,\"lg\":3}"`
   on `simple-grid`, NEVER fixed `web_cols="3"` for content cards.
2. **Min-width zero**: any flex/grid child that contains long text must
   include `min-w-0` (and often `flex-1`) in its `css` so the parent can
   shrink instead of overflowing horizontally.
3. **Word breaking**: paragraphs and titles inside narrow cards need
   `break-words` (or `overflow-wrap-anywhere` for code-like content).
   Card surfaces should also include `overflow-hidden`.
4. **Responsive padding**: prefer `p-4 sm:p-6 lg:p-8` in `css` (or the
   shared `Spacing` control) over a single fixed padding. Mobile users do
   not need 24-32px of dead space on every side of a card.
5. **Responsive display text**: hero/title classes always need a mobile
   step (`text-3xl sm:text-4xl lg:text-5xl`).
6. **No horizontal scroll**: never set fixed pixel widths on body content
   (`w-[1200px]`, `min-w-[400px]`). If a media element absolutely needs a
   ratio, wrap it in `aspect-ratio` and let the parent govern width.
7. **Tap targets**: interactive elements (`button`, `action-icon`,
   `link`, `chip`) need a minimum height of 36-44px. Use `web_size="md"`
   or larger on mobile-critical actions; do not shrink them to `xs` to
   save space.
8. **Carousel slides**: set `web_carousel_slide_size="100%"` on
   mobile (or by default and override to a percentage on larger screens
   via `css`); the default `slideSize` of `100` (px) creates a horizontal
   scroll trap on phones.
9. **Tabs with many items**: when a `tabs` parent has more than 3 tabs,
   add `overflow-x-auto` and `scrollbar-thin` to its `css` so users can
   swipe through them on narrow viewports.
10. **Timeline alignment**: `timeline` looks best with
    `web_timeline_align="left"` on mobile. Avoid `right` alignment
    unless your design requires it.

---

## Cross-platform readiness (web + mobile-web + native react-expo)

Pages are imported once and may be rendered by multiple frontends:

| Surface         | Renderer                            | Tailwind?      | Mantine props? |
|-----------------|-------------------------------------|----------------|----------------|
| Desktop web     | `sh-selfhelp_frontend` (Next.js)    | yes            | yes (Mantine v9) |
| Mobile web      | `sh-selfhelp_frontend`              | yes            | yes              |
| Native (mobile) | react-expo app (planned)            | **NO**         | yes (mapped)     |

Because the same JSON has to render in all three, follow these rules:

- **Carry meaning in Mantine props first, Tailwind second.** Color, size,
  variant, radius, spacing, alignment, layout direction and gap should be
  expressed via `web_color`, `web_size`, `web_variant`,
  `web_radius`, `web_gap`, `web_align`, `web_justify`,
  `web_direction`, `web_cols`, `web_grid_span`. These map 1:1
  to the native renderer; Tailwind classes do not.
- **Use Tailwind for visual polish, not for structure.** Gradients,
  shadows, hover effects, dark-mode tints — fine. Building a flex layout
  out of `flex flex-col items-center gap-4` instead of using `stack` is
  not — the native renderer will just see an empty `box`.
- **Prefer semantic styles over assemblies.** `card`, `alert`,
  `notification`, `badge`, `button`, `accordion`, `tabs`, `timeline`,
  `list` already encode their semantics for every renderer. Re-creating
  them with `box` + `html-tag` works on web but renders as plain
  containers on native.
- **Avoid `html-tag` for content.** It exists for legacy templates and
  emits raw HTML. The native renderer will skip unknown tags. If you
  need a paragraph use `text`; for emphasis use `highlight`; for code
  use `code`; for arbitrary structure prefer Mantine layout primitives.
- **`css_mobile` is portable.** The web renderer auto-prefixes its tokens
  for the browser; the native renderer can read the raw field and apply
  its own platform-specific adjustments.
- **Image URLs must be HTTPS or relative.** Native renderers cannot fetch
  `localhost`/`http://` resources. For demo content use `/assets/image-holder.png`.

---

## Recipe hints (pick one as a starting point)

- **Hero**: `container` → `stack` → [`title` (gradient display, responsive
  font size in `css`), `text` (body), `group` with two `button`s].
  Container `css`: `px-4 py-12 sm:px-6 sm:py-16 lg:py-20 max-w-7xl mx-auto`.
- **Feature grid (responsive)**: `container` → `simple-grid` with
  `web_cols="{\"base\":1,\"sm\":2,\"lg\":3}"` → three `card` entries
  with `min-w-0 break-words` in `css`. Each card holds a `card-segment`
  with a `title`, `text`, and a trailing `button`.
- **Form**: `form-record` (or `form-log`) → `fieldset` → input controls in
  a `stack`. Follow with the form's built-in submit; do not add an extra
  button.
- **FAQ**: `accordion` → several `accordion-item`s, each with `text`
  children. `web_accordion_variant="separated"` reads well on mobile.
- **Marketing list**: `list` with `web_list_list_style_type="disc"`
  and `list-item` children each holding `web_list_item_content`.
- **Side-by-side that collapses on mobile**: `grid` with
  `web_cols="12"` and two `grid-column` children whose
  `web_grid_span` is `"{\"base\":12,\"md\":8}"` and
  `"{\"base\":12,\"md\":4}"` respectively.

---

## Dark / light theme compatibility

- Use a neutral light base (`bg-white`, `text-gray-900`) and pair each
  color-bearing class with a `dark:` variant (see the palette above).
- For images that rely on contrast (logos, icons) prefer SVG; otherwise
  pick images that read on both themes.

---

## Language policy (replacing `<LOCALES>`)

- Replace `<LOCALES>` with the real-language locales you want translations
  for — never include `all` here.
- Translatable fields (catalog tag `translatable, locale=en-GB|de-CH|...`)
  must have exactly one entry per chosen locale.
- Property fields (catalog tag `property, locale=all`) must always use the
  literal locale key `"all"`, regardless of how many real locales you listed.
- Locales must be valid DB entries (see `languages.locale`). The three
  currently registered values are `all`, `en-GB`, `de-CH`. Unknown locales
  are rejected with HTTP 422 at import time.

---

## Validation you can anticipate

The importer pre-validates the whole tree before writing anything. It returns
HTTP **422** with a list of `{path, type, detail}` errors if any of these are
violated:

- `unknown_style` — `style_name` isn't registered.
- `unknown_field` — field name isn't in the `fields` table.
- `invalid_field_for_style` — field isn't part of that style's schema.
- `unknown_locale` — locale isn't registered in `languages.locale`.
- `missing_style` / `missing_content` — required keys missing.

If you receive one of these, fix the offending node(s) and try again.

---

## Style & field catalog (auto-generated)

Everything below this line is regenerated by
`bin/console app:prompt-template:build` from the live DB schema
(`GET /cms-api/v1/admin/styles/schema`). Do not edit by hand.

<!-- CATALOG:BEGIN -->

<!-- CATALOG:END -->
