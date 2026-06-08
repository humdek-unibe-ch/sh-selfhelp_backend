# Layout styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 layout styles (`@selfhelp/shared` `layout` category).
Last verified: 2026-06-04.
Source of truth: `src/types/styles/layout.ts`, `src/registry/styles.registry.ts`, the `admin/styles/schema` endpoint, and `src/app/components/frontend/styles/` renderers.

Layout styles arrange other sections on the page. They are almost all
[Mantine](https://mantine.dev) layout primitives. Read
[`_conventions.md`](./_conventions.md) first — the common fields (`css`,
`css_mobile`, `condition`, spacing, `use_mantine_style`) are documented there and
are not repeated below.

**When to reach for which layout (administrators):** use `stack` for a vertical
column, `group` for a horizontal row, `grid`/`simple-grid` for multi-column
grids, `container` to constrain page width, `card`/`paper` for raised surfaces,
and `box`/`flex` when you need full control.

---

## container

**Purpose.** Mantine `Container` — centres content and caps its width at a responsive maximum.

**Administrators.** Wrap a page's main content in a container so it stays readable on wide screens. Pick a `size` for the max width, or turn on `fluid` to span the full width.

**Developers.** Renders `<Container>`; children render inside. Honors horizontal/vertical padding via `mantine_px` / `mantine_py`.

**Distinctive fields.** `mantine_size` (max-width preset), `mantine_fluid` (full-width toggle), `mantine_px` / `mantine_py` (inner horizontal/vertical padding).

**Children.** Yes.

---

## box

**Purpose.** Mantine `Box` — the generic, unopinionated wrapper element.

**Administrators.** A neutral container when no other layout fits. Style it with `css` / spacing. Use it to group sections without imposing flex/grid behaviour.

**Developers.** Renders `<Box>` (or a plain element when `use_mantine_style = 0`). `content` is an optional inline text/HTML payload for simple cases.

**Distinctive fields.** `content` (optional inline content).

**Children.** Yes.

---

## flex

**Purpose.** Mantine `Flex` — a full CSS flexbox container.

**Administrators.** Use when you need precise control over how children line up: direction (row/column), spacing, alignment, justification, and wrapping.

**Developers.** Renders `<Flex>` mapping each `mantine_*` prop directly to the flexbox CSS property.

**Distinctive fields.** `mantine_gap`, `mantine_justify` (justify-content), `mantine_align` (align-items), `mantine_direction` (row/column), `mantine_wrap`, `mantine_width`, `mantine_height`.

**Children.** Yes.

---

## group

**Purpose.** Mantine `Group` — lays children out **horizontally** with even spacing.

**Administrators.** The quickest way to put items in a row (e.g. buttons side by side). Set the gap, and optionally let items grow to fill the row or wrap to the next line.

**Developers.** Renders `<Group>`. `mantine_group_grow` makes children share width equally; `mantine_group_wrap` toggles wrapping.

**Distinctive fields.** `mantine_gap`, `mantine_justify`, `mantine_align`, `mantine_group_wrap` (`0`/`1`), `mantine_group_grow` (`0`/`1`), `mantine_width`, `mantine_height`.

**Children.** Yes.

---

## stack

**Purpose.** Mantine `Stack` — lays children out **vertically** with even spacing.

**Administrators.** The default choice for stacking sections in a column. Set the gap and how items align/justify.

**Developers.** Renders `<Stack>`.

**Distinctive fields.** `mantine_gap`, `mantine_justify`, `mantine_align`, `mantine_width`, `mantine_height`.

**Children.** Yes.

---

## simple-grid

**Purpose.** Mantine `SimpleGrid` — an equal-width responsive grid.

**Administrators.** Use for galleries or card rows where every column is the same width. Set the column count and spacing; use breakpoints to change columns on small screens.

**Developers.** Renders `<SimpleGrid>`. `mantine_breakpoints` is a JSON string of responsive column overrides.

**Distinctive fields.** `mantine_cols` (column count), `mantine_spacing` (horizontal), `mantine_vertical_spacing`, `mantine_breakpoints` (responsive JSON), `mantine_width`, `mantine_height`.

**Children.** Yes.

---

## grid

**Purpose.** Mantine `Grid` — a 12-column grid whose columns (`grid-column`) can span and offset independently.

**Administrators.** Use for asymmetric layouts (e.g. a wide main column + a narrow sidebar). Add `grid-column` children and set each one's span.

**Developers.** Renders `<Grid>`; direct children should be `grid-column` sections.

**Distinctive fields.** `mantine_cols`, `mantine_gap`, `mantine_justify`, `mantine_align`, `mantine_grid_overflow`, `mantine_width`, `mantine_height`.

**Children.** Yes (typically `grid-column`).

---

## grid-column

**Purpose.** Mantine `Grid.Col` — one column inside a `grid`.

**Administrators.** Place inside a `grid`. Set how many of the 12 columns it spans, and optionally an offset or order.

**Developers.** Renders `<Grid.Col>`. `mantine_grid_span` accepts a number, `auto`, or `content`.

**Distinctive fields.** `mantine_grid_span` (width), `mantine_grid_offset`, `mantine_grid_order`, `mantine_grid_grow`, `mantine_width`, `mantine_height`.

**Children.** Yes.

---

## space

**Purpose.** Mantine `Space` — adds empty horizontal or vertical space.

**Administrators.** Drop between sections to add a gap without using margins. Pick a size and direction.

**Developers.** Renders `<Space>`. Leaf node.

**Distinctive fields.** `mantine_size`, `mantine_space_direction` (horizontal/vertical).

**Children.** No.

---

## divider

**Purpose.** Mantine `Divider` — a horizontal or vertical separator line, optionally with a label.

**Administrators.** Visually split content into groups. Add an optional centred label ("OR", section names) and choose the line style.

**Developers.** Renders `<Divider>`.

**Distinctive fields.** `mantine_divider_variant` (solid/dashed/dotted), `mantine_size` (thickness), `mantine_divider_label`, `mantine_divider_label_position`, `mantine_orientation`, `mantine_color`.

**Children.** No.

---

## paper

**Purpose.** Mantine `Paper` — a surface with optional shadow, radius, and border.

**Administrators.** Lift content onto a "sheet" with a drop shadow and rounded corners. Good for panels and call-outs.

**Developers.** Renders `<Paper>`.

**Distinctive fields.** `mantine_paper_shadow`, `mantine_radius`, `mantine_px` / `mantine_py` (padding), `mantine_border` (`0`/`1`).

**Children.** Yes.

---

## center

**Purpose.** Mantine `Center` — centres its single child both horizontally and vertically.

**Administrators.** Use to centre a logo, spinner, or message. Optionally constrain min/max width and height.

**Developers.** Renders `<Center>`. `mantine_center_inline` switches to inline centering.

**Distinctive fields.** `mantine_center_inline`, `mantine_width` / `mantine_height`, `mantine_miw` / `mantine_mih` (min), `mantine_maw` / `mantine_mah` (max).

**Children.** Yes.

---

## scroll-area

**Purpose.** Mantine `ScrollArea` — a scrollable region with styled scrollbars.

**Administrators.** Wrap tall content (long lists, tables) in a fixed-height box that scrolls internally. Set the height and when scrollbars appear.

**Developers.** Renders `<ScrollArea>`.

**Distinctive fields.** `mantine_scroll_area_type` (hover/always/never/scroll), `mantine_scroll_area_scrollbar_size`, `mantine_scroll_area_offset_scrollbars`, `mantine_scroll_area_scroll_hide_delay`, `mantine_height`, `mantine_width`.

**Children.** Yes.

---

## card

**Purpose.** Mantine `Card` — a bordered, rounded content card.

**Administrators.** Group related content (image + title + text + button) into a card. Combine with `card-segment` for full-bleed sections inside the card.

**Developers.** Renders `<Card>`.

**Distinctive fields.** `mantine_card_shadow`, `mantine_border` (`0`/`1`), `mantine_radius`.

**Children.** Yes (often `card-segment`).

---

## card-segment

**Purpose.** Mantine `Card.Section` — a full-width segment inside a `card` (e.g. an image that bleeds to the card edges).

**Administrators.** Place inside a `card` to make a part of it span edge to edge.

**Developers.** Renders `<Card.Section>`. Only meaningful as a `card` child.

**Distinctive fields.** None beyond the common/spacing fields.

**Children.** Yes.

---

## aspect-ratio

**Purpose.** Mantine `AspectRatio` — keeps its child at a fixed width-to-height ratio.

**Administrators.** Use for responsive embeds (videos, maps, images) that must keep their shape as the page resizes. Set the ratio (e.g. `16/9`).

**Developers.** Renders `<AspectRatio>`.

**Distinctive fields.** `mantine_aspect_ratio` (e.g. `16/9`).

**Children.** Yes.

---

## background-image

**Purpose.** Mantine `BackgroundImage` — renders children over a background image.

**Administrators.** Put text or buttons on top of a hero image. Set the image source and corner radius.

**Developers.** Renders `<BackgroundImage>` with `img_src` as the source.

**Distinctive fields.** `img_src` (image URL/asset), `mantine_radius`.

**Children.** Yes.

---

## Related references

- [_conventions.md](./_conventions.md) — common fields and Mantine prop conventions.
- [index.md](./index.md) — full style catalog.
