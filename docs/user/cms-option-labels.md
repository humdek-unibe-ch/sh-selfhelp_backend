<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Configure translated option labels

Audience: CMS administrators and content editors.
Status: active.
Applies to: Select, radio, combobox, and segmented-control sections.
Last verified: 2026-07-09.
Source of truth: The section inspector Options grid and public page rendering.

Open an option-based section and use its Options grid:

1. Add one row per choice.
2. Enter a permanent code such as `release`. Existing records keep this code, so do not rename it after collecting data.
3. Enter the readable label in every language column.
4. Optionally set the display order or disable a choice.
5. Save the section.

The editor identifies the exact row and language for missing labels, duplicate or malformed codes, and invalid sort values. Its example panel has copy buttons for the internal option and label JSON.

Records store codes, not translated text. In public list/detail templates use `{{_category_label}}` for one choice or `{{_tags_labels}}` for a multi-choice field. These underscore variables are generated at runtime and cannot be used as real form field names.

Older options containing `text` or `label` still render. If a translation is missing, SelfHelp tries the instance default language, then the legacy label, then the code.
