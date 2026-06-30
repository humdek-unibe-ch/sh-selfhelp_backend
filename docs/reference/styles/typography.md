# Typography styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 typography styles (`@selfhelp/shared` `typography` category).
Last verified: 2026-06-29.
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
semantic heading level on both platforms; `color` tints the heading;
`line_clamp` truncates after N lines (web `lineClamp` / mobile
`numberOfLines`). `web_title_text_wrap` stays web-only.

**Distinctive fields.** `content` (heading text), `title_order` (1–6, common), `size`, `color`, `line_clamp`, `web_title_text_wrap` (wrap/balance/nowrap, web only).

**Children.** No.

---

## text

**Purpose.** Mantine `Text` — a styled paragraph or inline run of text.

**Administrators.** The workhorse for body copy. Set size, colour, weight, alignment, and decoration. Use the gradient variant for accent text. Text is translatable. The `text` field is a **full rich-text** field (`textarea`): press **Enter** for multiple paragraphs and use the toolbar for headings, bullet/numbered lists, links, alignment and inline bold/italic/underline — plus `{{ }}` interpolation. The block formatting renders on the web frontend; the mobile app currently shows the inline subset.

**Developers.** Renders `<Text>`. The `text` field is `textarea`, so it carries full block HTML (headings, lists, paragraphs, alignment, links). Web renders the real block structure via `renderRichBlock` (`sanitizeHtmlForBlock` — DOMPurify-cleaned, block tags **kept**) inside a `<Text component="div">` when the content has markup, so nested blocks stay valid HTML; a plain string passes straight through inline. Mobile still parses the inline subset with `parseInlineRich` + `<InlineText>` (block tags degrade to inline there). `web_text_span` renders inline (`<span>`) only for plain content; `web_text_inherit` inherits parent typography; gradient needs `web_text_gradient`.

**Distinctive fields.** `text` (the copy, `textarea` — full rich text: headings/lists/links/alignment + inline marks), `web_text_font_weight`, `web_text_font_style`, `web_text_text_decoration`, `web_text_text_transform`, `web_text_align`, `web_text_variant` (default/gradient), `web_text_gradient`, `web_text_truncate`, `web_text_line_clamp`, `web_text_inherit`, `web_text_span`, `web_size`, `color`.

**Children.** No.

---

## code

**Purpose.** Mantine `Code` — inline or block monospaced code.

**Administrators.** Show code snippets or technical values. Turn on `code_block` for a multi-line block; leave off for inline code. Pick a colour and corner radius. Content is translatable.

**Developers.** Renders `<Code block={…}>`. `code_block` (common) selects block
vs inline on both platforms; `radius` rounds the block corners per
platform.

**Distinctive fields.** `content` (the code), `code_block` (block vs inline, common), `color`, `radius`.

**Children.** No.

---

## highlight

**Purpose.** Mantine `Highlight` — text with one or more substrings highlighted.

**Administrators.** Draw attention to keywords inside a sentence. Put the full sentence in `text` and the word(s) to highlight in `highlight_highlight`. The shared `text` field is now `textarea`, but `highlight` needs plain text for substring matching, so any formatting (inline marks **and** block tags) is **stripped to plain text** on both platforms — keep highlight content to a single styled sentence; use the `text` style if you need formatting.

**Developers.** Renders `<Highlight highlight={…}>`. The `text` field is the shared `textarea` field (same one the `text` style uses); because Mantine `Highlight` matches a plain substring, the renderer runs `stripHtmlTags` (web) / `stripHtmlToText` (mobile) so literal tags never leak and the highlight match works regardless of the richer editor.

**Distinctive fields.** `text` (full text, shared `textarea` field — rendered as plain text here), `highlight_highlight` (substring(s) to highlight), `color`.

**Children.** No.

---

## blockquote

**Purpose.** Mantine `Blockquote` — a quotation with an optional citation and icon.

**Administrators.** Use for testimonials or quotes. Add the quote in `blockquote_content`, the source in `cite`, and optionally an icon. The quote body is a **full rich-text** field (`textarea`): press **Enter** for multiple paragraphs and use the toolbar for headings, lists, links, alignment and inline marks. The block formatting renders on the web; the mobile app shows the inline subset.

**Developers.** Renders `<Blockquote cite icon>`. The quote body is a dedicated `blockquote_content` (`textarea`) field — **not** the generic shared `content` — so authors can format inside the quote without affecting the `code` style (which keeps the plain `content`). `<Blockquote>` is itself a block element, so the web renderer emits the real block structure via `renderRichBlock` (`sanitizeHtmlForBlock`); mobile renders the inline subset via `parseInlineRich` + `<InlineText>`.

**Distinctive fields.** `blockquote_content` (quote, `textarea` — full rich text), `cite` (attribution), `web_left_icon`, `web_icon_size`, `color`.

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

**Administrators.** Hide long text behind "Show more". Set the collapsed height, the show/hide labels, and the control colour (`color`).

**Developers.** Renders `<Spoiler maxHeight showLabel hideLabel>`; `color` colours the show/hide control (`controlRef`/anchor) and resolves through the semantic mapper on mobile.

**Distinctive fields.** `web_height` (collapsed max-height), `spoiler_show_label`, `spoiler_hide_label`, `color` (show/hide control colour).

**Children.** Yes.

---

## Related references

- [_conventions.md](./_conventions.md) — common fields and Mantine prop conventions.
- [index.md](./index.md) — full style catalog.
- [cms-translation.md](../../developer/cms-translation.md) — how translatable text is stored per locale.
