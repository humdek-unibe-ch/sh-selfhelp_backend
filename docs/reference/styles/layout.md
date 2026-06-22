# Layout styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 layout styles (`@selfhelp/shared` `layout` category).
Last verified: 2026-06-22.
Source of truth: `src/types/styles/layout.ts`, `src/registry/styles.registry.ts`, the `admin/styles/schema` endpoint, and `src/app/components/frontend/styles/` renderers.

Layout styles arrange other sections on the page. They are almost all
[Mantine](https://mantine.dev) layout primitives. Read
[`_conventions.md`](./_conventions.md) first — the common fields (`css`,
`css_mobile`, `condition`, spacing) are documented there and are not repeated
below.

> **Cross-platform pass (2026-06-22).** The layout styles used to keep most of
> their sizing/behaviour under `web_*`, so on mobile they were barely
> configurable. The portable properties — width/height (and min/max),
> column count, grid-column span/offset/order/grow, divider variant + label
> position, and the space direction — were promoted to `shared_*`, so the same
> field now drives both the web (Mantine) and the mobile (React Native flexbox)
> renderer. `web_px`/`web_py` (paper, container) were removed in favour of the
> portable `shared_spacing` padding, and `simple-grid` gained `shared_gap` plus
> `web_cols_sm`/`md`/`lg` responsive overrides (replacing the old
> `web_breakpoints`). See migration `Version20260622063129`.

**When to reach for which layout (administrators):** use `stack` for a vertical
column, `group` for a horizontal row, `grid`/`simple-grid` for multi-column
grids, `container` to constrain page width, `card`/`paper` for raised surfaces,
and `box`/`flex` when you need full control.

---

## container

**Purpose.** Mantine `Container` — centres content and caps its width at a responsive maximum.

**Administrators.** Wrap a page's main content in a container so it stays readable on wide screens. Pick a `size` for the max width, or turn on `fluid` to span the full width.

**Developers.** Renders `<Container>`; children render inside. Padding comes from the cross-platform `shared_spacing`. `web_fluid` (web only) switches to full width; on mobile a container is full-width by default.

**Distinctive fields.** `shared_size` (max-width preset), `web_fluid` (full-width toggle, web-only). Inner padding is the portable `shared_spacing` — the old web-only `web_px`/`web_py` were removed.

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

**Developers.** Renders `<Flex>`; the `shared_*` flexbox props map to Mantine on web and to React Native flexbox on mobile (RN defaults to `column`/no-wrap, so `shared_direction`/`shared_wrap` should be set explicitly).

**Distinctive fields.** `shared_gap`, `shared_justify` (justify-content), `shared_align` (align-items), `shared_direction` (row/column), `shared_wrap`, `shared_width`, `shared_height` — all cross-platform.

**Children.** Yes.

---

## group

**Purpose.** Mantine `Group` — lays children out **horizontally** with even spacing.

**Administrators.** The quickest way to put items in a row (e.g. buttons side by side). Set the gap, and optionally let items grow to fill the row or wrap to the next line.

**Developers.** Renders `<Group>`. `web_group_grow` makes children share width equally; `web_group_wrap` toggles wrapping.

**Distinctive fields.** `shared_gap`, `shared_justify`, `shared_align`, `shared_width`, `shared_height` (cross-platform); `web_group_wrap` (`0`/`1`), `web_group_grow` (`0`/`1`) stay web-only.

**Children.** Yes.

---

## stack

**Purpose.** Mantine `Stack` — lays children out **vertically** with even spacing.

**Administrators.** The default choice for stacking sections in a column. Set the gap and how items align/justify.

**Developers.** Renders `<Stack>`.

**Distinctive fields.** `shared_gap`, `shared_justify`, `shared_align`, `shared_width`, `shared_height` — all cross-platform.

**Children.** Yes.

---

## simple-grid

**Purpose.** Mantine `SimpleGrid` — an equal-width responsive grid.

**Administrators.** Use for galleries or card rows where every column is the same width. Set the column count and spacing; use breakpoints to change columns on small screens.

**Developers.** Renders `<SimpleGrid>`. Base columns are `shared_cols` (read on both platforms); `web_cols_sm`/`web_cols_md`/`web_cols_lg` add web responsive overrides. Horizontal column spacing is `shared_gap`, row spacing is `shared_vertical_spacing`. On mobile the equal-width grid is emulated with flex-wrap + width percentages.

**Distinctive fields.** `shared_cols` (base column count), `shared_gap` (horizontal column spacing), `shared_vertical_spacing` (row spacing), `shared_width`, `shared_height` (all cross-platform); `web_cols_sm` / `web_cols_md` / `web_cols_lg` (web-only responsive column overrides; clear to inherit `shared_cols`).

**Children.** Yes.

---

## grid

**Purpose.** Mantine `Grid` — a 12-column grid whose columns (`grid-column`) can span and offset independently.

**Administrators.** Use for asymmetric layouts (e.g. a wide main column + a narrow sidebar). Add `grid-column` children and set each one's span.

**Developers.** Renders `<Grid>`; direct children are `grid-column` sections. Grid is intentionally **restricted to `grid-column` children** through `rel_styles_allowed_relationships` (`styles.can_have_children = 0` **plus** a `grid → grid-column` whitelist row). That `0 + whitelist` combination is the catalog's "restricted children" model — it is **not** a missing-children bug; flipping `can_have_children` to `1` would let grid accept any child.

**Distinctive fields.** `shared_cols`, `shared_gap`, `shared_justify`, `shared_align`, `shared_width`, `shared_height` (cross-platform); `web_grid_overflow` (web-only).

**Children.** Restricted to `grid-column` (see Developers).

---

## grid-column

**Purpose.** Mantine `Grid.Col` — one column inside a `grid`.

**Administrators.** Place inside a `grid`. Set how many of the 12 columns it spans, and optionally an offset or order.

**Developers.** Renders `<Grid.Col>`. `shared_grid_span` accepts a number (1–12), `auto`, or `content`; on mobile the grid is emulated with flex-basis percentages from the span.

**Distinctive fields.** `shared_grid_span` (width), `shared_grid_offset`, `shared_grid_order`, `shared_grid_grow`, `shared_width`, `shared_height` — all cross-platform.

**Children.** Yes.

---

## space

**Purpose.** Mantine `Space` — adds empty horizontal or vertical space.

**Administrators.** Drop between sections to add a gap without using margins. Pick a size and direction.

**Developers.** Renders `<Space>` on web and a sized `View` on mobile. Leaf node.

**Distinctive fields.** `shared_size`, `shared_orientation` (horizontal/vertical) — both cross-platform.

**Children.** No.

---

## divider

**Purpose.** Mantine `Divider` — a horizontal or vertical separator line, optionally with a label.

**Administrators.** Visually split content into groups. Add an optional centred label ("OR", section names) and choose the line style.

**Developers.** Renders `<Divider>` on web and a themed `View` border on mobile. `shared_divider_variant` maps to Mantine `variant` and to RN `borderStyle` (solid/dashed/dotted).

**Distinctive fields.** `shared_divider_variant` (solid/dashed/dotted), `shared_size` (thickness), `divider_label` (content), `shared_divider_label_position`, `shared_orientation`, `shared_color` — all cross-platform.

**Children.** No.

---

## paper

**Purpose.** Mantine `Paper` — a surface with optional shadow, radius, and border.

**Administrators.** Lift content onto a "sheet" with a drop shadow and rounded corners. Good for panels and call-outs. Fill in the optional **Title** for an automatic heading above the content (leave it empty for a plain surface); turn **Border** on/off and pick a radius. Padding is the shared **Spacing** control.

**Developers.** Renders `<Paper>`. `title` (translatable) renders as an automatic `<Text fw>` heading when non-empty (it never adds a child section). `shared_border` maps to Mantine `withBorder` (a themed border on mobile), exactly like `card`. Shadow (`web_paper_shadow`) stays web-only because `react-native-web` shadows are unreliable.

**Distinctive fields.** `title` (optional auto-styled heading, content), `shared_border` (`0`/`1`), `shared_radius`, `web_paper_shadow` (web-only). Padding is the portable `shared_spacing` — the old web-only `web_px`/`web_py` were removed.

**Children.** Yes.

---

## center

**Purpose.** Mantine `Center` — centres its single child both horizontally and vertically.

**Administrators.** Use to centre a logo, spinner, or message. Optionally constrain min/max width and height.

**Developers.** Renders `<Center>`. `web_center_inline` switches to inline centering (web-only). The size constraints map to flexbox on mobile.

**Distinctive fields.** `shared_width` / `shared_height`, `shared_miw` / `shared_mih` (min), `shared_maw` / `shared_mah` (max) — all cross-platform; `web_center_inline` stays web-only.

**Children.** Yes.

---

## scroll-area

**Purpose.** Mantine `ScrollArea` — a scrollable region with styled scrollbars.

**Administrators.** Wrap tall content (long lists, tables) in a fixed-height box that scrolls internally. Set the height and when scrollbars appear.

**Developers.** Renders `<ScrollArea>` on web and a fixed-height `ScrollView` on mobile (RN needs a bounded height to scroll). The custom-scrollbar props are web-only.

**Distinctive fields.** `shared_height` (cross-platform — required for the mobile `ScrollView` to scroll), `web_scroll_area_type` (hover/always/auto/scroll/never), `web_scroll_area_scrollbar_size`, `web_scroll_area_offset_scrollbars`, `web_scroll_area_scroll_hide_delay` (web-only custom-scrollbar props).

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
