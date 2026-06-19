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

**Administrators.** Use for FAQs or grouped content where only some panels are open at a time. Add `accordion-item` children. Allow multiple open panels via `web_accordion_multiple`, and set the default-open item.

**Developers.** Renders `<Accordion>`; children are `accordion-item`. `web_accordion_default_value` selects the initially open item value(s).

**Distinctive fields.** `web_accordion_variant`, `web_accordion_multiple`, `web_accordion_chevron_position`, `web_accordion_chevron_size`, `web_accordion_disable_chevron_rotation`, `web_accordion_loop`, `web_accordion_transition_duration`, `web_accordion_default_value`, `web_radius`.

**Children.** Yes (`accordion-item`).

---

## accordion-item

**Purpose.** Mantine `Accordion.Item` — one collapsible panel.

**Administrators.** Place inside an `accordion`. Set the panel `label` (the clickable header) and a unique `web_accordion_item_value`. Put the panel body as children.

**Developers.** Renders `<Accordion.Item value>` with a control + panel; body comes from child sections.

**Distinctive fields.** `label` (header), `web_accordion_item_value` (unique key), `web_accordion_item_icon`, `disabled`.

**Children.** Yes (the panel body).

---

## tabs

**Purpose.** Mantine `Tabs` — a tabbed interface.

**Administrators.** Split content across tabs. Add `tab` children; choose orientation (horizontal/vertical) and variant.

**Developers.** Renders `<Tabs>`; children are `tab` (each contributing a `Tabs.Tab` + `Tabs.Panel`).

**Distinctive fields.** `web_tabs_variant`, `web_tabs_orientation`, `web_tabs_radius`, `shared_color`, `web_width`, `web_height`.

**Children.** Yes (`tab`).

---

## tab

**Purpose.** A single tab — a `Tabs.Tab` label plus its `Tabs.Panel` content.

**Administrators.** Place inside `tabs`. Set the tab `label` and optional icons; put the tab's content as children.

**Developers.** Renders the `Tabs.Tab` + `Tabs.Panel` pair; panel content comes from children.

**Distinctive fields.** `label`, `web_left_icon` / `web_right_icon`, `web_tab_disabled`, `web_width`, `web_height`.

**Children.** Yes (the panel content).

---

## timeline

**Purpose.** Mantine `Timeline` — a vertical sequence of events with bullets and a connecting line.

**Administrators.** Show steps or a history. Add `list-item`-style children as events; set how many leading items are "active" and the bullet/line styling.

**Developers.** Renders `<Timeline active>`. `web_timeline_active` marks completed items.

**Distinctive fields.** `web_timeline_bullet_size`, `web_timeline_line_width`, `web_timeline_active`, `web_timeline_align`, `web_timeline_line_variant`, `shared_color`.

**Children.** Yes.

---

## timeline-item

**Purpose.** A single event inside a `timeline` (the child style of `timeline`).

**Administrators.** Place inside a `timeline`. Each `timeline-item` is one dot on the line; put the event content in child sections. Use the parent `timeline`'s `web_timeline_active` to mark how many leading items are completed.

**Developers.** Child-only style (placement is enforced by the timeline parent). The web renderer (`TimelineItemStyle`) renders the item's children inside the parent `<Timeline>`; the mobile renderer (`components/styles/composite/TimelineItem.tsx`) renders the event row in the React Native timeline. It carries no presentation fields of its own — bullet/line styling lives on the parent `timeline`.

**Distinctive fields.** None beyond the common fields; styling is inherited from the parent `timeline`.

**Children.** Yes (the event content).

---

## list

**Purpose.** Mantine `List` — an ordered or unordered list.

**Administrators.** A bullet or numbered list. Choose ordered/unordered, the marker style, spacing, and an optional custom icon for items.

**Developers.** Renders `<List type listStyleType>`; children are `list-item`.

**Distinctive fields.** `web_list_type` (ordered/unordered), `web_list_list_style_type` (marker), `web_spacing`, `web_size`, `web_list_with_padding`, `web_list_center`, `web_list_icon`.

**Children.** Yes (`list-item`).

---

## list-item

**Purpose.** Mantine `List.Item` — one list entry.

**Administrators.** Place inside a `list`. Set the item text (`list_item_content`) and an optional per-item icon.

**Developers.** Renders `<List.Item>`.

**Distinctive fields.** `list_item_content` (text), `web_list_item_icon`.

**Children.** Yes.

---

## entry-list

**Purpose.** A **data-driven** list that renders the rows saved by a `form-log` (one block per stored entry).

**Administrators.** Show submitted entries back to the user (e.g. a list of past journal entries). Bind it to the source form via the section's data config; lay out how each entry looks using child sections, which can interpolate the entry's fields with `{{field_name}}`.

**Developers.** Driven by `data_config`. Renders its child template once per backend-provided row; `line_clamp` truncates long text. Pairs with `entry-record` / `entry-record-delete`.

**Distinctive fields.** `line_clamp` (max lines per entry). Data binding via `data_config` (see [_conventions.md](./_conventions.md)).

**Children.** Yes (the per-entry template).

---

## entry-record

**Purpose.** A **data-driven** container for a single stored record (the `form-record` row).

**Administrators.** Display one saved record and interpolate its fields with `{{field_name}}` in child sections.

**Developers.** Driven by `data_config`; exposes one record's fields to its children.

**Distinctive fields.** None beyond the common fields; binding via `data_config`.

**Children.** Yes.

---

## entry-record-delete

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
- [forms.md](./forms.md) — the `form-log` / `form-record` styles that produce the data `entry-list` / `entry-record` display.
- [index.md](./index.md) — full style catalog.
