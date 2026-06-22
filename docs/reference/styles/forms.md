# Form styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 form styles (`@selfhelp/shared` `forms` category).
Last verified: 2026-06-19.
Source of truth: `src/types/styles/forms.ts`, `src/registry/styles.registry.ts`, the `admin/styles/schema` endpoint, and `src/app/components/frontend/styles/` renderers.

Form styles collect input from visitors and (for the two form containers) save
it. Read [`_conventions.md`](./_conventions.md) first; common fields and standard
Mantine cosmetic props are not repeated below.

## How forms work (developers)

- A **form container** (`form-log` or `form-record`) wraps a set of input
  styles and a submit button. On submit it gathers each input by its `name`
  and persists the values.
- `form-record` keeps **one record per user**, updated on each submit
  (`is_log = 0`). `form-log` is **append-only** — every submit adds a new row
  (`is_log = 1`).
- Each input's `name` is the data column; `value` seeds an initial value;
  `is_required` enforces presence client- and server-side.
- The `translatable` flag on text inputs marks whether the **submitted value**
  is treated as translatable content.

## How forms work (administrators)

1. Add a `form-record` (profile-like, editable) or `form-log` (survey-like, one
   entry per submit) section.
2. Drag input styles inside it (text input, select, radio, …).
3. Give every input a unique **name** — that is the column the answer is saved
   under.
4. Set the submit/cancel button labels and the success message on the form
   container.

---

## form-log

**Purpose.** Append-only form: each submit stores a **new** row.

**Administrators.** Use for surveys, journals, check-ins — anything where every submission should be kept. Set the save label, success message, and (optionally) a cancel URL/label.

**Developers.** `is_log = 1`. Renders a `<form>` over its input children; persists a new data row per submit.

**Distinctive fields.** `name` (form/table identifier), `is_log`, `btn_save_label`, `alert_success`, `alert_error`, `redirect_at_end`, `btn_cancel_url`, `btn_cancel_label`, button styling (`buttons_size`, `buttons_radius`, `buttons_variant`, `buttons_position`, `btn_save_color`, `btn_cancel_color`).

**Children.** Yes (input styles + a submit).

---

## form-record

**Purpose.** Per-user record form: keeps a **single** record, updated on each submit.

**Administrators.** Use for editable profiles/settings where the latest values replace the previous ones. Same fields as `form-log` plus a separate "update" button label/colour.

**Developers.** `is_log = 0`. On submit, updates the user's existing record (or creates it the first time).

**Distinctive fields.** All `form-log` fields, plus `btn_update_label` and `btn_update_color`.

**Children.** Yes.

---

## input

**Purpose.** A plain HTML `<input>` (no Mantine wrapper).

**Administrators.** A low-level input when you want raw control. Set the HTML `type_input` (text, hidden, number, …), `name`, `placeholder`, min/max, and required.

**Developers.** Renders a bare `<input type={type_input}>`. `translatable` marks the value as translatable.

**Distinctive fields.** `type_input`, `name`, `value`, `placeholder`, `is_required`, `min`, `max`, `disabled`, `translatable`.

**Children.** No.

---

## text-input

**Purpose.** Mantine `TextInput` — a single-line text field.

**Administrators.** The standard one-line text question. Set `label`, `name`, `placeholder`, `description` (helper text), and required.

**Developers.** Renders `<TextInput>`. Supports left/right icons and a variant. `shared_max_length` caps characters on both platforms (HTML / RN `maxLength`); the `mobile_*` knobs map to the native keyboard (`keyboardType` / `autoCapitalize` / `secureTextEntry`) and are mobile-only.

**Distinctive fields.** `label`, `name`, `value`, `placeholder`, `description`, `is_required`, `disabled`, `web_left_icon` / `web_right_icon`, `web_text_input_variant`, `shared_max_length` (web + mobile), `mobile_keyboard_type` / `mobile_auto_capitalize` / `mobile_secure_entry` (mobile-only keyboard knobs), `translatable`.

**Children.** No.

---

## textarea

**Purpose.** Mantine `Textarea` — a multi-line text field, optionally a Markdown editor.

**Administrators.** For longer free-text answers. Control the visible rows (min/max), autosize, and resize behaviour. Turn on `markdown_editor` for a Markdown-aware editor.

**Developers.** Renders `<Textarea>` (or a Markdown editor when `markdown_editor` is set). Autosize via `web_textarea_autosize` + min/max rows.

**Distinctive fields.** `label`, `name`, `value`, `placeholder`, `description`, `is_required`, `min` / `max`, `markdown_editor`, `web_textarea_autosize`, `web_textarea_min_rows` / `web_textarea_max_rows`, `web_textarea_resize`, `web_textarea_variant`, `web_left_icon` / `web_right_icon`, `shared_max_length` (web + mobile), `mobile_auto_capitalize` (mobile-only), `translatable`.

**Children.** No.

---

## rich-text-editor

**Purpose.** Tiptap rich-text editor on web; a documented-subset source editor with a live preview on mobile.

**Administrators.** For formatted long-form content (bold, lists, links). Set `label`, `name`, placeholder, and description.

**Developers.** Renders a Tiptap editor on web (`web_rich_text_editor_*` toggle bubble menu, task lists, text colour). On mobile (`components/styles/forms/RichTextEditor.tsx`) full WYSIWYG is out of scope; it edits the rich-text **source** (HTML/markdown markup) in a multiline field with a live `react-native-render-html` preview, preserving the exact submitted string so a richer editor can replace it later without a data migration.

**Distinctive fields.** `label`, `name`, `value`, `placeholder`, `description`, `is_required`, `web_rich_text_editor_variant`, `rich_text_editor_placeholder`, `web_rich_text_editor_bubble_menu`, `web_rich_text_editor_text_color`, `web_rich_text_editor_task_list`, `translatable`.

**Children.** No.

---

## select

**Purpose.** A dropdown select (HTML `select` / Mantine `Select`).

**Administrators.** A dropdown of predefined options. Provide `options` (the choices), allow multiple (`is_multiple`), live search, image options, and clearing.

**Developers.** Renders a select; `options` is a serialized option list. `max` caps multi-select count.

**Distinctive fields.** `name`, `value`, `placeholder`, `options`, `is_multiple`, `max`, `live_search`, `image_selector`, `allow_clear`, `is_required`, `disabled`. *(The unused `alt` field was unlinked in migration `Version20260622132034`; no renderer read it.)*

**Children.** No.

---

## radio

**Purpose.** Mantine `Radio` / `RadioGroup` — pick exactly one option.

**Administrators.** A single-choice question. Provide the options (`radio_options`), choose inline/orientation, and optionally render them as selectable cards.

**Developers.** Renders a `<Radio.Group>`. `web_radio_card` switches to card style; `web_use_input_wrapper` adds a label/description wrapper.

**Distinctive fields.** `label`, `name`, `value`, `description`, `is_required`, `items`, `radio_options`, `is_inline`, `web_orientation`, `web_radio_label_position`, `web_radio_variant`, `web_radio_card`, `tooltip_label` / `web_tooltip_position`, `web_use_input_wrapper`.

**Children.** No.

---

## checkbox

**Purpose.** Mantine `Checkbox` — a single on/off box.

**Administrators.** A yes/no or opt-in checkbox. Set `name`, the checked value (`checkbox_value`), label position, and required.

**Developers.** Renders `<Checkbox>`. `shared_label_position` puts the label left/right on both platforms; `web_use_input_wrapper` adds description support.

**Distinctive fields.** `label`, `name`, `value`, `checkbox_value`, `is_required`, `description`, `shared_label_position` (left/right, shared), `web_checkbox_icon`, `web_use_input_wrapper`.

**Children.** No.

---

## slider

**Purpose.** Mantine `Slider` — pick a single number on a track.

**Administrators.** A draggable scale (e.g. 0–10 rating). Set min/max/step, marks, and whether the value label is always shown.

**Developers.** Renders `<Slider>` on web. Marks come from `slider_marks_values`. On mobile (`components/styles/forms/Slider.tsx`) it renders the HeroUI Native `Slider` compound (draggable single thumb + tap-to-position) bound to the form value through `web_numeric_min` / `web_numeric_max` / `web_numeric_step`.

**Distinctive fields.** `label`, `name`, `value`, `description`, `web_numeric_min` / `web_numeric_max` / `web_numeric_step`, `slider_marks_values`, `web_slider_show_label`, `web_slider_labels_always_on`, `web_slider_inverted`, `web_slider_thumb_size`, `web_slider_required`.

**Children.** No.

---

## range-slider

**Purpose.** Mantine `RangeSlider` — pick a **range** (two handles).

**Administrators.** For "from–to" numeric ranges. Same options as `slider`, applied to two thumbs.

**Developers.** Renders `<RangeSlider>` on web. On mobile (`components/styles/forms/RangeSlider.tsx`) it renders the HeroUI Native `Slider` compound in two-thumb mode (its value supports `number[]`), serialising the pair to the canonical `"lo,hi"` form value.

**Distinctive fields.** `label`, `name`, `value`, `description`, `web_numeric_min` / `web_numeric_max` / `web_numeric_step`, `range_slider_marks_values`, `web_range_slider_show_label`, `web_range_slider_labels_always_on`, `web_range_slider_inverted`.

**Children.** No.

---

## datepicker

**Purpose.** Mantine date/time picker.

**Administrators.** Collect a date, date-range, or date+time. Choose the picker `type`, display format, min/max dates, locale, first day of week, time grid, and more.

**Developers.** Renders the appropriate Mantine date input from `web_datepicker_type`. The many `web_datepicker_*` fields map to Mantine `@mantine/dates` props.

**Distinctive fields.** `label`, `name`, `value`, `description`, `is_required`, `web_datepicker_type`, `web_datepicker_format`, `web_datepicker_locale`, `datepicker_placeholder`, `web_datepicker_min_date` / `web_datepicker_max_date`, `web_datepicker_first_day_of_week`, `web_datepicker_weekend_days`, `web_datepicker_clearable`, `web_datepicker_allow_deselect`, `web_datepicker_readonly`, `web_datepicker_with_time_grid`, `web_datepicker_consistent_weeks`, `web_datepicker_hide_outside_dates`, `web_datepicker_hide_weekends`, `web_datepicker_time_step`, `web_datepicker_time_format`, `web_datepicker_date_format`, `web_datepicker_time_grid_config`, `web_datepicker_with_seconds`.

**Children.** No.

---

## switch

**Purpose.** Mantine `Switch` — a toggle.

**Administrators.** An on/off toggle (a friendlier checkbox). Set on/off labels and the values stored for each state.

**Developers.** Renders `<Switch>`. `web_switch_on_value` / `web_switch_off_value` define the persisted values. `web_switch_with_thumb_indicator` shows a coloured dot in the thumb; `web_switch_thumb_icon` renders an optional icon inside the thumb (both web-only).

**Distinctive fields.** `label`, `name`, `value`, `description`, `is_required`, `switch_on_label` / `switch_off_label`, `web_switch_on_value` / `web_switch_off_value`, `web_label_position`, `web_use_input_wrapper`, `web_switch_with_thumb_indicator`, `web_switch_thumb_icon`.

**Children.** No.

---

## combobox

**Purpose.** Mantine `Combobox` — a searchable, optionally creatable/multi-select dropdown.

**Administrators.** A power dropdown for long option lists: searchable, can allow creating new entries, multi-select, and clearable.

**Developers.** Renders a Combobox over `combobox_options`. `web_combobox_multi_select`, `_searchable`, `_creatable`, `_clearable` toggle behaviours.

**Distinctive fields.** `label`, `name`, `value`, `placeholder`, `description`, `is_required`, `combobox_options`, `web_combobox_multi_select`, `web_combobox_searchable`, `web_combobox_creatable`, `web_combobox_clearable`, `web_combobox_separator`, `web_multi_select_max_values`.

**Children.** No.

---

## color-input

**Purpose.** Mantine `ColorInput` — a text field with a colour swatch/picker.

**Administrators.** Let users enter or pick a colour. Set the format (hex/rgb/hsl) and preset swatches.

**Developers.** Renders `<ColorInput>`. `web_color_input_swatches` is the preset list. `web_color_input_with_eye_dropper` shows the screen eye-dropper, `web_color_input_disallow_input` makes it pick-only, `web_color_input_with_preview` shows the selected-colour swatch in the field (all web-only).

**Distinctive fields.** `label`, `name`, `value`, `placeholder`, `description`, `is_required`, `web_color_format`, `web_color_input_swatches`, `web_color_input_with_eye_dropper`, `web_color_input_disallow_input`, `web_color_input_with_preview`.

**Children.** No.

---

## color-picker

**Purpose.** Mantine `ColorPicker` — an inline colour picker surface.

**Administrators.** A full colour picker (saturation/hue/alpha) shown inline or behind a button. Configure swatches per row and which sub-controls show.

**Developers.** Renders `<ColorPicker>`; `web_color_picker_as_button` collapses it behind a button.

**Distinctive fields.** `label`, `name`, `value`, `description`, `is_required`, `web_color_format`, `web_color_picker_swatches`, `web_color_picker_swatches_per_row`, `web_color_picker_with_picker`, `color_picker_saturation_label` / `_hue_label` / `_alpha_label`, `web_color_picker_as_button`, `web_color_picker_button_label`, `web_fullwidth`.

**Children.** No.

---

## file-input

**Purpose.** Mantine `FileInput` (web) / media picker (mobile) — upload files.

**Administrators.** Let users attach files. Set accepted types, max size, max count, multiple, and (web) drag-and-drop.

**Developers.** Renders `<FileInput>` on web. On mobile (`components/styles/forms/FileInput.tsx`) it uses `expo-image-picker` and enforces the `web_file_input_accept` / `web_file_input_max_size` constraints client-side before accepting the file (`_fileValidation.ts`). Always enforce server-side size/type/path validation on upload too (see the assets reference); generic non-image documents on mobile are a follow-up once `expo-document-picker` is added.

**Distinctive fields.** `label`, `name`, `description`, `placeholder`, `is_required`, `disabled`, `web_file_input_multiple`, `web_file_input_accept`, `web_file_input_clearable`, `web_file_input_max_size`, `web_file_input_max_files`, `web_file_input_drag_drop`, `web_left_icon` / `web_right_icon`.

**Children.** No.

---

## number-input

**Purpose.** Mantine `NumberInput` — a numeric field with steppers.

**Administrators.** For numbers. Set min/max/step, decimal places, and clamp behaviour.

**Developers.** Renders `<NumberInput>`. `web_number_input_prefix` / `_suffix` add currency/unit affixes; `_thousand_separator` groups thousands; `_allow_negative` permits negatives; `_hide_controls` hides the steppers (all web-only).

**Distinctive fields.** `label`, `name`, `value`, `placeholder`, `description`, `is_required`, `web_numeric_min` / `web_numeric_max` / `web_numeric_step`, `web_number_input_decimal_scale`, `web_number_input_clamp_behavior`, `web_number_input_prefix`, `web_number_input_suffix`, `web_number_input_thousand_separator`, `web_number_input_allow_negative`, `web_number_input_hide_controls`.

**Children.** No.

---

## segmented-control

**Purpose.** Mantine `SegmentedControl` — a horizontal set of mutually exclusive buttons.

**Administrators.** A compact single-choice toggle (e.g. Day/Week/Month). Provide the options in `segmented_control_data`.

**Developers.** Renders `<SegmentedControl data>`; `fullwidth` stretches across the container.

**Distinctive fields.** `label`, `name`, `value`, `description`, `is_required`, `segmented_control_data`, `web_orientation`, `fullwidth`, `readonly`, `web_segmented_control_item_border`.

**Children.** No.

---

## rating

**Purpose.** Mantine `Rating` — a star (or smiley) rating control.

**Administrators.** A star-rating question. Set the number of stars, fractional steps, and optionally smiley icons; can be read-only for display.

**Developers.** Renders `<Rating>`. `web_rating_use_smiles` switches to smiley icons.

**Distinctive fields.** `label`, `name`, `value`, `description`, `readonly`, `web_rating_count`, `web_rating_fractions`, `web_rating_use_smiles`, `web_rating_empty_icon` / `web_rating_full_icon`, `web_rating_highlight_selected_only`.

**Children.** No.

---

## progress

**Purpose.** Mantine `Progress` — a single progress bar.

**Administrators.** Show completion (e.g. survey progress). Set the `value` (0–100), colour, and striped/animated style.

**Developers.** Renders `<Progress value>`.

**Distinctive fields.** `value`, `web_progress_striped`, `web_progress_animated`, `web_progress_transition_duration`.

**Children.** No.

---

## progress-root

**Purpose.** Mantine `Progress.Root` — a container for **multiple** progress sections (a segmented bar).

**Administrators.** Use when one bar must show several coloured segments. Add `progress-section` children.

**Developers.** Renders `<Progress.Root>`; children are `progress-section`. `shared_radius` rounds the bar on both platforms (web `radius`, mobile `borderRadius`).

**Distinctive fields.** `web_progress_auto_contrast`, `shared_size`, `shared_radius` (web + mobile).

**Children.** Yes (`progress-section`).

---

## progress-section

**Purpose.** Mantine `Progress.Section` — one coloured segment inside a `progress-root`.

**Administrators.** Place inside `progress-root`. Set this segment's `value`, colour, and optional tooltip.

**Developers.** Renders `<Progress.Section value>`.

**Distinctive fields.** `value`, `label`, `web_progress_striped`, `web_progress_animated`, `tooltip_label` / `web_tooltip_position`.

**Children.** No.

---

## show-user-input

**Purpose.** Read-only **display** companion to `form-log` / `form-record`: renders a user's previously submitted entries from a data table as a Mantine Table.

**Administrators.** Drop it on a page to show back what people have submitted. Point `data_table` at the form's table and use `fields_map` to choose which columns appear and how they are labelled. Two switches control *whose* data is shown and *what* can be deleted:

- **Own Entries Only** (`own_entries_only`, default on) — each user sees only their **own** submissions. Turn it **off** to show **all** users' entries (only do this where viewing everyone's data is allowed; it is still subject to data-access permissions).
- **Delete** (`delete_entry`, default on) — shows a per-row delete button. A user may always delete **their own** record; deleting **another user's** record additionally requires the table's delete data-access permission. The confirmation dialog copy is set by the translatable `delete_modal_title` / `delete_modal_body`.

Optional table behaviour: search (`dt_searching`), sorting (`dt_sortable` + `dt_default_order_column` / `dt_default_order_dir`), pagination (`dt_paginate`), the row-count footer (`dt_info`), a CSV export button (`csv_export`), and a leading timestamp column (`show_timestamp`).

**Developers.** Renders as a Mantine Table (the style always uses the Mantine renderer). Rows come from the configured data table; when `own_entries_only = 1` the query is scoped to the current user. The own-vs-permission delete rule is **centralised** in `DataAccessSecurityService::canDeleteOwnedRecord()` so the display check (`SectionUtilityService` deciding whether to show the button) and the enforcement check (`FormController::deleteForm`) stay in lockstep: own record → always deletable; another user's record → deletable only with the data table's `delete` bit. The shared contract is `IShowUserInputStyle` / `IShowUserInputEntry` in `@selfhelp/shared` (`src/types/styles/forms.ts`); the frontend imports those types directly (no local duplicate).

**Distinctive fields.** `data_table`, `fields_map` (translatable column config), `own_entries_only`, `show_timestamp`, `delete_entry`, `csv_export`, `dt_sortable`, `dt_searching`, `dt_paginate`, `dt_info`, `dt_default_order_column`, `dt_default_order_dir`, `delete_modal_title` / `delete_modal_body` (translatable), and the Mantine Table props `web_table_striped`, `web_table_highlight_on_hover`, `web_table_with_table_border`, `web_table_with_column_borders`, `web_table_with_row_borders`, `web_table_sticky_header`, `web_table_caption_side`.

**Children.** No.

---

## Related references

- [_conventions.md](./_conventions.md) — common fields and Mantine prop conventions.
- [composite.md](./composite.md) — `entry-list` / `entry-record` that display saved form data.
- [index.md](./index.md) — full style catalog.
