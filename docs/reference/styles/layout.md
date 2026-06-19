# Layout styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 layout styles (`@selfhelp/shared` `layout` category).
Last verified: 2026-06-19.
Source of truth: `src/types/styles/layout.ts`, `src/registry/styles.registry.ts`, the `admin/styles/schema` endpoint, and `src/app/components/frontend/styles/` renderers.

Layout styles arrange other sections on the page. They are almost all
[Mantine](https://mantine.dev) layout primitives. Read
[`_conventions.md`](./_conventions.md) first — the common fields (`css`,
`css_mobile`, `condition`, spacing, `use_web_style`) are documented there and
are not repeated below.

**When to reach for which layout (administrators):** use `stack` for a vertical
column, `group` for a horizontal row, `grid`/`simple-grid` for multi-column
grids, `container` to constrain page width, `card`/`paper` for raised surfaces,
and `box`/`flex` when you need full control.

---

## container

**Purpose.** Mantine `Container` — centres content and caps its width at a responsive maximum.

**Administrators.** Wrap a page's main content in a container so it stays readable on wide screens. Pick a `size` for the max width, or turn on `fluid` to span the full width.

**Developers.** Renders `<Container>`; children render inside. Honors horizontal/vertical padding via `web_px` / `web_py`.

**Distinctive fields.** `web_size` (max-width preset), `web_fluid` (full-width toggle), `web_px` / `web_py` (inner horizontal/vertical padding).

**Children.** Yes.

---

## box

**Purpose.** Mantine `Box` — the generic, unopinionated wrapper element.

**Administrators.** A neutral container when no other layout fits. Style it with `css` / spacing. Use it to group sections without imposing flex/grid behaviour.

**Developers.** Renders `<Box>` (or a plain element when `use_web_style = 0`). `content` is an optional inline text/HTML payload for simple cases.

**Distinctive fields.** `content` (optional inline content).

**Children.** Yes.

---

## flex

**Purpose.** Mantine `Flex` — a full CSS flexbox container.

**Administrators.** Use when you need precise control over how children line up: direction (row/column), spacing, alignment, justification, and wrapping.

**Developers.** Renders `<Flex>` mapping each `web_*` prop directly to the flexbox CSS property.

**Distinctive fields.** `web_gap`, `web_justify` (justify-content), `web_align` (align-items), `web_direction` (row/column), `web_wrap`, `web_width`, `web_height`.

**Children.** Yes.

---

## group

**Purpose.** Mantine `Group` — lays children out **horizontally** with even spacing.

**Administrators.** The quickest way to put items in a row (e.g. buttons side by side). Set the gap, and optionally let items grow to fill the row or wrap to the next line.

**Developers.** Renders `<Group>`. `web_group_grow` makes children share width equally; `web_group_wrap` toggles wrapping.

**Distinctive fields.** `web_gap`, `web_justify`, `web_align`, `web_group_wrap` (`0`/`1`), `web_group_grow` (`0`/`1`), `web_width`, `web_height`.

**Children.** Yes.

---

## stack

**Purpose.** Mantine `Stack` — lays children out **vertically** with even spacing.

**Administrators.** The default choice for stacking sections in a column. Set the gap and how items align/justify.

**Developers.** Renders `<Stack>`.

**Distinctive fields.** `web_gap`, `web_justify`, `web_align`, `web_width`, `web_height`.

**Children.** Yes.

---

## simple-grid

**Purpose.** Mantine `SimpleGrid` — an equal-width responsive grid.

**Administrators.** Use for galleries or card rows where every column is the same width. Set the column count and spacing; use breakpoints to change columns on small screens.

**Developers.** Renders `<SimpleGrid>`. `web_breakpoints` is a JSON string of responsive column overrides.

**Distinctive fields.** `web_cols` (column count), `web_spacing` (horizontal), `web_vertical_spacing`, `web_breakpoints` (responsive JSON), `web_width`, `web_height`.

**Children.** Yes.

---

## grid

**Purpose.** Mantine `Grid` — a 12-column grid whose columns (`grid-column`) can span and offset independently.

**Administrators.** Use for asymmetric layouts (e.g. a wide main column + a narrow sidebar). Add `grid-column` children and set each one's span.

**Developers.** Renders `<Grid>`; direct children should be `grid-column` sections.

**Distinctive fields.** `web_cols`, `web_gap`, `web_justify`, `web_align`, `web_grid_overflow`, `web_width`, `web_height`.

**Children.** Yes (typically `grid-column`).

---

## grid-column

**Purpose.** Mantine `Grid.Col` — one column inside a `grid`.

**Administrators.** Place inside a `grid`. Set how many of the 12 columns it spans, and optionally an offset or order.

**Developers.** Renders `<Grid.Col>`. `web_grid_span` accepts a number, `auto`, or `content`.

**Distinctive fields.** `web_grid_span` (width), `web_grid_offset`, `web_grid_order`, `web_grid_grow`, `web_width`, `web_height`.

**Children.** Yes.

---

## space

**Purpose.** Mantine `Space` — adds empty horizontal or vertical space.

**Administrators.** Drop between sections to add a gap without using margins. Pick a size and direction.

**Developers.** Renders `<Space>`. Leaf node.

**Distinctive fields.** `web_size`, `web_space_direction` (horizontal/vertical).

**Children.** No.

---

## divider

**Purpose.** Mantine `Divider` — a horizontal or vertical separator line, optionally with a label.

**Administrators.** Visually split content into groups. Add an optional centred label ("OR", section names) and choose the line style.

**Developers.** Renders `<Divider>`.

**Distinctive fields.** `web_divider_variant` (solid/dashed/dotted), `web_size` (thickness), `divider_label`, `web_divider_label_position`, `web_orientation`, `shared_color`.

**Children.** No.

---

## paper

**Purpose.** Mantine `Paper` — a surface with optional shadow, radius, and border.

**Administrators.** Lift content onto a "sheet" with a drop shadow and rounded corners. Good for panels and call-outs.

**Developers.** Renders `<Paper>`.

**Distinctive fields.** `web_paper_shadow`, `web_radius`, `web_px` / `web_py` (padding), `web_border` (`0`/`1`).

**Children.** Yes.

---

## center

**Purpose.** Mantine `Center` — centres its single child both horizontally and vertically.

**Administrators.** Use to centre a logo, spinner, or message. Optionally constrain min/max width and height.

**Developers.** Renders `<Center>`. `web_center_inline` switches to inline centering.

**Distinctive fields.** `web_center_inline`, `web_width` / `web_height`, `web_miw` / `web_mih` (min), `web_maw` / `web_mah` (max).

**Children.** Yes.

---

## scroll-area

**Purpose.** Mantine `ScrollArea` — a scrollable region with styled scrollbars.

**Administrators.** Wrap tall content (long lists, tables) in a fixed-height box that scrolls internally. Set the height and when scrollbars appear.

**Developers.** Renders `<ScrollArea>`.

**Distinctive fields.** `web_scroll_area_type` (hover/always/never/scroll), `web_scroll_area_scrollbar_size`, `web_scroll_area_offset_scrollbars`, `web_scroll_area_scroll_hide_delay`, `web_height`, `web_width`.

**Children.** Yes.

---

## card

**Purpose.** Mantine `Card` — a bordered, rounded content card.

**Administrators.** Group related content into a card. For a quick good-looking
result you can fill in the optional **Title** and **Image** fields — when set,
the renderer draws a styled heading and a top image automatically; leave them
empty and the card renders exactly as before (a plain container). These fields
are a convenience layered on top of manual composition: you can still build the
same thing with child sections (and combine with `card-segment` for full-bleed
sections). Filling a field never adds a section — it only changes how this card
draws. Turn **Border** on/off and pick a radius/shadow; padding is the shared
**Spacing** control (there is no separate card-padding field).

**Developers.** Renders `<Card>`. `title` (translatable) renders as an automatic
`<Text fw>` heading when non-empty; `img_src` renders as a top
`<Card.Section><Image></Card.Section>` when non-empty. Border is the
cross-platform `shared_border` (Mantine `withBorder`; themed border on mobile).

**Distinctive fields.** `title` (optional auto-styled heading, content),
`img_src` (optional top image via the asset picker, content), `shared_border`
(`0`/`1`), `shared_radius`, `web_card_shadow`. Padding is the portable
`shared_spacing` (`pt`/`pb`/`ps`/`pe`) — there is no web-only card-padding field;
the renderer keeps a fixed Mantine `padding="md"` inner default (also the
`Card.Section` image-bleed reference).

**Children.** Yes (often `card-segment`).

---

## card-segment

**Purpose.** Mantine `Card.Section` — a full-width segment inside a `card` (e.g. an image that bleeds to the card edges).

**Administrators.** Place inside a `card` to make a part of it span edge to edge.
Turn **Border** on to separate the segment with a divider line; turn **Inherit
padding** on (web) to align the segment with the card's horizontal padding.

**Developers.** Renders `<Card.Section>`. Only meaningful as a `card` child.
`shared_border` maps to Mantine `withBorder` (a themed divider on mobile);
`web_segment_inherit_padding` maps to Mantine `inheritPadding` (web only).

**Distinctive fields.** `shared_border` (`0`/`1`), `web_segment_inherit_padding`
(`0`/`1`, web only).

**Children.** Yes.

---

## aspect-ratio

**Purpose.** Mantine `AspectRatio` — keeps its child at a fixed width-to-height ratio.

**Administrators.** Use for responsive embeds (videos, maps, images) that must keep their shape as the page resizes. Set the ratio (e.g. `16/9`).

**Developers.** Renders `<AspectRatio>`.

**Distinctive fields.** `web_aspect_ratio` (e.g. `16/9`).

**Children.** Yes.

---

## background-image

**Purpose.** Mantine `BackgroundImage` — renders children over a background image.

**Administrators.** Put text or buttons on top of a hero image. Set the image source and corner radius.

**Developers.** Renders `<BackgroundImage>` with `img_src` as the source.

**Distinctive fields.** `img_src` (image URL/asset), `web_radius`.

**Children.** Yes.

---

## ref-container

**Purpose.** Structural, transparent container for **reusable section blocks**. It passes its children through without adding any visual styling, layout, or presentation of its own — it is the mechanism for rendering one section on several pages.

**Administrators.** Reach for `ref-container` when the *same* block of content must appear on more than one page (a shared banner, a reusable card group, …). Author it once, then add it to other pages from the **Reference Containers** picker in the page editor (it lists every `ref-container` so you can pick an existing one instead of recreating it). Editing the block updates it everywhere it is used. Two distinct verbs apply when taking it off a page:

- **Remove from page** (single or bulk) only *detaches* the shared block from that one page — it keeps rendering on every other page that references it.
- **Delete** *destroys* the block on every page at once.

**Developers.** `ref-container` has no renderer chrome: the frontend renders its children directly, so it introduces no DOM wrapper or Mantine element. It is surfaced to the editor by `GET /cms-api/v1/admin/sections/ref-containers` (`styleName` is always `ref-container`). The same section row is linked to multiple pages through `rel_pages_sections` / `rel_sections_hierarchy`; `SectionRelationshipService` resolves every referencing page (`SectionRepository::getPageIdsContainingSection`) and fans cache invalidation out to all of them on edit, on detach, and — capturing the page set **before** the relationship rows are removed — on destroy, so no page keeps serving a stale shared block. See [Admin Pages & Sections APIs](../api/04-admin-pages-sections.md) for the add / remove-from-page / bulk-remove / delete endpoints.

**Distinctive fields.** None — `ref-container` has no own fields; all rendering comes from its children.

**Children.** Yes (required — the children *are* the reusable block).

---

## data-container

**Purpose.** A **data-scoped** structural container: it renders its child subtree against a backend-resolved data scope (so the children can interpolate `{{field}}` values from that scope) without adding any visual chrome.

**Administrators.** Wrap a block of sections that should read from a specific data source. Bind the scope via the section's data config; the children then interpolate the scoped values. Like `ref-container`, it is transparent — it adds no visual styling of its own.

**Developers.** The backend resolves the `scope` data context and interpolates the subtree server-side, so both renderers render the already-resolved children through the normal recursive dispatcher (no second renderer): web `DataContainerStyle`, mobile `components/styles/layout/DataContainer.tsx`. Binding is via `data_config` (see [_conventions.md](./_conventions.md)).

**Distinctive fields.** None beyond the common fields; data binding via `data_config`.

**Children.** Yes (the scoped subtree).

---

## Related references

- [_conventions.md](./_conventions.md) — common fields and Mantine prop conventions.
- [index.md](./index.md) — full style catalog.
