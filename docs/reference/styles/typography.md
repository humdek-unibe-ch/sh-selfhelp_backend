# Typography styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 typography styles (`@selfhelp/shared` `typography` category).
Last verified: 2026-06-19.
Source of truth: `src/types/styles/typography.ts`, `src/registry/styles.registry.ts`, the `admin/styles/schema` endpoint, and `src/app/components/frontend/styles/` renderers.

Typography styles render text — headings, paragraphs, quotes, code, and rich
prose. Read [`_conventions.md`](./_conventions.md) first; common fields are not
repeated here.

**Translatable content.** The text these styles display (`content`, `text`,
`label`, `cite`, …) is **translatable** (`display = 1`): enter a value per
language and the visitor sees their locale's version.

---

## title

**Purpose.** Mantine `Title` — a semantic heading (`h1`–`h6`).

**Administrators.** Use for page and section headings. Set the heading level with `title_order` (1 = biggest), pick a size and a theme colour, and optionally clamp to N lines. The text is translatable.

**Developers.** Renders `<Title order={…}>`. `title_order` (common) is the
semantic heading level on both platforms; `shared_color` tints the heading;
`shared_line_clamp` truncates after N lines (web `lineClamp` / mobile
`numberOfLines`). `web_title_text_wrap` stays web-only.

**Distinctive fields.** `content` (heading text), `title_order` (1–6, common), `shared_size`, `shared_color`, `shared_line_clamp`, `web_title_text_wrap` (wrap/balance/nowrap, web only).

**Children.** No.

---

## text

**Purpose.** Mantine `Text` — a styled paragraph or inline run of text.

**Administrators.** The workhorse for body copy. Set size, colour, weight, alignment, and decoration. Use the gradient variant for accent text. Text is translatable.

**Developers.** Renders `<Text>`. `web_text_span` renders it inline (`<span>`); `web_text_inherit` inherits parent typography; gradient needs `web_text_gradient`.

**Distinctive fields.** `text` (the copy), `web_text_font_weight`, `web_text_font_style`, `web_text_text_decoration`, `web_text_text_transform`, `web_text_align`, `web_text_variant` (default/gradient), `web_text_gradient`, `web_text_truncate`, `web_text_line_clamp`, `web_text_inherit`, `web_text_span`, `web_size`, `shared_color`.

**Children.** No.

---

## code

**Purpose.** Mantine `Code` — inline or block monospaced code.

**Administrators.** Show code snippets or technical values. Turn on `code_block` for a multi-line block; leave off for inline code. Pick a colour and corner radius. Content is translatable.

**Developers.** Renders `<Code block={…}>`. `code_block` (common) selects block
vs inline on both platforms; `shared_radius` rounds the block corners per
platform.

**Distinctive fields.** `content` (the code), `code_block` (block vs inline, common), `shared_color`, `shared_radius`.

**Children.** No.

---

## highlight

**Purpose.** Mantine `Highlight` — text with one or more substrings highlighted.

**Administrators.** Draw attention to keywords inside a sentence. Put the full sentence in `text` and the word(s) to highlight in `highlight_highlight`.

**Developers.** Renders `<Highlight highlight={…}>`.

**Distinctive fields.** `text` (full text), `highlight_highlight` (substring(s) to highlight), `shared_color`.

**Children.** No.

---

## blockquote

**Purpose.** Mantine `Blockquote` — a quotation with an optional citation and icon.

**Administrators.** Use for testimonials or quotes. Add the quote in `content`, the source in `cite`, and optionally an icon.

**Developers.** Renders `<Blockquote cite icon>`.

**Distinctive fields.** `content` (quote), `cite` (attribution), `web_left_icon`, `web_icon_size`, `shared_color`.

**Children.** No.

---

## html-tag

**Purpose.** A generic HTML tag wrapper on web (maps to a `View` on mobile). The escape hatch when no semantic style fits.

**Administrators.** Advanced: choose the raw HTML tag (e.g. `section`, `article`, `span`) and optional inline content. Prefer the dedicated styles where possible.

**Developers.** Renders `<{html_tag}>` with `html_tag_content` inside; can also contain child sections.

**Distinctive fields.** `html_tag` (tag name), `html_tag_content` (inline content).

**Children.** Yes.

---

## kbd

**Purpose.** Mantine `Kbd` — renders a keyboard key (e.g. `Ctrl`, `⌘`).

**Administrators.** Use in instructions to show keystrokes. Put the key in `label`.

**Developers.** Renders `<Kbd>{label}</Kbd>`.

**Distinctive fields.** `label` (key text), `web_size`.

**Children.** No.

---

## typography

**Purpose.** Mantine `Typography` wrapper — applies consistent prose styling to any rich HTML it contains.

**Administrators.** Wrap a block of mixed rich content (headings, lists, links generated elsewhere) so it all gets harmonised typography.

**Developers.** Renders `<Typography>` and styles its descendant HTML.

**Distinctive fields.** None beyond the common/spacing fields.

**Children.** Yes.

---

## fieldset

**Purpose.** Mantine `Fieldset` — a labelled border grouping related form controls.

**Administrators.** Group related inputs under a heading (legend). Place input sections inside. Can be disabled to grey out the whole group.

**Developers.** Renders `<Fieldset legend disabled>`; `disabled` cascades to nested inputs.

**Distinctive fields.** `legend` (group title), `label`, `web_fieldset_variant`, `web_radius`, `disabled`.

**Children.** Yes.

---

## spoiler

**Purpose.** Mantine `Spoiler` — collapses long content behind a show/hide toggle.

**Administrators.** Hide long text behind "Show more". Set the collapsed height and the show/hide labels.

**Developers.** Renders `<Spoiler maxHeight showLabel hideLabel>`.

**Distinctive fields.** `web_height` (collapsed max-height), `spoiler_show_label`, `spoiler_hide_label`.

**Children.** Yes.

---

## Related references

- [_conventions.md](./_conventions.md) — common fields and Mantine prop conventions.
- [index.md](./index.md) — full style catalog.
- [cms-translation.md](../../developer/cms-translation.md) — how translatable text is stored per locale.
