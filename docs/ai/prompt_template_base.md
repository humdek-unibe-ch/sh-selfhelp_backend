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
   themed MUST include `dark:` variants. Mobile overrides go into
   `global_fields.css_mobile`.
10. Image fields use `img_src`. For placeholder/illustrative images, use the
    canonical URL `/assets/image-holder.png` (relative path; the backend
    serves the placeholder).
11. **Enum options are pre-validated implicitly.** When a field advertises
    `options: "a" | "b" | "c"` in the catalog, the rendered page will only
    behave correctly if you write one of those exact `value` strings. Do not
    invent new values. Free-form fields (`text`, `textarea`, `markdown-inline`,
    `json`) have no options list — write what makes sense.
12. **Mantine spacing fields are JSON objects, NOT Tailwind strings.** Any
    field whose catalog type is `mantine_spacing_margin`,
    `mantine_spacing_padding`, or `mantine_spacing_margin_padding` expects a
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
    `mantine_spacing_padding` only accepts the four `p*` keys;
    `mantine_spacing_margin` only accepts the four `m*` keys;
    `mantine_spacing_margin_padding` accepts all eight. Omit keys you do
    not set rather than writing empty strings.

    Correct shape (stringified JSON stored under the `all` locale — always
    a property field):

    ```json
    "mantine_spacing_margin_padding": {
      "all": {
        "content": "{\"mt\":\"md\",\"mb\":\"md\",\"pt\":\"lg\",\"pb\":\"lg\"}"
      }
    }
    ```

    WRONG — do NOT put Tailwind classes here (they will crash the frontend):

    ```json
    "mantine_spacing_margin_padding": {
      "all": { "content": "pt-10" }
    }
    "mantine_spacing_margin_padding": {
      "all": { "content": "mx-auto max-w-sm" }
    }
    ```

    Rule of thumb: if the value you want is a Mantine size token use the
    spacing field, otherwise omit it entirely and express the spacing with
    Tailwind classes inside `global_fields.css`.
13. Do NOT include `id`, `position`, `timestamp`, or any ID-like key — the
    backend assigns those.

---

## JSON shape — minimal round-trip example

Single locale (`<LOCALES>` = `en-GB`), hero container with one title:

```json
[
  {
    "section_name": "hero",
    "style_name": "container",
    "fields": {
      "mantine_size": {
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
          "mantine_title_order": {
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
      "mantine_title_order": {
        "all": { "content": "1" }
      }
    }
  }
]
```

Notice how the translatable `content` field is replicated across every real
locale while the property `mantine_title_order` uses the `all` locale exactly
once. The style/field catalog below tells you which is which (`locale=all` for
property, `locale=en-GB|de-CH|...` for translatable).

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
- `title` renders as `<h1>`…`<h6>` depending on `mantine_title_order`,
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
  `use_mantine_style` is `"1"` (the default).

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
  blocks). For Mantine `stack` use `mantine_gap`.
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

Use `css_mobile` only when a class needs to be different on small screens,
not to repeat what Tailwind responsive prefixes already solve. Good uses:

- Tighter padding: `py-10 px-4` in `css_mobile` vs. `py-16 px-6` in `css`.
- Smaller display text: `text-3xl` in `css_mobile` vs.
  `text-4xl md:text-5xl` in `css`.
- Stack instead of flex-row: `flex-col items-start` in `css_mobile`.

---

## Recipe hints (pick one as a starting point)

- **Hero**: `container` → `stack` → [`title` (gradient display), `text`
  (body), `group` with two `button`s].
- **Feature grid**: `container` → `simple-grid` with `mantine_cols="3"` →
  three `card` entries, each with one `card-segment` holding a `title`,
  `text`, and a trailing `button`.
- **Form**: `form-record` (or `form-log`) → `fieldset` → input controls in
  a `stack`. Follow with a submit-labelled `button` or rely on the form's
  built-in submit.
- **FAQ**: `accordion` → several `accordion-item`s, each with `text`
  children.
- **Marketing list**: `list` with type `unordered` → `list-item` children
  each holding a short `text`.

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
