<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Style field cleanup, slice 3 — semantic variant promotion + the translatable
 * `web_*` un-prefix sweep. Decision register:
 * docs/reference/styles/style-refactoring-recommendations.md (RF-14, RF-35).
 * Pairs with `@selfhelp/shared` v1.14.0 and the coupled web + mobile renderer
 * reads. Pre-1.0: nothing here is backward compatible.
 *
 *   - RF-14  rename `web_button_variant` -> `shared_variant`. The error/surface
 *            styles (`missing`, `no-access`, `not-found`) carry a button variant
 *            that is a semantic token both platforms should honour, so it drops
 *            the `web_` prefix. Because field scope is derived from the name
 *            prefix (`StyleRepository::deriveFieldScope`), this `display = 0`
 *            field flips from the Web card to the Shared card and becomes
 *            readable by the mobile renderer.
 *   - RF-35  drop the misleading `web_` prefix from every *translatable*
 *            (`display = 1`) field. `deriveFieldScope` already groups every
 *            `display = 1` field as `content` regardless of prefix, so these
 *            were always shipped to both platforms — the `web_` prefix was only
 *            a naming lie. Un-prefixing makes the catalog honest (translatable
 *            copy/options/marks are shared content, not web presentation). The
 *            web-only *presentation* twins (e.g. `web_divider_label_position`,
 *            `web_radio_card`, `web_color_format`) keep their prefix and are
 *            untouched.
 *
 * Already handled elsewhere and deliberately excluded: `web_alert_title`
 * (renamed to `alert_title` in slice 1), `web_image_src`/`web_image_alt`
 * (removed in slice 2), and the orphaned `web_combobox_data` (0 style links,
 * a separate dead-field cleanup).
 *
 * All operations are id-stable renames: relationships and authored content
 * reference `fields.id`, so renaming a `name` never breaks a link. `down()` is
 * the exact inverse.
 */
final class Version20260619093723 extends AbstractMigration
{
    /** @var array<string, string> old field name => new field name */
    private const RENAMES = [
        // RF-14 — web -> shared scope promotion (display = 0 presentation token).
        'web_button_variant' => 'shared_variant',
        // RF-35 — un-prefix translatable (display = 1) content fields.
        'web_color_picker_alpha_label' => 'color_picker_alpha_label',
        'web_color_picker_hue_label' => 'color_picker_hue_label',
        'web_color_picker_saturation_label' => 'color_picker_saturation_label',
        'web_combobox_options' => 'combobox_options',
        'web_datepicker_placeholder' => 'datepicker_placeholder',
        'web_divider_label' => 'divider_label',
        'web_highlight_highlight' => 'highlight_highlight',
        'web_list_item_content' => 'list_item_content',
        'web_multi_select_data' => 'multi_select_data',
        'web_radio_options' => 'radio_options',
        'web_range_slider_marks_values' => 'range_slider_marks_values',
        'web_rich_text_editor_placeholder' => 'rich_text_editor_placeholder',
        'web_segmented_control_data' => 'segmented_control_data',
        'web_slider_marks_values' => 'slider_marks_values',
        'web_spoiler_hide_label' => 'spoiler_hide_label',
        'web_spoiler_show_label' => 'spoiler_show_label',
        'web_switch_off_label' => 'switch_off_label',
        'web_switch_on_label' => 'switch_on_label',
        'web_tooltip_label' => 'tooltip_label',
    ];

    public function getDescription(): string
    {
        return 'Style field cleanup slice 3: promote web_button_variant -> shared_variant (RF-14) and un-prefix every translatable web_* content field (RF-35).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->abortIf(
                $this->fieldExists($new),
                sprintf("Refusing rename: target field '%s' already exists.", $new)
            );
        }

        foreach (self::RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$new, $old]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$old, $new]);
        }
    }

    private function fieldExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `fields` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
