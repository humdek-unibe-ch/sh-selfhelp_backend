# Form styles

Audience: Developers and CMS administrators.
Status: active.
Applies to: SelfHelp2 form styles (`@selfhelp/shared` `forms` category).
Last verified: 2026-06-16.
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

**Developers.** Renders `<TextInput>`. Supports left/right icons and a variant.

**Distinctive fields.** `label`, `name`, `value`, `placeholder`, `description`, `is_required`, `disabled`, `mantine_left_icon` / `mantine_right_icon`, `mantine_text_input_variant`, `translatable`.

**Children.** No.

---

## textarea

**Purpose.** Mantine `Textarea` — a multi-line text field, optionally a Markdown editor.

**Administrators.** For longer free-text answers. Control the visible rows (min/max), autosize, and resize behaviour. Turn on `markdown_editor` for a Markdown-aware editor.

**Developers.** Renders `<Textarea>` (or a Markdown editor when `markdown_editor` is set). Autosize via `mantine_textarea_autosize` + min/max rows.

**Distinctive fields.** `label`, `name`, `value`, `placeholder`, `description`, `is_required`, `min` / `max`, `markdown_editor`, `mantine_textarea_autosize`, `mantine_textarea_min_rows` / `mantine_textarea_max_rows`, `mantine_textarea_resize`, `mantine_textarea_variant`, `mantine_left_icon` / `mantine_right_icon`, `translatable`.

**Children.** No.

---

## rich-text-editor

**Purpose.** Tiptap rich-text editor on web; a **read-only** viewer on mobile (v1).

**Administrators.** For formatted long-form content (bold, lists, links). Set `label`, `name`, placeholder, and description.

**Developers.** Renders a Tiptap editor (`mantine_rich_text_editor_*` toggle bubble menu, task lists, text colour). On mobile it renders read-only.

**Distinctive fields.** `label`, `name`, `value`, `placeholder`, `description`, `is_required`, `mantine_rich_text_editor_variant`, `mantine_rich_text_editor_placeholder`, `mantine_rich_text_editor_bubble_menu`, `mantine_rich_text_editor_text_color`, `mantine_rich_text_editor_task_list`, `translatable`.

**Children.** No.

---

## select

**Purpose.** A dropdown select (HTML `select` / Mantine `Select`).

**Administrators.** A dropdown of predefined options. Provide `options` (the choices), allow multiple (`is_multiple`), live search, image options, and clearing.

**Developers.** Renders a select; `options` is a serialized option list. `max` caps multi-select count.

**Distinctive fields.** `name`, `value`, `placeholder`, `options`, `is_multiple`, `max`, `live_search`, `image_selector`, `allow_clear`, `is_required`, `disabled`, `alt`.

**Children.** No.

---

## radio

**Purpose.** Mantine `Radio` / `RadioGroup` — pick exactly one option.

**Administrators.** A single-choice question. Provide the options (`mantine_radio_options`), choose inline/orientation, and optionally render them as selectable cards.

**Developers.** Renders a `<Radio.Group>`. `mantine_radio_card` switches to card style; `mantine_use_input_wrapper` adds a label/description wrapper.

**Distinctive fields.** `label`, `name`, `value`, `description`, `is_required`, `items`, `mantine_radio_options`, `is_inline`, `mantine_orientation`, `mantine_radio_label_position`, `mantine_radio_variant`, `mantine_radio_card`, `mantine_tooltip_label` / `mantine_tooltip_position`, `mantine_use_input_wrapper`.

**Children.** No.

---

## checkbox

**Purpose.** Mantine `Checkbox` — a single on/off box.

**Administrators.** A yes/no or opt-in checkbox. Set `name`, the checked value (`checkbox_value`), label position, and required.

**Developers.** Renders `<Checkbox>`. `mantine_checkbox_labelPosition` puts the label left/right; `mantine_use_input_wrapper` adds description support.

**Distinctive fields.** `label`, `name`, `value`, `checkbox_value`, `is_required`, `description`, `mantine_checkbox_icon`, `mantine_checkbox_labelPosition`, `mantine_use_input_wrapper`.

**Children.** No.

---

## slider

**Purpose.** Mantine `Slider` — pick a single number on a track.

**Administrators.** A draggable scale (e.g. 0–10 rating). Set min/max/step, marks, and whether the value label is always shown.

**Developers.** Renders `<Slider>`. Marks come from `mantine_slider_marks_values`.

**Distinctive fields.** `label`, `name`, `value`, `description`, `mantine_numeric_min` / `mantine_numeric_max` / `mantine_numeric_step`, `mantine_slider_marks_values`, `mantine_slider_show_label`, `mantine_slider_labels_always_on`, `mantine_slider_inverted`, `mantine_slider_thumb_size`, `mantine_slider_required`.

**Children.** No.

---

## range-slider

**Purpose.** Mantine `RangeSlider` — pick a **range** (two handles).

**Administrators.** For "from–to" numeric ranges. Same options as `slider`, applied to two thumbs.

**Developers.** Renders `<RangeSlider>`.

**Distinctive fields.** `label`, `name`, `value`, `description`, `mantine_numeric_min` / `mantine_numeric_max` / `mantine_numeric_step`, `mantine_range_slider_marks_values`, `mantine_range_slider_show_label`, `mantine_range_slider_labels_always_on`, `mantine_range_slider_inverted`.

**Children.** No.

---

## datepicker

**Purpose.** Mantine date/time picker.

**Administrators.** Collect a date, date-range, or date+time. Choose the picker `type`, display format, min/max dates, locale, first day of week, time grid, and more.

**Developers.** Renders the appropriate Mantine date input from `mantine_datepicker_type`. The many `mantine_datepicker_*` fields map to Mantine `@mantine/dates` props.

**Distinctive fields.** `label`, `name`, `value`, `description`, `is_required`, `mantine_datepicker_type`, `mantine_datepicker_format`, `mantine_datepicker_locale`, `mantine_datepicker_placeholder`, `mantine_datepicker_min_date` / `mantine_datepicker_max_date`, `mantine_datepicker_first_day_of_week`, `mantine_datepicker_weekend_days`, `mantine_datepicker_clearable`, `mantine_datepicker_allow_deselect`, `mantine_datepicker_readonly`, `mantine_datepicker_with_time_grid`, `mantine_datepicker_consistent_weeks`, `mantine_datepicker_hide_outside_dates`, `mantine_datepicker_hide_weekends`, `mantine_datepicker_time_step`, `mantine_datepicker_time_format`, `mantine_datepicker_date_format`, `mantine_datepicker_time_grid_config`, `mantine_datepicker_with_seconds`.

**Children.** No.

---

## switch

**Purpose.** Mantine `Switch` — a toggle.

**Administrators.** An on/off toggle (a friendlier checkbox). Set on/off labels and the values stored for each state.

**Developers.** Renders `<Switch>`. `mantine_switch_on_value` / `mantine_switch_off_value` define the persisted values.

**Distinctive fields.** `label`, `name`, `value`, `description`, `is_required`, `mantine_switch_on_label` / `mantine_switch_off_label`, `mantine_switch_on_value` / `mantine_switch_off_value`, `mantine_label_position`, `mantine_use_input_wrapper`.

**Children.** No.

---

## combobox

**Purpose.** Mantine `Combobox` — a searchable, optionally creatable/multi-select dropdown.

**Administrators.** A power dropdown for long option lists: searchable, can allow creating new entries, multi-select, and clearable.

**Developers.** Renders a Combobox over `mantine_combobox_options`. `mantine_combobox_multi_select`, `_searchable`, `_creatable`, `_clearable` toggle behaviours.

**Distinctive fields.** `label`, `name`, `value`, `placeholder`, `description`, `is_required`, `mantine_combobox_options`, `mantine_combobox_multi_select`, `mantine_combobox_searchable`, `mantine_combobox_creatable`, `mantine_combobox_clearable`, `mantine_combobox_separator`, `mantine_multi_select_max_values`.

**Children.** No.

---

## color-input

**Purpose.** Mantine `ColorInput` — a text field with a colour swatch/picker.

**Administrators.** Let users enter or pick a colour. Set the format (hex/rgb/hsl) and preset swatches.

**Developers.** Renders `<ColorInput>`. `mantine_color_input_swatches` is the preset list.

**Distinctive fields.** `label`, `name`, `value`, `placeholder`, `description`, `is_required`, `mantine_color_format`, `mantine_color_input_swatches`.

**Children.** No.

---

## color-picker

**Purpose.** Mantine `ColorPicker` — an inline colour picker surface.

**Administrators.** A full colour picker (saturation/hue/alpha) shown inline or behind a button. Configure swatches per row and which sub-controls show.

**Developers.** Renders `<ColorPicker>`; `mantine_color_picker_as_button` collapses it behind a button.

**Distinctive fields.** `label`, `name`, `value`, `description`, `is_required`, `mantine_color_format`, `mantine_color_picker_swatches`, `mantine_color_picker_swatches_per_row`, `mantine_color_picker_with_picker`, `mantine_color_picker_saturation_label` / `_hue_label` / `_alpha_label`, `mantine_color_picker_as_button`, `mantine_color_picker_button_label`, `mantine_fullwidth`.

**Children.** No.

---

## file-input

**Purpose.** Mantine `FileInput` (web) / DocumentPicker (mobile) — upload files.

**Administrators.** Let users attach files. Set accepted types, max size, max count, multiple, and (web) drag-and-drop.

**Developers.** Renders `<FileInput>`. Enforce server-side size/type/path validation on upload (see the assets reference).

**Distinctive fields.** `label`, `name`, `description`, `placeholder`, `is_required`, `disabled`, `mantine_file_input_multiple`, `mantine_file_input_accept`, `mantine_file_input_clearable`, `mantine_file_input_max_size`, `mantine_file_input_max_files`, `mantine_file_input_drag_drop`, `mantine_left_icon` / `mantine_right_icon`.

**Children.** No.

---

## number-input

**Purpose.** Mantine `NumberInput` — a numeric field with steppers.

**Administrators.** For numbers. Set min/max/step, decimal places, and clamp behaviour.

**Developers.** Renders `<NumberInput>`.

**Distinctive fields.** `label`, `name`, `value`, `placeholder`, `description`, `is_required`, `mantine_numeric_min` / `mantine_numeric_max` / `mantine_numeric_step`, `mantine_number_input_decimal_scale`, `mantine_number_input_clamp_behavior`.

**Children.** No.

---

## segmented-control

**Purpose.** Mantine `SegmentedControl` — a horizontal set of mutually exclusive buttons.

**Administrators.** A compact single-choice toggle (e.g. Day/Week/Month). Provide the options in `mantine_segmented_control_data`.

**Developers.** Renders `<SegmentedControl data>`; `fullwidth` stretches across the container.

**Distinctive fields.** `label`, `name`, `value`, `description`, `is_required`, `mantine_segmented_control_data`, `mantine_orientation`, `fullwidth`, `readonly`, `mantine_segmented_control_item_border`.

**Children.** No.

---

## rating

**Purpose.** Mantine `Rating` — a star (or smiley) rating control.

**Administrators.** A star-rating question. Set the number of stars, fractional steps, and optionally smiley icons; can be read-only for display.

**Developers.** Renders `<Rating>`. `mantine_rating_use_smiles` switches to smiley icons.

**Distinctive fields.** `label`, `name`, `value`, `description`, `readonly`, `mantine_rating_count`, `mantine_rating_fractions`, `mantine_rating_use_smiles`, `mantine_rating_empty_icon` / `mantine_rating_full_icon`, `mantine_rating_highlight_selected_only`.

**Children.** No.

---

## progress

**Purpose.** Mantine `Progress` — a single progress bar.

**Administrators.** Show completion (e.g. survey progress). Set the `value` (0–100), colour, and striped/animated style.

**Developers.** Renders `<Progress value>`.

**Distinctive fields.** `value`, `mantine_progress_striped`, `mantine_progress_animated`, `mantine_progress_transition_duration`.

**Children.** No.

---

## progress-root

**Purpose.** Mantine `Progress.Root` — a container for **multiple** progress sections (a segmented bar).

**Administrators.** Use when one bar must show several coloured segments. Add `progress-section` children.

**Developers.** Renders `<Progress.Root>`; children are `progress-section`.

**Distinctive fields.** `mantine_progress_auto_contrast`.

**Children.** Yes (`progress-section`).

---

## progress-section

**Purpose.** Mantine `Progress.Section` — one coloured segment inside a `progress-root`.

**Administrators.** Place inside `progress-root`. Set this segment's `value`, colour, and optional tooltip.

**Developers.** Renders `<Progress.Section value>`.

**Distinctive fields.** `value`, `label`, `mantine_progress_striped`, `mantine_progress_animated`, `mantine_tooltip_label` / `mantine_tooltip_position`.

**Children.** No.

---

## showUserInput

**Purpose.** Read-only **display** companion to `form-log` / `form-record`: renders a user's previously submitted entries from a data table as a Mantine Table.

**Administrators.** Drop it on a page to show back what people have submitted. Point `data_table` at the form's table and use `fields_map` to choose which columns appear and how they are labelled. Two switches control *whose* data is shown and *what* can be deleted:

- **Own Entries Only** (`own_entries_only`, default on) — each user sees only their **own** submissions. Turn it **off** to show **all** users' entries (only do this where viewing everyone's data is allowed; it is still subject to data-access permissions).
- **Delete** (`delete_entry`, default on) — shows a per-row delete button. A user may always delete **their own** record; deleting **another user's** record additionally requires the table's delete data-access permission. The confirmation dialog copy is set by the translatable `delete_modal_title` / `delete_modal_body`.

Optional table behaviour: search (`dt_searching`), sorting (`dt_sortable` + `dt_default_order_column` / `dt_default_order_dir`), pagination (`dt_paginate`), the row-count footer (`dt_info`), a CSV export button (`csv_export`), and a leading timestamp column (`show_timestamp`).

**Developers.** Renders as a Mantine Table (the style always uses the Mantine renderer). Rows come from the configured data table; when `own_entries_only = 1` the query is scoped to the current user. The own-vs-permission delete rule is **centralised** in `DataAccessSecurityService::canDeleteOwnedRecord()` so the display check (`SectionUtilityService` deciding whether to show the button) and the enforcement check (`FormController::deleteForm`) stay in lockstep: own record → always deletable; another user's record → deletable only with the data table's `delete` bit. The shared contract is `IShowUserInputStyle` / `IShowUserInputEntry` in `@selfhelp/shared` (`src/types/styles/forms.ts`); the frontend imports those types directly (no local duplicate).

**Distinctive fields.** `data_table`, `fields_map` (translatable column config), `own_entries_only`, `show_timestamp`, `delete_entry`, `csv_export`, `dt_sortable`, `dt_searching`, `dt_paginate`, `dt_info`, `dt_default_order_column`, `dt_default_order_dir`, `delete_modal_title` / `delete_modal_body` (translatable), and the Mantine Table props `mantine_table_striped`, `mantine_table_highlight_on_hover`, `mantine_table_with_table_border`, `mantine_table_with_column_borders`, `mantine_table_with_row_borders`, `mantine_table_sticky_header`, `mantine_table_caption_side`.

**Children.** No.

---

## Related references

- [_conventions.md](./_conventions.md) — common fields and Mantine prop conventions.
- [composite.md](./composite.md) — `entryList` / `entryRecord` that display saved form data.
- [index.md](./index.md) — full style catalog.
