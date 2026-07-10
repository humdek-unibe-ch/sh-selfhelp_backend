# Composite styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 composite styles (`@selfhelp/shared` `composite` category).
Last verified: 2026-07-09.
Source of truth: `src/types/styles/composite.ts`, `src/registry/styles.registry.ts`, the `admin/styles/schema` endpoint, and `src/app/components/frontend/styles/` renderers.

Composite styles combine child sections into a richer widget (accordions, tabs,
timelines, lists) or render **data-driven** collections (saved form entries,
loops). Read [`_conventions.md`](./_conventions.md) first; common fields are not
repeated below.

Most composite styles come in **parent + item** pairs: add the parent, then add
the matching item children inside it.

---

## accordion

**Purpose.** Mantine `Accordion` (web) / HeroUI Native `Accordion` compound (mobile) — a stack of collapsible panels.

**Administrators.** Use for FAQs or grouped content where only some panels are open at a time. Add `accordion-item` children. Allow multiple open panels via **Multiple** (`multiple`), pick the **Variant**, and (web) set the default-open item.

**Developers.** Web renders `<Accordion>`; mobile renders the HeroUI Native `Accordion` compound (themed + animated) and each child `accordion-item` consults the HeroUI accordion context automatically (no custom context). The mobile renderer reads `shared_*` only. Precedence is shared → component default.

**Distinctive fields.**

- `accordion_variant` (shared, select: `default`/`contained`/`filled`/`separated`) — web → Mantine `variant`; mobile → HeroUI `variant` (`default`, or `surface` for the boxed variants), via `mapAccordionVariantToHeroUiVariant`. *Renamed from the web-only `web_accordion_variant` in `Version20260619183601`; clearable.*
- `multiple` (shared, checkbox) — single vs multiple open; read by both platforms.
- `radius` (shared, slider) — web → Mantine `radius`; mobile → surface container border radius.
- `web_accordion_chevron_position`, `web_accordion_chevron_size`, `web_accordion_disable_chevron_rotation`, `web_accordion_loop`, `web_accordion_transition_duration`, `web_accordion_default_value` (web-only Mantine presentation; the mobile chevron is HeroUI's animated `Accordion.Indicator`).

**Children.** Yes (`accordion-item`).

---

## accordion-item

**Purpose.** Mantine `Accordion.Item` (web) / HeroUI Native `Accordion.Item` (mobile) — one collapsible panel.

**Administrators.** Place inside an `accordion`. Set the panel `label` (the clickable header) and an optional `description` subtitle shown under it. Put the panel body as children. (Web also offers a unique `web_accordion_item_value` and an optional header icon.)

**Developers.** Web renders `<Accordion.Item value>` with a control (label + optional dimmed `description` + icon) and a panel. Mobile renders `Accordion.Item` / `.Trigger` / `.Indicator` / `.Content` with theme-aware text from `useAppColors()`; the mobile item value is the section id (the web-only `web_accordion_item_value` / icon are not read on mobile). Plain-text slots are sanitized (web `stripHtmlTags`, mobile `stripHtmlToText`). Body comes from child sections.

**Distinctive fields.**

- `label` (content, markdown-inline) — the clickable header text.
- `description` (content, textarea) — optional subtitle under the label; empty = hidden. *Added in `Version20260619183601`.*
- `disabled` (common, checkbox) — disables the panel.
- `web_accordion_item_value` (web, text) — unique key used by the accordion's web default-open feature.
- `web_accordion_item_icon` (web, select-icon) — header icon (web only).

**Children.** Yes (the panel body).

---

## tabs

**Purpose.** Mantine `Tabs` — a tabbed interface.

**Administrators.** Split content across tabs. Add `tab` children; choose orientation (horizontal/vertical) and variant.

**Developers.** Renders `<Tabs>`; children are `tab` (each contributing a `Tabs.Tab` + `Tabs.Panel`). `web_tabs_grow` / `web_tabs_justify` apply to the `Tabs.List` (stretch / alignment); `web_tabs_keep_mounted` keeps inactive panels mounted; `web_tabs_placement` sets the list side when the orientation is vertical (all web-only).

**Distinctive fields.** `web_tabs_variant`, `web_tabs_orientation`, `web_tabs_radius`, `color`, `web_tabs_grow`, `web_tabs_justify`, `web_tabs_keep_mounted`, `web_tabs_placement`, `web_width`, `web_height`.

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

**Distinctive fields.** `web_timeline_bullet_size`, `web_timeline_line_width`, `web_timeline_active`, `web_timeline_align`, `web_timeline_line_variant`, `color`.

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

**Administrators.** Place inside a `list`. Set the item text (`list_item_content`) and an optional per-item icon. `list_item_content` is a **rich-text** field (`markdown-inline`): select a word and press **Ctrl/⌘ + B / I / U** or add a link — it renders the same on web and mobile.

**Developers.** Renders `<List.Item>`. `list_item_content` is `markdown-inline`; web renders it via `sanitizeHtmlForParsing` + `html-react-parser`, mobile via `parseInlineRich` + `<InlineText>` (plain strings pass straight through).

**Distinctive fields.** `list_item_content` (text, `markdown-inline`), `web_list_item_icon`.

**Children.** Yes.

---

## entry-list

**Purpose.** A **data/context holder** that renders one block per row of a bound data table (any table, not only a `form-log`).

**Administrators.** Show a collection of records (e.g. team members, journal entries). Bind it to a data table via the **Data table** property field in the section inspector (Properties panel); lay out how each row looks using child sections, which interpolate the row's fields with `{{field_name}}`. Optional **Filter** accepts a SQL WHERE fragment (route tokens like `{{route.category}}` are validated server-side). **Own entries only** limits rows to the current user (default on). **Scope** prefixes row keys (`scope.name`). **Load as table** wraps each clone in a `<table>` row on web. To make a list/detail pattern, give each row a link to its detail page using `/<base>/{{record_id}}`. The **Create list + detail pages** wizard (Admin → Pages) scaffolds this for you — see [../../cookbook/cms-in-cms-list-detail.md](../../cookbook/cms-in-cms-list-detail.md).

**Data binding (important).** Which rows appear is controlled **only** by the style property fields below — not by **Data configuration** (`global_fields.data_config`).

| Task | Where to configure it |
|------|------------------------|
| Choose the data table | **Data table** property field |
| Limit rows to the current user | **Own entries only** property field |
| SQL constraints on loaded rows | **Filter** property field (SQL builder) |
| Prefix row tokens (`item.name`) | **Scope** property field |
| Optional column subset | **Selected columns** property field |

**Do not** use Data configuration → *Table* on an `entry-list` section to bind rows. That path applies to other styles (`loop`, `data-container`, …) but is **ignored** for entry holders since core `0.1.35`. A section with only a legacy `data_config.table` binding and an empty **Data table** field renders **no rows**.

You **may** still add Data configuration on the same section when you need **helper scopes** only — for example a `filters` scope whose values you reference inside the **Filter** property field (`{{filters.category}}`). Helper scopes do not replace **Data table**.

**Developers.** Pure holder driven by style property fields (`data_table`, `own_entries_only`, `filter`, `scope`, `load_as_table`, `selected_columns`); the **backend** clones the child template once per bound row during page render (`PageService::resolveEntryRows` / `processSectionsRecursively`, Step 8) and flattens each row's columns into top-level interpolation tokens, so `{{name}}` / `{{record_id}}` resolve per clone (row keys are remapped from the immutable `section_<id>` field keys back to the current input names). Row table and retrieve mode come **only** from those property fields — never from `data_config.table`. `data_config` on the same section may still run earlier in the pipeline to populate helper scopes (e.g. `filters`) that the author `filter` field may reference via `{{filters.*}}`. Filters pass through `DataTableFilterService` (typed route params, SQL denylist). The web/mobile renderers just render the already-cloned children. No rows → no children. Carries no presentational fields of its own. Pairs with `entry-record` / `entry-record-delete`. See [27-db-driven-public-routing.md](../../developer/27-db-driven-public-routing.md).

**Distinctive fields.** `data_table` (`select-data_table`), `own_entries_only` (checkbox), `filter` (code/SQL), `scope` (text), `load_as_table` (checkbox), `selected_columns` (`select-data_table_columns`, optional column subset).

Filter safety: see [data-table-filter-safety.md](../../developer/data-table-filter-safety.md).

**Children.** Yes (the per-entry template, cloned per row by the backend).

---

## entry-record

**Purpose.** A **data/context holder** for a single record of a bound data table. Pair of `entry-record-form`: the form creates/edits a row; this holder **displays** one row.

**Administrators.** Display one record and interpolate its fields with `{{field_name}}` in child sections. On a detail page reached via a parameterized route (e.g. `/team-members/{record_id}`):

1. Set **Data table** to the owning form's table (or `@section:<form>` in bundles).
2. Set **Load record from route parameter** to the route param name (usually `record_id`) — the **same field** as on `entry-record-form`.

There is **no SQL Filter** on this style. The server reads the named route param and loads that `record_id`. If the param is missing or empty, nothing is shown (fail-closed). Optional **Scope** prefixes interpolation tokens (e.g. `team_member.name`).

**Data binding (important).** The record comes **only** from property fields (**Data table**, **Own entries only**, **Load record from route parameter**, **Scope**). **Data configuration** → *Table* does **not** load the record.

**Developers.** `PageService::resolveEntryRows()` for `entry-record` builds `AND record_id = <int>` from `load_record_from` + the matched route params (same resolution path as `entry-record-form` / `SectionUtilityService::applySectionData`). No author SQL; no `DataTableFilterService` on this style. See [27-db-driven-public-routing.md](../../developer/27-db-driven-public-routing.md).

**Distinctive fields.** `data_table` (`select-data_table`), `own_entries_only` (checkbox), `load_record_from` (text; route param name, default `record_id`), `scope` (text). Removed: `url_param`, author `filter`.

**Children.** Yes.

---

## entry-record-delete

**Purpose.** An inline delete control/confirmation for a stored entry.

**Administrators.** Add inside an entry template to let users delete that entry (with confirmation).

**Developers.** Renders a delete action scoped to the current entry/record context. The backend hydrates `record_id` into the section during entry rendering; both renderers read the button text from `label_delete` (the generic `label` field is not linked to this style). The delete call goes to `DELETE /cms-api/v1/forms/delete`, whose validation accepts `entry-record-delete` (and `entry-table`) sections — ACL `delete` on the page plus the own-entries/data-table permission rules apply.

**Distinctive fields.** `label_delete` (button text, default "Delete"); `confirmation_title` / `confirmation_message` / `confirmation_continue` / `confirmation_cancel` (confirmation dialog copy); `own_entries_only`.

**Children.** No.

---

## loop

**Purpose.** A generic repeater over a backend-provided collection.

**Administrators.** Repeat a block of child sections once per item in a data set, interpolating each item's values. Use when you have a list of items to template that is not a form-log table.

**Developers.** Server-hydrated repeater, same mechanism as `entry-list`: the backend clones the child template once per row and flattens the row's keys into the interpolation data, so `{{key}}` resolves per item (see `PageService::processSectionsRecursively`). Rows come from the first `data_config` entry when one is bound; otherwise from the style's `loop` field — a static JSON array of row objects that is itself interpolated before decoding, so it may reference parent scopes. No rows means no children. Like the other holders it must **not** overwrite the read-only `route` interpolation scope, so child sections can still reference `{{route.*}}` URL params inside a loop.

**Distinctive fields.** `loop` (static JSON array of row objects, e.g. `[{"title":"First"},{"title":"Second"}]`); binding via `data_config` takes precedence.

**Children.** Yes (the per-item template).

---

## Related references

- [_conventions.md](./_conventions.md) — common fields and Mantine prop conventions.
- [forms.md](./forms.md) — the `form-log` / `form-record` styles that produce the data `entry-list` / `entry-record` display.
- [index.md](./index.md) — full style catalog.
