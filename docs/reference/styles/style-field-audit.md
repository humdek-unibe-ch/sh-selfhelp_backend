# Style field audit (DB vs code)

Audience: Developers and maintainers.
Status: active (living audit — regenerate the data after catalog/renderer changes).
Applies to: the 90 core CMS styles and their 473 distinct in-use fields.
Last verified: 2026-06-22.
Source of truth: the live DB catalog (`admin/styles/schema`), `@selfhelp/shared` (registry + types + mapper), the web + mobile dispatch maps. Machine-readable companion: [`style-field-audit.generated.json`](./style-field-audit.generated.json), produced by `scripts/build-style-audit.php`.

> A deep, evidence-based comparison of what exists in the **database** (the truth)
> against what the **shared types, web renderer, and mobile renderer** expect. It
> classifies every field (see [`style-field-naming-rules.md`](./style-field-naming-rules.md)
> §4) and lists what is wrong, duplicated, or drifting so the
> [refactor](./style-refactoring-recommendations.md) can fix it.

## How to regenerate

```bash
php scripts/build-style-audit.php
# → docs/reference/styles/style-field-audit.generated.json
```

It reads the live DB through `DATABASE_URL` (read-only) and the sibling repos
(`../sh-selfhelp_shared`, `../sh-selfhelp_frontend`, `../sh-selfhelp_mobile`;
override with `SH_SHARED_DIR` / `SH_FRONTEND_DIR` / `SH_MOBILE_DIR`). Cross-repo
sections degrade gracefully when a repo is absent.

## 1. Headline numbers

| Metric | Value |
|--------|------:|
| Styles in DB | 90 |
| Styles in shared registry / web renderer / mobile renderer | 90 / 90 / 90 |
| Distinct fields in use | 473 |
| Field instances by scope: `content` | 276 |
| `common` | 453 |
| `web` | 232 |
| `mobile` | 12 |
| Styles currently `render_target = both` | 90 (all) |

Reading: the catalog is **perfectly reconciled at the style level** — no missing
or extra styles on any platform. Everything below is **field-level**.

The scope split is the core finding. **The `shared` scope no longer exists**:
migration `Version20260622165615` dropped the redundant `shared_` prefix from 47
fields, folding portable presentation (`size`, `radius`, `color`, `spacing`,
layout enums, …) into the single unprefixed `common` scope — "no prefix = both
platforms". Three names stay prefixed as reserved-name exceptions
(`shared_height`, `shared_width`, `shared_icon`) but still count as `common`. The
mobile contract is `common` (unprefixed presentation + behaviour) + content, plus
a small set of `mobile_*` native-only knobs.

## 2. Catalog parity — clean

```
in_registry_not_db: []   in_db_not_registry: []
in_web_not_db:      []   in_db_not_web:      []
in_mobile_not_db:   []   in_db_not_mobile:   []
```

No action. (This was *not* true mid-refactor: mobile previously had 98 keys and
was reconciled to 90 — see `mobile.md` §4.6.)

## 3. The two spacing systems — consolidated (RF-15, landed)

This was the highest-impact cleanup; it is now **done**. The catalog previously
carried two competing spacing fields (`web_spacing_margin`, 39 styles, margin-only
web-only; and `spacing`, 37 styles, portable box-model). Migration
`Version20260619100642` (paired with `@selfhelp/shared` v1.14.4) merged the
margin-only field and its authored section values into the portable box-model
field and dropped `web_spacing_margin` + its `spacing-margin` field type.

| Field | Styles using it | Meaning |
|-------|----------------:|---------|
| `spacing` | 76 | portable box-model (margin + padding), both platforms |
| ~~`web_spacing_margin`~~ | 0 | **removed** — merged into `spacing` |

The web renderer now always reads `spacing` (the `?? web_spacing_margin`
fallback is gone); mobile reads the same field. **Spacing is now one
cross-platform field** — all 76 spacing-capable styles get portable spacing on
both platforms.

## 4. DB ↔ shared-type drift — clean

The generator parses every `I*Style` interface in `@selfhelp/shared`, compares
its fields against the DB catalog, and records the delta per style in
`drift_db_fields_missing_from_type` / `drift_type_fields_missing_from_db`. After
the field-naming unification wave (`Version20260622165615` + `@selfhelp/shared`
v1.14.22/1.14.23) and the parser fix below, **all 90 styles report empty drift
arrays and `has_shared_type: true`** — the shared types now match the installed
catalog field-for-field.

```
drift across all 90 styles: 0 db-fields-missing-from-type, 0 type-fields-missing-from-db
has_shared_type: true for all 90 styles
```

> **Parser fix (2026-06-22).** Earlier snapshots of this section were unreliable.
> The generator's regex interface-parser terminated a body only on `\n}`, so an
> interface that closed with `;}` on one line (or any non-`\n}` formatting) made
> the non-greedy match swallow the *next* interface: a style appeared to "own"
> an unrelated style's fields, and the swallowed style reported
> `has_shared_type: false`. The terminator is now `[\n;]\s*\}` (tolerant of `;}`,
> `;\n}`, and indented braces), which lifted the parsed-interface count from 59 to
> all 90 and collapsed the phantom drift to zero. If non-empty drift reappears
> here it is now a **real** type↔DB contract gap to reconcile (fix the shared
> type or extend the DB seed), not a parser artefact.

## 5. Duplicate / typo / mis-scope findings

| Style | Field(s) | Problem | Fix |
|-------|----------|---------|-----|
| `alert` | `value` (display 0) + `content` (display 1) | duplicate concept ("content/message of the alert") | remove `value`; keep `content` |
| `alert` | `web_with_close_button` + `web_alert_with_close_button` | two close-button toggles | keep one; remove the twin |
| `alert` | `web_alert_title` (display 1) | translatable copy under a `web_` prefix; mobile reads it as a leak | rename to unprefixed `alert_title` (both platforms read it) |
| `datepicker` | `web_datepicker_allow_deseselect` | **typo** (`deseselect`) — the type expects `web_datepicker_allow_deselect` | rename field via migration; migrate values by `id_fields` |
| `profile` | `profile_timezone_change_*` etc. | shared type ahead of DB (12 fields) | add to DB seed or remove from type — decide |

The `alert.value`/`alert.content` pair is the **real** instance of the "remove the
duplicate mobile field" example: mobile already loads the message from the shared
`content` field, so the extra `value` field is dead weight. (There is no
`mobile_message` field anywhere in the DB or mobile repo — the principle still
holds for `value`.)

## 6. Most-shared fields (the de-facto common library)

The fields used by the most styles — the ones whose contract changes ripple
widest:

| Field | # styles | Scope |
|-------|---------:|-------|
| `spacing` | 76 | common |
| `color` | 35 | common |
| `radius` | 35 | common |
| `size` | 30 | common |
| `label` | 28 | content |
| `name` | 23 | common |
| `disabled` | 22 | common |
| `value` | 20 | common |
| `description` | 19 | content |
| `is_required` | 17 | common |
| `title` | 14 | content |
| `web_left_icon` | 14 | web |
| `placeholder` | 9 | content |

The most-shared field is `spacing` (76 styles) after the spacing
consolidation (§3) — the single widest-rippling contract in the catalog, portable
to both platforms. The next three (`color`, `radius`, `size`)
are all unprefixed cross-platform appearance fields (`common` scope since the
`shared_` prefix was dropped in `Version20260622165615`), so the de-facto common
library is genuinely cross-platform. The earlier near-universal `use_web_style`
"Mantine vs raw element" toggle has been **removed** (RF-01) — it was meaningless
on mobile. The only `web_*` field still in the top tier is `web_left_icon` (the
icon affordance); the box metrics `web_height` / `web_width` have dropped out of
the top tier as their usage consolidated.

## 7. Per-field "where used / how loaded" — the contract

The generated JSON records, for every field of every style: `type` (editor),
`scope`, `display`, and `default`. The "used by web / used by mobile / mapped to"
columns follow deterministically from the [naming rules](./style-field-naming-rules.md)
and [mobile mapping](./style-mobile-mapping.md):

- `content` / `common` → read by **both** renderers (content interpolated per
  locale; common read as behaviour/data).
- `shared` → read by **both** via the `@selfhelp/shared` mapper.
- `web` → read by **web only**.
- `mobile` → read by **mobile only** (none yet).

See [`style-mobile-mapping.md`](./style-mobile-mapping.md) §5 for the full `alert`
field-by-field worked example in this format.

## 8. What the audit does NOT yet cover (honest gaps)

- **Content-reference counts.** mobile.md Phase 0 wants per-style usage counts
  from `sections` / `sections_fields_translation` before any field/style removal.
  The generator can be extended to add them; today removal recommendations are
  flagged "verify zero references first".
- **Per-category page field lists.** The 7 category pages
  (`layout.md`, `typography.md`, …) still describe fields in prose and have not
  all been re-verified field-by-field against this audit. They should be updated
  in a follow-up pass driven by the generated JSON (the `alert` section in
  `interactive.md` is corrected as the worked example).
- **Plugin styles.** Out of scope (documented in their own repos).

## 9. Per-style evaluation (all 90)

Every style evaluated the way the 2026-06-19 review evaluated the samples: *is it
configurable? are the props shared or platform-specific? what is dead / mis-scoped
/ needs a mobile path?* **Universal rules (assumed for every style, not repeated
per row):** `use_web_style` removed (RF-01); `web_color` → `color` (RF-13);
`web_spacing_margin` → `spacing` (RF-15); any **translatable** `web_*`
field un-prefixed (RF-35); Pro override where one exists (RF-25…RF-34). Rows list
only the **style-specific** notes on top of those.

### Auth / system

| Style | Verdict + specific actions |
|-------|----------------------------|
| `login` | Mostly content + `web_color` (login button colour). RF-13 makes the button colour work on mobile. Drop stale type field `type` (RF-02). |
| `register` | Remove `label_security_question_1/2` (RF-03, landed). Keep `group` backend-only (RF-24). `web_color` → `color` (RF-13). |
| `validate` | Custom multi-step; many `web_buttons_*`/`web_btn_*`/`web_card_*` → promote button order/position/variant + colours to `shared_*` so mobile builds the same form (RF-21). Reconcile content drift (RF-12). |
| `reset-password` | Remove `subject_user`,`is_html` (RF-06, landed). Legacy single-field copies (`label_pw_reset`,`alert_success`,`placeholder`) likely superseded by the `reset_*` set — verify + remove (RF-09). |
| `two-factor-auth` | No `use_web_style`. Add `label_code`,`label_submit`,`title` to DB (RF-23). OTP UI uses HeroUI `input-otp` internally. |
| `profile` | 64 content fields + an accordion config block (`profile_accordion_*`,`profile_columns`,`profile_gap`…). Add the `profile_timezone_change_*` + `alert_*` fields the type expects (RF-22). The bespoke `profile_*` accordion knobs should reuse `shared_*` semantics, not a parallel set. |
| `no-access`/`missing`/`not-found` | Surface styles: `web_button_variant` → `variant` (RF-14), `web_color` → `color` (RF-13) so mobile colours/variants work; `web_shadow` stays web-only (RF-16). |
| `version` | **0 author fields** → target `web` or remove after a zero-reference check (RF, platform-matrix). |

### Layout (all map to RN flexbox wrappers, not HeroUI components — mapping §6)

| Style | Verdict + specific actions |
|-------|----------------------------|
| `flex`/`group`/`stack` | Already mostly `shared_*` (`align`/`justify`/`gap`/`direction`/`wrap`) — good shared contract. `web_height`/`web_width` stay web-only. Mobile = RN `View` flexbox. |
| `grid`/`simple-grid`/`grid-column` | `web_cols`/`web_breakpoints`/`web_grid_*` stay web-only; mobile emulates with flex-wrap. Layout, not 1:1. |
| `container` | `size`; `web_px`/`web_py`/`web_fluid` web-only. Mobile `View` maxWidth. |
| `box` | Has a `content` field + spacing; thin wrapper. |
| `space` | `size` + `web_space_direction` (web-only). |
| `divider` | `web_divider_label` is translatable → un-prefix (RF-35). `orientation`/`size` good. Mobile → HeroUI `separator`. |
| `paper`/`card` | `radius`; `web_*shadow`/`web_*padding`/`web_border` web-only → consider `shared_elevation` later (RF-16). Mobile → HeroUI `surface`/`card`. |
| `card-segment` | Minimal (only `spacing`) — fine. |
| `center`/`scroll-area`/`aspect-ratio`/`background-image` | Layout wrappers; `web_*` sizing stays web-only; mobile RN equivalents (mapping §6). |
| `ref-container`/`data-container` | Structural (0 / scope-only). Same behaviour both platforms; verify `data-container` actually does something on both (mobile.md §11.2). |

### Typography (mobile = RN `Text`/`View`)

| Style | Verdict + specific actions |
|-------|----------------------------|
| `title`/`text` | `size`/`text_align` good; `web_text_*` (gradient, line-clamp, transform…) web-only — fine. |
| `highlight` | `web_highlight_highlight` (the terms) is translatable → un-prefix (RF-35). |
| `blockquote` | `cite`/`content` shared; `web_color` → `color`. |
| `code`/`kbd` | Simple; `web_code_block` web-only. Mobile mono `Text`. |
| `html-tag` | `html_tag_content` + `html_tag`; document the **mobile-supported HTML subset** (web-first). |
| `fieldset` | `label` + `web_fieldset_variant`; mobile `View`+legend. |
| `spoiler` | `web_spoiler_show_label`/`hide_label` translatable → un-prefix (RF-35); mobile collapsible. |
| `typography` | Prose wrapper, web-centric; mobile `View` fallback. |

### Media

| Style | Verdict + specific actions |
|-------|----------------------------|
| `image` | **Duplicate fields**: `img_src`+`web_image_src`, `alt`+`web_image_alt` → drop the `web_image_*` twins (RF-36). `radius`; mobile `expo-image`. |
| `video`/`audio` | `*_src`/`sources` content; mobile `expo-video`/`expo-audio`. Reconcile `video_src` vs type `sources`. |
| `figure` | caption content; mobile `View`+`Image`+`Text`. |
| `carousel` | Many `web_carousel_*` (Embla) web-only; `orientation` + behaviour (`has_controls`,`loop`,`drag_free`) shared; mobile `reanimated-carousel`. Reconcile `sources`/`id_prefix`/`has_crossfade` drift. |

### Interactive / feedback

| Style | Verdict + specific actions |
|-------|----------------------------|
| `button` | `size`/`radius`/`full_width` good; `web_color`→`color`, `web_variant`→`variant`; `web_compact`/`web_auto_contrast` web-only. `open_in_new_tab` → mobile modal (RF-20). Pro: ProgressButton/SlideButton/SocialAuth/Toggle (RF-32). |
| `link` | `open_in_new_tab` → mobile in-app browser/modal (RF-20). |
| `action-icon` | `web_color`→`color`, `web_variant`→`variant`; mobile HeroUI icon `button`. |
| `alert` | Landed: removed `value`, `web_alert_with_close_button`; `web_alert_title`→`alert_title` (RF-07/08/10). `web_color`→`color` so mobile status colour is authorable. |
| `badge`/`chip` | `web_color`→`color`, `web_variant`→`variant`. Mobile OSS `chip`/`tag-group`; **Pro `Badge`** (RF-25). |
| `avatar` | `radius`/`size`; `web_variant`→`variant`; mobile HeroUI `avatar`. |
| `indicator` | mostly `web_indicator_*` (web-only positioning) + `web_color`→`color`; mobile overlay `View`. |
| `theme-icon` | `web_color`→`color`, `web_variant`→`variant`. |
| `notification` | `web_color`→`color`; mobile HeroUI `toast`/`alert`. |
| `accordion`/`accordion-item` | Add `variant`+`multiple` so mobile configures it (RF-19); `web_accordion_*` chevron/transition web-only. Mobile HeroUI `accordion`. |
| `tabs`/`tab` | `orientation`/`radius`; `web_tabs_variant`/`web_color`→shared where semantic; mobile HeroUI `tabs`. |

### Forms / input

| Style | Verdict + specific actions |
|-------|----------------------------|
| `form-log`/`form-record` | Remove `is_log` (RF-04/05, landed). Custom forms: promote `web_buttons_*`/`web_btn_*` knobs (order/position/variant/colour) to `shared_*` so mobile renders the same form (RF-21). |
| `input`/`text-input` | `size`/`radius`; `web_variant`→`variant`; `web_left/right_icon` web-only (mobile uses HeroUI `text-field` slots → consider shared). Add `mobile_keyboard_type`/`mobile_secure_entry` (RF-04 family / mobile.md). |
| `textarea` | `web_textarea_rows`/`autosize` → portable (RN `numberOfLines`); `resize`/`variant` web-only (RF-18). |
| `select` | `web_select_searchable`/`clearable` → `shared_*` (RF-17, mobile-capable). `web_multi_select_data` is translatable options → un-prefix (RF-35). Mobile HeroUI `select`/`search-field`. |
| `radio` | `web_radio_options` translatable → un-prefix (RF-35); `orientation`/`size`; `web_radio_card`/`label_position` web-only. Mobile HeroUI `radio-group`; Pro `RadioButtonGroup`. |
| `checkbox` | `web_checkbox_labelPosition` is **camelCase** → `web_checkbox_label_position` (RF-37). Review `checkbox_value` vs `value`, `toggle_switch` vs the `switch` style. |
| `switch` | `web_switch_on_label`/`off_label` translatable → un-prefix (RF-35); `web_switch_on_value` behaviour → consider `common`. Mobile HeroUI `switch`. |
| `slider`/`range-slider` | `*_marks_values` translatable → un-prefix (RF-35); numeric min/max/step web-only-ish (could be `common`). Mobile HeroUI `slider`. |
| `datepicker` | Typo fixed (`deselect`, RF-11, landed). `web_datepicker_placeholder` translatable → un-prefix (RF-35); huge `web_datepicker_*` set stays web-only. Mobile RN date primitive; **Pro DatePicker/Calendar/…** (RF-26). |
| `combobox` | `web_combobox_options` translatable → un-prefix (RF-35); `web_combobox_searchable`/`clearable`/`creatable`/`multi_select` → `shared_*` where mobile-capable. |
| `color-input`/`color-picker` | `web_color_picker_*_label` translatable → un-prefix (RF-35); `web_color_format`/swatches web-only. Web-first; mobile custom RN colour UI. |
| `file-input` | `web_file_input_*` web-only; mobile `expo-document-picker`; keep size/ext validation. |
| `number-input` | numeric config web-only-ish; mobile HeroUI numeric `text-field`; **Pro NumberField/Stepper/Pad** (RF-29). |
| `segmented-control` | `web_segmented_control_data` translatable → un-prefix (RF-35); mobile HeroUI `tabs`; **Pro `Segment`** (RF-30). |
| `rating` | `web_color`→`color`; `web_rating_*` web-only; mobile custom stars; **Pro `Rating`** (RF-27). |
| `progress`/`progress-root`/`progress-section` | `web_tooltip_label` translatable → un-prefix (RF-35); `web_color`→`color`; mobile RN bar; **Pro ProgressBar/Circle** (RF-28). |
| `rich-text-editor` | `web_rich_text_editor_placeholder` translatable → un-prefix (RF-35); web-first (Tiptap); mobile read-only viewer v1. |
| `show-user-input` | `web_table_*` web-only; **mobile must render list/cards, not a table** (mobile.md §11.6); Pro `EmptyState` for the empty case. |

### Composite / collection / data

| Style | Verdict + specific actions |
|-------|----------------------------|
| `timeline`/`timeline-item` | `web_color`→`color`; `web_timeline_*` web-only; mobile custom RN; **Pro `Stepper`** vertical (RF-31). |
| `list`/`list-item` | `web_list_item_content` translatable → un-prefix (RF-35); `size`; `web_list_*` web-only; mobile HeroUI `list-group`. |
| `entry-list`/`entry-record`/`entry-record-delete` | Data-scope styles (`data_table`,`filter`,`scope`,`own_entries_only`). Add these to the shared types (audit §4). `entry-record-delete` confirm dialog → mobile HeroUI `dialog`; preserve delete permission. |
| `loop` | Structural repeater (`loop`,`scope`); same behaviour both platforms. |

## Related references

- [`style-field-naming-rules.md`](./style-field-naming-rules.md) — the contract this audit checks.
- [`style-platform-matrix.md`](./style-platform-matrix.md) — per-style platform + renderer.
- [`style-mobile-mapping.md`](./style-mobile-mapping.md) — semantic → Mantine + HeroUI Native.
- [`style-refactoring-recommendations.md`](./style-refactoring-recommendations.md) — the fix list.
- [`style-field-audit.generated.json`](./style-field-audit.generated.json) — machine-readable data.
