# Composite styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 composite styles (`@selfhelp/shared` `composite` category).
Last verified: 2026-06-04.
Source of truth: `src/types/styles/composite.ts`, `src/registry/styles.registry.ts`, the `admin/styles/schema` endpoint, and `src/app/components/frontend/styles/` renderers.

Composite styles combine child sections into a richer widget (accordions, tabs,
timelines, lists) or render **data-driven** collections (saved form entries,
loops). Read [`_conventions.md`](./_conventions.md) first; common fields are not
repeated below.

Most composite styles come in **parent + item** pairs: add the parent, then add
the matching item children inside it.

---

## accordion

**Purpose.** Mantine `Accordion` — a stack of collapsible panels.

**Administrators.** Use for FAQs or grouped content where only some panels are open at a time. Add `accordion-item` children. Allow multiple open panels via `mantine_accordion_multiple`, and set the default-open item.

**Developers.** Renders `<Accordion>`; children are `accordion-item`. `mantine_accordion_default_value` selects the initially open item value(s).

**Distinctive fields.** `mantine_accordion_variant`, `mantine_accordion_multiple`, `mantine_accordion_chevron_position`, `mantine_accordion_chevron_size`, `mantine_accordion_disable_chevron_rotation`, `mantine_accordion_loop`, `mantine_accordion_transition_duration`, `mantine_accordion_default_value`, `mantine_radius`.

**Children.** Yes (`accordion-item`).

---

## accordion-item

**Purpose.** Mantine `Accordion.Item` — one collapsible panel.

**Administrators.** Place inside an `accordion`. Set the panel `label` (the clickable header) and a unique `mantine_accordion_item_value`. Put the panel body as children.

**Developers.** Renders `<Accordion.Item value>` with a control + panel; body comes from child sections.

**Distinctive fields.** `label` (header), `mantine_accordion_item_value` (unique key), `mantine_accordion_item_icon`, `disabled`.

**Children.** Yes (the panel body).

---

## tabs

**Purpose.** Mantine `Tabs` — a tabbed interface.

**Administrators.** Split content across tabs. Add `tab` children; choose orientation (horizontal/vertical) and variant.

**Developers.** Renders `<Tabs>`; children are `tab` (each contributing a `Tabs.Tab` + `Tabs.Panel`).

**Distinctive fields.** `mantine_tabs_variant`, `mantine_tabs_orientation`, `mantine_tabs_radius`, `mantine_color`, `mantine_width`, `mantine_height`.

**Children.** Yes (`tab`).

---

## tab

**Purpose.** A single tab — a `Tabs.Tab` label plus its `Tabs.Panel` content.

**Administrators.** Place inside `tabs`. Set the tab `label` and optional icons; put the tab's content as children.

**Developers.** Renders the `Tabs.Tab` + `Tabs.Panel` pair; panel content comes from children.

**Distinctive fields.** `label`, `mantine_left_icon` / `mantine_right_icon`, `mantine_tab_disabled`, `mantine_width`, `mantine_height`.

**Children.** Yes (the panel content).

---

## timeline

**Purpose.** Mantine `Timeline` — a vertical sequence of events with bullets and a connecting line.

**Administrators.** Show steps or a history. Add `list-item`-style children as events; set how many leading items are "active" and the bullet/line styling.

**Developers.** Renders `<Timeline active>`. `mantine_timeline_active` marks completed items.

**Distinctive fields.** `mantine_timeline_bullet_size`, `mantine_timeline_line_width`, `mantine_timeline_active`, `mantine_timeline_align`, `mantine_timeline_line_variant`, `mantine_color`.

**Children.** Yes.

---

## list

**Purpose.** Mantine `List` — an ordered or unordered list.

**Administrators.** A bullet or numbered list. Choose ordered/unordered, the marker style, spacing, and an optional custom icon for items.

**Developers.** Renders `<List type listStyleType>`; children are `list-item`.

**Distinctive fields.** `mantine_list_type` (ordered/unordered), `mantine_list_list_style_type` (marker), `mantine_spacing`, `mantine_size`, `mantine_list_with_padding`, `mantine_list_center`, `mantine_list_icon`.

**Children.** Yes (`list-item`).

---

## list-item

**Purpose.** Mantine `List.Item` — one list entry.

**Administrators.** Place inside a `list`. Set the item text (`mantine_list_item_content`) and an optional per-item icon.

**Developers.** Renders `<List.Item>`.

**Distinctive fields.** `mantine_list_item_content` (text), `mantine_list_item_icon`.

**Children.** Yes.

---

## entryList

**Purpose.** A **data-driven** list that renders the rows saved by a `form-log` (one block per stored entry).

**Administrators.** Show submitted entries back to the user (e.g. a list of past journal entries). Bind it to the source form via the section's data config; lay out how each entry looks using child sections, which can interpolate the entry's fields with `{{field_name}}`.

**Developers.** Driven by `data_config`. Renders its child template once per backend-provided row; `line_clamp` truncates long text. Pairs with `entryRecord` / `entryRecordDelete`.

**Distinctive fields.** `line_clamp` (max lines per entry). Data binding via `data_config` (see [_conventions.md](./_conventions.md)).

**Children.** Yes (the per-entry template).

---

## entryRecord

**Purpose.** A **data-driven** container for a single stored record (the `form-record` row).

**Administrators.** Display one saved record and interpolate its fields with `{{field_name}}` in child sections.

**Developers.** Driven by `data_config`; exposes one record's fields to its children.

**Distinctive fields.** None beyond the common fields; binding via `data_config`.

**Children.** Yes.

---

## entryRecordDelete

**Purpose.** An inline delete control/confirmation for a stored entry.

**Administrators.** Add inside an entry template to let users delete that entry (with confirmation).

**Developers.** Renders a delete action scoped to the current entry/record context.

**Distinctive fields.** None beyond the common fields.

**Children.** No.

---

## loop

**Purpose.** A generic repeater over a backend-provided collection.

**Administrators.** Repeat a block of child sections once per item in a data set, interpolating each item's values. Use when you have a list of items to template that is not a form-log table.

**Developers.** Iterates the `loop` data array, rendering its child template per item. Bind the collection via `data_config`.

**Distinctive fields.** `loop` (the collection); binding via `data_config`.

**Children.** Yes (the per-item template).

---

## Related references

- [_conventions.md](./_conventions.md) — common fields and Mantine prop conventions.
- [forms.md](./forms.md) — the `form-log` / `form-record` styles that produce the data `entryList` / `entryRecord` display.
- [index.md](./index.md) — full style catalog.
