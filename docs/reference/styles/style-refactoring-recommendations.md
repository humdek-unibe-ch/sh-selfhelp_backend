# Style refactoring recommendations

Audience: Developers and maintainers planning the style/field cleanup.
Status: active proposal (binding field decisions from the 2026-06-19 review + sequencing).
Applies to: backend catalog/migrations, `@selfhelp/shared`, web renderer, mobile renderer.
Last verified: 2026-06-19.
Source of truth: [`style-field-audit.md`](./style-field-audit.md) + [`style-field-audit.generated.json`](./style-field-audit.generated.json), the live DB catalog, and `mobile.md` (repo root).

> The "what we should change" list, derived from the audit. Every item is
> additive-or-migrated, pre-1.0, and must ship its Doctrine migration + shared
> type update + renderer update + tests + this-doc update together (AGENTS.md
> style + cross-repo rules). Ordered by impact ÷ risk.

> **Update 2026-06-22 — `shared_` prefix retired.** This page predates the
> field-naming unification. The `shared_*` property prefix was dropped from 47
> fields (migration `Version20260622165615`): "no prefix = both platforms", so
> the cross-platform presentation fields are now plain unprefixed `common`-scope
> names (`size`, `radius`, `color`, `spacing`, …). Read every `shared_X` below as
> the unprefixed `X`. Three names stay prefixed as reserved-name exceptions
> (`shared_height`, `shared_width`, `shared_icon`) because the bare names already
> exist as page-type fields. See [`_conventions.md`](./_conventions.md) and
> [`style-field-naming-rules.md`](./style-field-naming-rules.md).

## 0. Guiding decision: contract first, not Mantine→HeroUI

Confirmed direction (the architecture the rest of this builds on):

```
DB style  →  shared semantic contract  →  Mantine adapter (web)
                                       →  HeroUI Native / RN adapter (mobile)
```

Do **not** copy Mantine props into mobile or maintain a Mantine→HeroUI map. The
DB owns SelfHelp semantics; renderers adapt. This keeps the DB stable if Mantine
or HeroUI Native is ever replaced. (See [`style-field-naming-rules.md`](./style-field-naming-rules.md) §1.)

Two more confirmed principles from the 2026-06-19 review:

- **`use_web_style` is retired.** Web always renders the Mantine component now, so
  the per-style "use Mantine vs raw element" toggle is dead confusion. Remove it
  from every style (≈70 links).
- **Colour is semantic, not web-only.** Authors must be able to set a button/alert
  colour that applies on **mobile too** (e.g. the login button). So `web_color`
  becomes `shared_color` wherever it carries meaning, mapped per platform — see
  RF-13 and [`style-mobile-mapping.md`](./style-mobile-mapping.md) §2 (colour mapper).

## Field decision register (binding — 2026-06-19 review)

This register records the field-by-field decisions taken while reviewing
[`style-field-audit.generated.json`](./style-field-audit.generated.json). Each has
a stable id (`RF-nn`) used as the **revision tag** in migrations, `@selfhelp/shared`
CHANGELOG entries, and PR titles. Status is `pending` until the owning slice ships.
"Refs" cite the audited style(s). Nothing here is backward-compatible (pre-1.0).

> **Shipped — slice 1** (backend `Version20260619090609` + `@selfhelp/shared`
> v1.12.0 + web & mobile renderer reads): **RF-01, RF-02 (type-only), RF-03,
> RF-04, RF-05, RF-06, RF-07, RF-08, RF-10, RF-11.** Verified by
> `Version20260619090609RoundTripTest` + frontend/mobile typecheck + style tests.
>
> **Shipped — slice 2** (backend `Version20260619092612` + `@selfhelp/shared`
> v1.13.0 + web & mobile renderer reads): **RF-13** (`web_color` →
> `shared_color`, scope flips web→shared so mobile renders the colour), **RF-36**
> (image `web_image_src`/`web_image_alt` duplicate removal), **RF-37**
> (`web_checkbox_labelPosition` → `web_checkbox_label_position`). Verified by
> `Version20260619092612RoundTripTest` + frontend/mobile typecheck + 81 style /
> section-inspector tests.
>
> **Shipped — slice 3** (backend `Version20260619093723` + `@selfhelp/shared`
> v1.14.0 + web & mobile renderer reads): **RF-14** (`web_button_variant` →
> `shared_variant` on `missing`/`no-access`/`not-found`, scope flips web→shared)
> and **RF-35** (drop the `web_` prefix from every *translatable* `display = 1`
> field — `radio_options`, `combobox_options`, `segmented_control_data`,
> `slider_marks_values`, `range_slider_marks_values`, `switch_on_label`/
> `switch_off_label`, `spoiler_show_label`/`spoiler_hide_label`,
> `color_picker_saturation_label`/`_hue_label`/`_alpha_label`,
> `datepicker_placeholder`, `rich_text_editor_placeholder`, `tooltip_label`,
> `highlight_highlight`, `divider_label`, `list_item_content`,
> `multi_select_data`). `deriveFieldScope` already grouped these as `content`, so
> RF-35 is a naming-honesty cleanup (no scope change); the web-only *presentation*
> twins (e.g. `web_divider_label_position`, `web_radio_card`, `web_color_format`)
> keep their prefix. Verified by `Version20260619093723RoundTripTest` +
> frontend/mobile typecheck + 19 frontend renderer renames (81 style /
> section-inspector tests) + 7 mobile renderer renames.
>
> **Shipped — slice 4** (backend `Version20260619094535`, backend-only — the
> shared types already declare these and the renderers already read them, so no
> publish/FE/MB change): the **additive, renderer-verified** half of the DB↔type
> reconciliation. **RF-22** seeds the 8 `profile_timezone_change_*` fields (web
> `ProfileStyle` reads all eight with the exact defaults seeded). **RF-23** links
> the already-global `title` / `label_submit` / `label_code` to `two-factor-auth`
> (web reads `title`/`label_submit`; mobile reads `label_code`/`label_submit`).
> Verified by `Version20260619094535RoundTripTest` + focused PHPStan.
>
> **Shipped — slice 5** (backend `Version20260619095112` + `@selfhelp/shared`
> v1.14.1 + web & mobile renderer reads): the **design-led reconciliation tail**,
> corrected against runtime evidence rather than the register's original
> hypotheses. **RF-12**: `validate` cancel URL was a cross-renderer naming drift —
> the catalog + `form-log`/`form-record` + web `FormStyle` + mobile
> `FormUserInput` all use `btn_cancel_url`; only the `validate` type + web
> `ValidateStyle` used the divergent `cancel_url` (so its cancel button never
> resolved a URL). Renamed `IValidateStyle.cancel_url` → `btn_cancel_url`; web
> `ValidateStyle` now reads it (no migration — the catalog already had
> `btn_cancel_url` on `validate`). Dropped the stale `IValidateStyle` fields
> `label_login` / `success` / `page_keyword` / `value_name` (not in the catalog,
> read by no renderer; `validate` is a web-only activation surface that reads
> `success_title`, not `success`). **RF-22 tail**: dropped the stale
> `IProfileStyle` `alert_fail` / `alert_del_fail` / `alert_del_success` /
> `alert_success` (read by no renderer — `profile` uses the per-section
> `profile_*_success` / `profile_*_error_general` copy). **RF-23 tail**:
> `two-factor-auth` heading unified on `title` (slice 4 seeded it; mobile
> `TwoFactorAuth` now reads `title`, not the divergent `label_title`); the legacy
> DB `label` link on `two-factor-auth` (read by no renderer, superseded by
> `title`) is dropped by the migration. Verified by
> `Version20260619095112RoundTripTest` + focused PHPStan + frontend `tsc` +
> mobile `tsc` + 146 shared tests.
>
> **Shipped — slice 6** (backend `Version20260619095732` + `@selfhelp/shared`
> v1.14.2 + web & mobile renderer reads): **mobile configurability**, promoting
> behaviour knobs the DB carried as `web_*` that map cleanly to both platforms.
> **RF-17** (`select`): `web_select_searchable` → `shared_searchable`,
> `web_select_clearable` → `shared_clearable` (web `SelectStyle` now reads them
> instead of hard-coding `searchable`/`clearable`). **RF-18** (`textarea`):
> `web_textarea_autosize`/`_min_rows`/`_max_rows`/`_rows` → `shared_autosize` /
> `shared_min_rows` / `shared_max_rows` / `shared_rows` (web reads the new names;
> mobile `Textarea` maps `shared_min_rows` → RN `numberOfLines`); `resize` /
> `variant` stay `web_` (no RN peer). **RF-19** (`accordion`):
> `web_accordion_multiple` → `shared_multiple` (web + mobile read it);
> `web_accordion_variant` stays `web_` — its option domain
> (`default`/`contained`/`filled`/`separated`) is Mantine-specific and does not
> match the generic `shared_variant` domain, so it is **not** merged. **RF-20**
> (`button`/`link` `open_in_new_tab`): already unprefixed `common` and already
> read by both renderers — **no change needed** (mobile already interprets it as
> in-app browser vs modal). Verified by `Version20260619095732RoundTripTest` +
> focused PHPStan + frontend `tsc` + mobile `tsc` + shared tests.
>
> **Shipped — slice 7** (backend `Version20260619100044` + `@selfhelp/shared`
> v1.14.3 + web & mobile renderer reads): **RF-21** — the form/validate button
> knobs are now semantic so the **mobile custom form** matches the web Mantine
> form. Renamed on `form-log`/`form-record`/`validate`:
> `web_buttons_size`/`_radius`/`_variant`/`_position`/`_order` → `shared_buttons_*`,
> `web_btn_save_color`/`web_btn_cancel_color` →
> `shared_btn_save_color`/`shared_btn_cancel_color`, and `web_btn_update_color` →
> `shared_btn_update_color` (`form-record`). This also **fixed a latent web bug**:
> `FormStyle.tsx` was reading the unprefixed `buttons_*`/`btn_*_color` names
> (which never existed in the catalog), so every form silently fell back to
> defaults; it now reads the `shared_*` names that exist. Mobile `FormUserInput`
> now builds its action row from the same `shared_*` knobs (order/position/size/
> radius/variant + save/cancel colours). Pure web cosmetics (`web_card_*`,
> `web_buttons_spacing`) stay `web_`. Verified by
> `Version20260619100044RoundTripTest` + focused PHPStan + frontend `tsc` +
> mobile `tsc` + shared tests.
>
> **Shipped — slice 8** (mobile + docs only — **no DB / shared / web change**, by
> design): **RF-25–34** HeroUI Native **Pro** marking + OSS fallback
> verification. Per the decision to defer the Pro implementation, each
> Pro-eligible mobile OSS renderer is now annotated with its Pro override and the
> `@selfhelp/mobile-pro-ui` adapter seam, and its OSS fallback was verified to
> render from the same CMS fields: `Badge` (RF-25), `DatePicker` (RF-26),
> `Rating` (RF-27), `Progress`/`ProgressRoot`/`ProgressSection` (RF-28),
> `NumberInput` (RF-29), `SegmentedControl` (RF-30), `Timeline`/`TimelineItem`
> (RF-31), `Button` (RF-32 — special-button overrides opt-in via
> `shared_variant`/dedicated styles), `RangeSlider` (RF-34), and `Radio`
> (`RadioButtonGroup`). **RF-33** (chart styles) has no CMS style yet —
> documented as future in [`style-mobile-mapping.md`](./style-mobile-mapping.md)
> §8, nothing to mark. The Pro components themselves are **not** implemented; the
> OSS fallbacks remain the shipped rendering for every install. No migration (no
> schema/field change), no shared bump (no contract change), so no
> `release-manifest.json` floor move.
>
> **Shipped — slice 9** (backend `Version20260619100642` + `@selfhelp/shared`
> v1.14.4 + web & mobile renderer reads): **RF-15** — merged the legacy
> margin-only `web_spacing_margin` (field type `spacing-margin`) into the
> portable box-model `shared_spacing` (type `spacing`). Both stored the **same**
> box-model JSON and were mutually exclusive at the style level (0 styles linked
> both), so the migration is a value-preserving repoint: the 39 margin-only
> style links + their authored section values move onto `shared_spacing` (editor
> label/help upgraded "margin" → "margin and padding"), and the now-orphaned
> field + its `spacing-margin` type are dropped. This gives those 39 styles full
> portable margin+padding on **both** platforms. It also **fixed a mobile bug**:
> mobile `buildSectionClasses` read the non-existent `web_spacing_margin_padding`
> and the margin-only `web_spacing_margin`, so the 37 styles already on
> `shared_spacing` got **no** mobile spacing; it now reads `shared_spacing` first
> (`web_spacing_margin` kept only as a transitional fallback). Shared
> `IStyleWithSpacing` drops `web_spacing_margin`; web `BasicStyle` drops its
> fallback. The CMS spacing editor's generic `spacing-margin` mode is left intact
> (harmless, type-driven). Verified by `Version20260619100642RoundTripTest` +
> focused PHPStan + frontend `tsc` + mobile `tsc`/tests + shared tests.
>
> **Resolved notes:** `validate.label_timezone` exists in the catalog but no
> renderer reads it yet (first-login timezone selection is a documented renderer
> gap, NOT a type field — intentionally not added to `@selfhelp/shared` until a
> renderer consumes it). `page_keyword` / `value_name` are not `validate` catalog
> fields and are read by no renderer there; the `button`/`action-icon`
> `page_keyword` is a different style's field and is untouched.
>
> All slice 1–9 migrations are backend-safe (backend `src/` reads none of these
> fields) and reversible. After the team runs them, regenerate
> `style-field-audit.generated.json` (it still lists the pre-merge catalog).
> **All planned RF waves (RF-01–37) are now shipped** — the Pro components
> (RF-25–34) are marked + fall back to OSS, pending the future `mobile-pro-ui`
> build.

### A. Remove — dead / leftover (no replacement)

| id | Style(s) | Field(s) | Reason | Migration note |
|----|----------|----------|--------|----------------|
| RF-01 | **all** (~70) | `use_web_style` | Web always uses Mantine now; mobile never read it. | Delete the `fields` row + all `rel_fields_styles` links. `down()` re-seeds the field and relinks the captured style list with default `1`. |
| RF-02 | `login` (+ other auth) | shared-type `type` field | Stale in `ILoginStyle` etc.; not in DB. | `@selfhelp/shared` only — drop from the interface. |
| RF-03 | `register` | `label_security_question_1`, `label_security_question_2` | Security questions belonged to the removed anonymous-registration flow. | Delete field rows + links + their translations. |
| RF-04 | `form-log` | `is_log` | Log vs record is decided by the **style** now; always `1` here. | Delete field + links. |
| RF-05 | `form-record` | `is_log` | Same — always `0` here; style decides. | Delete field + links. |
| RF-06 | `reset-password` | `subject_user`, `is_html` | Email is sent from mail templates now, not this style. | Delete field rows + links (+ check for `subject_admin`/body twins on sibling auth styles). |
| RF-07 | `alert` | `value` | Duplicates the translatable `content` mobile already reads. | Delete field + links. |
| RF-08 | `alert` | `web_alert_with_close_button` | Duplicate toggle of `web_with_close_button`. | Delete field + links. |
| RF-36 | `image` | `web_image_src`, `web_image_alt` | Duplicate the existing `img_src` / `alt` content fields. | Unlink/delete the `web_image_*` twins (keep `img_src`/`alt`). |

> Sweep task (part of RF-06/RF-09): grep every style for legacy email-send fields
> (`subject_user`, `subject_admin`, `is_html`, mail body copy) and the legacy
> single-field auth copies superseded by the `reset_*` set
> (`reset-password.label_pw_reset` / `alert_success` / `placeholder`); confirm zero
> content references, then remove. (RF-09)

### B. Rename / fix

| id | Style | Change | Reason |
|----|-------|--------|--------|
| RF-10 | `alert` | `web_alert_title` → `alert_title` (stay `display = 1`) | Title is translatable copy, not web presentation; both platforms read it; removes the mobile web→mobile leak. **Web renderer must switch to `alert_title` in the same wave** (mobile already prefers it). |
| RF-11 | `datepicker` | `web_datepicker_allow_deseselect` → `web_datepicker_allow_deselect` | Typo; the shared type already expects the correct spelling. |
| RF-35 | many (see eval) | un-prefix every **translatable** (`display = 1`) `web_*` field → unprefixed | Copy/options are shared, not web-only. Mobile needs the same options/labels. Covers `web_radio_options`, `web_combobox_options`, `web_segmented_control_data`, `web_multi_select_data`, `web_slider_marks_values`, `web_range_slider_marks_values`, `web_switch_on_label`/`off_label`, `web_divider_label`, `web_spoiler_show_label`/`hide_label`, `web_color_picker_*_label`, `web_datepicker_placeholder`, `web_rich_text_editor_placeholder`, `web_highlight_highlight`, `web_list_item_content`, `web_tooltip_label`, … (same class as RF-10). |
| RF-37 | `checkbox` | `web_checkbox_labelPosition` → `web_checkbox_label_position` | camelCase violates the `snake_case` field-name rule. Also review `checkbox_value` vs `value` and `toggle_switch` (overlaps the `switch` style). |

### C. Make semantic (`web_*` → `shared_*`) so mobile can use it

| id | Style(s) | Field | Becomes | Mapping |
|----|----------|-------|---------|---------|
| RF-13 | `login`, `register`, `no-access`, `missing`, `not-found`, `alert`, … (~32) | `web_color` | `shared_color` | semantic colour token → Mantine `color` + HeroUI Native colour ([mapping](./style-mobile-mapping.md) §2). Keep a `web_color` escape hatch only where an exact non-semantic Mantine palette value is required. |
| RF-14 | `no-access`, `missing`, `not-found` | `web_button_variant` | `shared_variant` | semantic variant → Mantine `variant` + HeroUI button variant. |
| RF-15 | ~39 styles | `web_spacing_margin` | `shared_spacing` | box-model merge (mobile already understands `shared_spacing`). Lower priority — a spacing-capable wrapper style is an acceptable interim. |
| RF-16 | surfaces | `web_shadow` | *stay `web_`* (+ optional `shared_elevation` later) | RN elevation ≠ CSS shadow; do not force-promote. |

### D. Mobile configurability — answers to the review questions

| id | Style | Field(s) | Answer / decision |
|----|-------|----------|-------------------|
| RF-17 | `select` | `web_select_searchable`, `web_select_clearable` | **Possible on mobile.** HeroUI Native does clearable; "searchable" maps to `search-field`/`combobox`. Promote to `shared_searchable` / `shared_clearable` (behaviour, both platforms). |
| RF-18 | `textarea` | `web_textarea_*` | `autosize`/`min_rows`/`max_rows`/`rows` → portable: promote rows to a shared concept mapped to RN `numberOfLines`/autosize. `resize`/`variant` stay `web_` (no RN peer). |
| RF-19 | `accordion` | (none today) | HeroUI Native **does** configure accordion. Add `shared_variant` + `shared_multiple` (selection mode), read by both. |
| RF-20 | `button`, `link` | `open_in_new_tab` | Keep unprefixed (`common`). Mobile interprets it as "open in in-app modal / browser". Optionally generalise to `shared_link_target` (`self` \| `new` \| `modal`). |
| RF-21 | `validate`, `form-log`, `form-record` | `web_buttons_*`, `web_btn_*`, `web_card_*` | These are **custom composite** styles (not 1:1 component maps). Mobile must build them. Promote the meaningful knobs (button order/position/variant, colours→`shared_color`) to `shared_*` so the mobile custom form matches; leave pure web cosmetics `web_`. |

### E. Keep — add to DB (shared type is ahead of the catalog; needed)

| id | Style | Fields | Action |
|----|-------|--------|--------|
| RF-12 | `validate` | type adds `label_login`, `success`, `page_keyword`, `value_name`, `cancel_url`; DB adds `label_timezone`, `btn_cancel_url` | Reconcile both directions per field (seed in DB or drop from type); `label_timezone` confirms `validate` carries first-login timezone selection. |
| RF-22 | `profile` | `profile_timezone_change_*` (8), `alert_fail`, `alert_del_fail`, `alert_del_success` | **Needed.** Seed the missing field rows + links (+ default copy) so DB matches the type. |
| RF-23 | `two-factor-auth` | `label_code`, `label_submit`, `title` (add to DB); `label` (add to type) | Reconcile both directions — they are used. |

### F. Keep — backend-only (never rendered)

| id | Style | Field | Note |
|----|-------|-------|------|
| RF-24 | `register` | `group` | Determines which group a new user is assigned at registration. Backend concern only; both renderers ignore it. Document scope as backend/common. |

### G. HeroUI Native **Pro** component adoption (mobile Pro build)

Where a Pro component exists, the mobile **Pro** build uses it directly; the OSS
build keeps the adapted fallback. The contract is
`effective adapter = OSS adapter + Pro override` (mobile-mapping §3/§4) — same CMS
fields, richer native rendering for licensed installs. See the Pro catalog +
per-style mapping in [`style-mobile-mapping.md`](./style-mobile-mapping.md) §8.

| id | CMS style | OSS fallback | Pro override |
|----|-----------|--------------|--------------|
| RF-25 | `badge` | chip / `tag-group` | **Badge** |
| RF-26 | `datepicker` | RN date primitive | **DatePicker / Calendar / DateField / DateRangePicker / DateTimePicker / TimePicker / Wheel\*** |
| RF-27 | `rating` | custom stars | **Rating** |
| RF-28 | `progress`, `progress-root`, `progress-section` | RN bar | **ProgressBar / ProgressCircle** |
| RF-29 | `number-input` | numeric `text-field` | **NumberField / NumberStepper / NumberPad** |
| RF-30 | `segmented-control` | `tabs` | **Segment** |
| RF-31 | `timeline`, `timeline-item` | custom RN | **Stepper** (vertical) |
| RF-32 | `button` (special) | `button` | **ProgressButton / SlideButton / SocialAuthButton / ToggleButton(+Group)** via `shared_variant` or dedicated styles later |
| RF-33 | future data/chart styles | — | **AreaChart / BarChart / LineChart / PieChart / RadarChart / RadialChart / ComposedChart** (+ Crosshair/Indicator/Tooltip) |
| RF-34 | `range-slider`, future steppers | `slider` (range) | **Stepper / WheelPicker** family where it fits |

### Revision tags & changelog plan

- Each `RF-nn` is the revision tag. A slice's backend migration description, the
  `@selfhelp/shared` CHANGELOG heading, and the frontend/mobile PR all cite the
  same id(s).
- Cross-repo coupling: every slice that adds/renames/removes a field read by a
  renderer bumps `@selfhelp/shared` (minor pre-1.0) and raises the
  `release-manifest.json` `supports.*` floors per
  [`cross-repo-compatibility-matrix.md`](../../developer/cross-repo-compatibility-matrix.md).
- Order of execution is in "Suggested sequencing" below; the original sections
  §1–§8 give the detailed mechanics each `RF-nn` plugs into.

## 1. Fix the concrete field bugs (low risk, do first)

Each is a focused migration generated with `php bin/console make:migration`,
migrating stored values by `id_fields` (never by name), with a round-trip test.

| # | Style | Change | Why |
|---|-------|--------|-----|
| 1.1 | `alert` | **remove `value`** | duplicate of translatable `content`; mobile already reads `content` |
| 1.2 | `alert` | **remove `web_alert_with_close_button`** | duplicate of `web_with_close_button` |
| 1.3 | `alert` | **rename `web_alert_title` → `alert_title`** (keep `display = 1`) | title is translatable copy, not web presentation; removes the mobile web→mobile leak; mobile renderer already prefers `alert_title` |
| 1.4 | `datepicker` | **rename `web_datepicker_allow_deseselect` → `web_datepicker_allow_deselect`** | DB typo; the shared type already expects the correct spelling |

Acceptance: migration round-trip test green; web + mobile alert render unchanged;
`scripts/build-style-audit.php` shows the duplicates/typo gone.

## 2. Reconcile `@selfhelp/shared` types to the DB (cross-repo contract)

The shared types drifted from the DB (audit §4). For every style in the drift
tables, make the `I<Name>Style` interface match the DB field set exactly, or
extend the DB if a field is genuinely planned. Priority styles:

- **`profile`** — the interface declares ~12 fields not in the DB
  (`profile_timezone_change_*`, `alert_*`). Decide per field: seed it in the DB
  (migration) or drop it from the type. No half-states.
- **`alert`** — drop `close_button_label` from the type (not in DB) or add it to
  the DB; align with the §1 cleanup.
- **`image` / `video` / `carousel`** — reconcile `web_image_src`/`web_image_alt`
  vs `height`/`width`/`sources`/`video_src`/`id_prefix`/`has_crossfade`.
- **`select`** — reconcile `web_multi_select_data` (DB) vs
  `alt`/`live_search`/`image_selector`/`allow_clear` (type).
- **`form-log` / `form-record`** — add the many real DB fields (`btn_*`,
  `web_buttons_*`, `is_log`, `redirect_at_end`, …) to the types.
- **`entry-list` / `entry-record` / `entry-record-delete`** — add
  `data_table`, `filter`, `scope`, `own_entries_only`, `type`. (`url_param` was
  removed pre-1.0; detail scoping uses explicit `filter` with
  `{{route.record_id}}` only.)

This is a `@selfhelp/shared` change → bump the package and the consumer ranges,
and update the cross-repo compatibility floors (AGENTS.md cross-repo rule).

## 3. Consolidate spacing → `shared_spacing`

> **Shipped — slice 9 (RF-15).** Implemented as a value-preserving repoint (both
> fields stored the same box-model JSON, zero style-level overlap): the 39
> `web_spacing_margin` links + their section values moved onto `shared_spacing`,
> and the `web_spacing_margin` field + `spacing-margin` type were dropped. Mobile
> `buildSectionClasses` now reads `shared_spacing` first. See the slice-9 note in
> the decision register above.

39 styles still use legacy `web_spacing_margin`; 37 use portable `shared_spacing`
(audit §3). Mobile only understands `shared_spacing`, so the 39 have no portable
spacing.

Plan (per `mobile.md` §7.4):

1. For each style with `web_spacing_margin`, migrate the stored margin values into
   the box-model `shared_spacing` JSON (`mt/mb/ms/me` set, padding empty).
2. Repoint the style-field link to `shared_spacing`.
3. Keep `web_*` spacing only where the value uses CSS/Mantine semantics that
   `shared_spacing` cannot express (rare).
4. Remove the obsolete `web_spacing_margin` link **after** value migration is
   verified by a round-trip test.

Highest-value because it is the most common portability gap and `shared_spacing`
already maps cleanly to both platforms.

## 4. Introduce `mobile_*` fields where (and only where) needed

There are **0 `mobile_*` fields** today. Add them sparingly, only for genuine
native presentation that has no shared meaning:

- `text-input` / `textarea` / `number-input`: `mobile_keyboard_type`,
  `mobile_secure_entry`, `mobile_auto_capitalize`.
- `button` / `action-icon`: `mobile_haptic`.
- inputs generally: `mobile_return_key`, `mobile_content_type`.

Do **not** add a `mobile_*` field that merely mirrors a `shared_*` or content
field (the anti-pattern the `alert.value` duplicate illustrates). Each new
`mobile_*` field ships its mobile editor control (mobile.md §12.3) and renderer
read.

## 5. Translatable content stays shared/unprefixed

Audit the catalog for any `display = 1` field carrying a `web_`/`mobile_` prefix
(today: `alert.web_alert_title`). Rename them to unprefixed content so both
platforms read the same authored copy. Reserve `mobile_<copy>` for the rare case
where mobile needs genuinely different wording.

Recommended canonical content names to standardise on (where a style needs them):
`title`, `label`, `content`, `description`, `placeholder`, `helper_text`,
`error_text`.

## 6. Assign deliberate render targets

All 90 styles are `both`. Keep that default; make only justified exceptions by
writing `styles.id_render_target` (mechanism: `Version20260618143215`):

- **`version`** → `web` (or remove if unreferenced): build/diagnostic surface, 0
  author fields. Run a content-reference check first.
- **Web-first styles** (`html-tag`, `typography`, `rich-text-editor`,
  `color-picker`, `color-input`, `entry-table`) → stay `both` **with a
  documented mobile fallback** (read-only viewer, RN list/cards, RN color UI).
  Do not mark them `web` just because the mobile renderer is unfinished.
- **`entry-table`** (ex `show-user-input`) → mobile must render a **list/card** view, never a copied
  desktop table (mobile.md §11.6).
- Layout styles stay `both`: they map to RN flexbox (see mobile mapping §6); they
  are not web-only even though they look web-shaped.

## 7. Tag every field with a lifecycle status

Adopt the lifecycle vocabulary ([naming rules](./style-field-naming-rules.md) §4)
as a first-class part of the audit so the catalog is *cleaned*, not just
described. Extend `scripts/build-style-audit.php` to emit a `status` per field
(it already detects duplicates and computes scope) and a
content-reference count per style/field, so `unused`/`candidate-for-removal` can
be decided safely.

## 8. Don't re-add the 16 deferred styles

`dialog`, `popover`, `menu`, `menu-item`, `bottom-sheet`, `skeleton`,
`skeleton-group`, `spinner`, `toast`, `tag-group`, `tag`, `input-group`,
`input-otp`, `search-field`, `fab-button`, `biometric-login-button` are HeroUI
Native components but **not** CMS styles (mobile.md §4.6/§11.8). Use them inside
app workflows; do not seed them as author-selectable styles without a concrete
authoring use case (+ fields, targets, behaviour, fallback, docs, tests).

## Suggested sequencing

1. **§1** field bug fixes (alert ×3, datepicker typo) — isolated, low risk.
2. **§3** spacing consolidation — biggest portability win.
3. **§2** shared-type reconciliation — unblocks mobile + fixes the FE contract.
4. **§6** render-target decisions (start with `version`).
5. **§4** add `mobile_*` fields as mobile renderers are built (mobile.md Phase 5).
6. **§5 / §7** content un-prefixing + lifecycle tagging — ongoing during the above.

Each step keeps the backend gates green (`composer fresh-test-install`, focused
tests, `composer validate-db`, `composer phpstan`) and updates the matching
category page + this audit.

## Related references

- [`style-field-audit.md`](./style-field-audit.md) — the findings these fixes address.
- [`style-field-naming-rules.md`](./style-field-naming-rules.md) — naming + lifecycle contract.
- [`style-platform-matrix.md`](./style-platform-matrix.md) — render-target decisions.
- [`style-mobile-mapping.md`](./style-mobile-mapping.md) — renderer adapter mapping.
- `mobile.md` (repo root) — the full implementation plan and phase gates.
