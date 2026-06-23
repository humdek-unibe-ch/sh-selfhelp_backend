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
 * Form / interactive styles capability pass: expose the Mantine props these
 * styles could already do but had no CMS field for, plus one cleanup unlink.
 *
 * All additive (new author fields) except the final `select.alt` unlink, which
 * removes a leftover link (the `alt` field stays — avatar/image still use it).
 * Backend `src/` reads none of these fields; they are author-catalog metadata
 * consumed only by the web/mobile renderers in the coupled renderer wave.
 *
 *  - number-input: web_number_input_prefix / _suffix (currency/units),
 *    _thousand_separator, _allow_negative, _hide_controls.
 *  - color-input: web_color_input_with_eye_dropper / _disallow_input / _with_preview.
 *  - tabs: web_tabs_grow, web_tabs_justify, web_tabs_keep_mounted, web_tabs_placement.
 *  - switch: web_switch_with_thumb_indicator, web_switch_thumb_icon (icon picker).
 *  - text-input + textarea: shared_max_length (HTML maxLength + RN maxLength).
 *  - text-input (+ textarea): mobile_keyboard_type / mobile_auto_capitalize /
 *    mobile_secure_entry native keyboard knobs. (number-input is omitted on
 *    purpose: its keyboard is inherently numeric, so the knobs add no value.)
 *  - progress-root: link the existing shared_radius field (rounder bar).
 *  - cleanup: unlink the unused `alt` field from `select`.
 *
 * down() is a best-effort structural inverse for local rollback.
 */
final class Version20260622132034 extends AbstractMigration
{
    /** Mantine JustifyContent subset for the tab list alignment. */
    private const JUSTIFY_CONFIG = '{"options":[{"text":"Start","value":"flex-start"},{"text":"Center","value":"center"},{"text":"End","value":"flex-end"},{"text":"Space between","value":"space-between"},{"text":"Space around","value":"space-around"}],"clearable":true,"searchable":false}';

    /** Vertical tab list side. */
    private const PLACEMENT_CONFIG = '{"options":[{"text":"Left","value":"left"},{"text":"Right","value":"right"}]}';

    /** React Native keyboardType subset. */
    private const KEYBOARD_CONFIG = '{"options":[{"text":"Default","value":"default"},{"text":"Email","value":"email-address"},{"text":"Numeric","value":"numeric"},{"text":"Phone","value":"phone-pad"},{"text":"URL","value":"url"}],"clearable":true,"searchable":false}';

    /** React Native autoCapitalize values. */
    private const CAPITALIZE_CONFIG = '{"options":[{"text":"None","value":"none"},{"text":"Sentences","value":"sentences"},{"text":"Words","value":"words"},{"text":"Characters","value":"characters"}],"clearable":true,"searchable":false}';

    /**
     * New fields to create: name => [type, display, config|null].
     *
     * @var array<string, array{0: string, 1: int, 2: string|null}>
     */
    private const NEW_FIELDS = [
        'web_number_input_prefix' => ['text', 0, null],
        'web_number_input_suffix' => ['text', 0, null],
        'web_number_input_thousand_separator' => ['checkbox', 0, null],
        'web_number_input_allow_negative' => ['checkbox', 0, null],
        'web_number_input_hide_controls' => ['checkbox', 0, null],
        'web_color_input_with_eye_dropper' => ['checkbox', 0, null],
        'web_color_input_disallow_input' => ['checkbox', 0, null],
        'web_color_input_with_preview' => ['checkbox', 0, null],
        'web_tabs_grow' => ['checkbox', 0, null],
        'web_tabs_justify' => ['select', 0, self::JUSTIFY_CONFIG],
        'web_tabs_keep_mounted' => ['checkbox', 0, null],
        'web_tabs_placement' => ['segment', 0, self::PLACEMENT_CONFIG],
        'web_switch_with_thumb_indicator' => ['checkbox', 0, null],
        'web_switch_thumb_icon' => ['select-icon', 0, null],
        'shared_max_length' => ['number', 0, null],
        'mobile_keyboard_type' => ['select', 0, self::KEYBOARD_CONFIG],
        'mobile_auto_capitalize' => ['select', 0, self::CAPITALIZE_CONFIG],
        'mobile_secure_entry' => ['checkbox', 0, null],
    ];

    /**
     * Style links to create: [style, field, default_value|null, help, title].
     *
     * @var list<array{0: string, 1: string, 2: string|null, 3: string, 4: string}>
     */
    private const LINKS = [
        ['number-input', 'web_number_input_prefix', '', 'Text shown before the number (e.g. $). Leave empty for none.', 'Prefix'],
        ['number-input', 'web_number_input_suffix', '', 'Text shown after the number (e.g. kg, %). Leave empty for none.', 'Suffix'],
        ['number-input', 'web_number_input_thousand_separator', '0', 'Group thousands with a separator (e.g. 1,000).', 'Thousand separator'],
        ['number-input', 'web_number_input_allow_negative', '1', 'Allow negative values.', 'Allow negative'],
        ['number-input', 'web_number_input_hide_controls', '0', 'Hide the up / down stepper buttons.', 'Hide controls'],

        ['color-input', 'web_color_input_with_eye_dropper', '1', 'Show the eye-dropper button to pick a colour from anywhere on screen.', 'Eye dropper'],
        ['color-input', 'web_color_input_disallow_input', '0', 'Pick-only: prevent typing a colour value by hand.', 'Disallow manual input'],
        ['color-input', 'web_color_input_with_preview', '1', 'Show the selected-colour preview swatch inside the field.', 'Colour preview'],

        ['tabs', 'web_tabs_grow', '0', 'Stretch the tabs to fill the available width.', 'Grow'],
        ['tabs', 'web_tabs_justify', '', 'Alignment of the tab list. Leave empty for the default (start).', 'Justify'],
        ['tabs', 'web_tabs_keep_mounted', '1', 'Keep inactive tab panels mounted (turn off to unmount hidden panels).', 'Keep mounted'],
        ['tabs', 'web_tabs_placement', 'left', 'Tab list side when the orientation is vertical.', 'Placement'],

        ['switch', 'web_switch_with_thumb_indicator', '1', 'Show a coloured dot inside the switch thumb.', 'Thumb indicator'],
        ['switch', 'web_switch_thumb_icon', '', 'Optional icon shown inside the switch thumb.', 'Thumb icon'],

        ['text-input', 'shared_max_length', '', 'Maximum number of characters allowed (web + mobile). Leave empty for no limit.', 'Max length'],
        ['textarea', 'shared_max_length', '', 'Maximum number of characters allowed (web + mobile). Leave empty for no limit.', 'Max length'],

        ['text-input', 'mobile_keyboard_type', '', 'Native keyboard type shown on mobile. Leave empty for the default keyboard.', 'Keyboard type (mobile)'],
        ['text-input', 'mobile_auto_capitalize', '', 'Auto-capitalization behaviour on mobile.', 'Auto-capitalize (mobile)'],
        ['text-input', 'mobile_secure_entry', '0', 'Mask the entered text (password-style) on mobile.', 'Secure entry (mobile)'],
        ['textarea', 'mobile_auto_capitalize', '', 'Auto-capitalization behaviour on mobile.', 'Auto-capitalize (mobile)'],

        ['progress-root', 'shared_radius', 'sm', 'Corner radius of the progress bar (web + mobile).', 'Radius'],
    ];

    /** @var list<string> every table with an FK to fields(id) */
    private const FIELD_REF_TABLES = [
        'sections_fields_translation',
        'rel_fields_styles',
        'rel_fields_pages',
        'pages_fields_translation',
        'rel_fields_page_types',
    ];

    public function getDescription(): string
    {
        return 'Form/interactive capability pass: add number-input prefix/suffix/separator/negative/hide-controls, color-input eye-dropper/disallow/preview, tabs grow/justify/keep-mounted/placement, switch thumb indicator/icon, shared_max_length (text-input+textarea), mobile keyboard knobs (text-input+textarea), progress-root radius; unlink unused select.alt.';
    }

    public function up(Schema $schema): void
    {
        foreach (array_keys(self::NEW_FIELDS) as $name) {
            $this->abortIf($this->fieldExists($name), sprintf("Refusing create: field '%s' already exists.", $name));
        }

        foreach (self::NEW_FIELDS as $name => [$type, $display, $config]) {
            $this->createField($name, $type, $display, $config);
        }

        foreach (self::LINKS as [$style, $field, $default, $help, $title]) {
            $this->linkRel($style, $field, $default, $help, $title);
        }

        // Cleanup: unlink the unused `alt` field from `select` (the field stays —
        // avatar/image still use it). Drop authored values on `select` sections.
        $this->addSql(
            "DELETE FROM `sections_fields_translation`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'alt')
               AND id_sections IN (
                   SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'select')
               )"
        );
        $this->deleteRel('alt', 'select');
    }

    public function down(Schema $schema): void
    {
        // Inverse cleanup: re-link `alt` to `select`.
        $this->linkRel('select', 'alt', null, 'Alternative text', 'Alt');

        // Inverse links + new fields (drop the links by removing the fields entirely).
        $this->deleteRel('shared_radius', 'progress-root');
        foreach (array_keys(self::NEW_FIELDS) as $name) {
            $this->dropFieldEverywhere($name);
        }
    }

    /** @param string|null $default */
    private function linkRel(string $style, string $field, ?string $default, ?string $help, ?string $title = null): void
    {
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, title, disabled, hidden)
             SELECT s.id, f.id, ?, ?, ?, 0, 0
             FROM `styles` s, `fields` f
             WHERE s.`name` = ? AND f.`name` = ?",
            [$default, $help, $title, $style, $field]
        );
    }

    private function deleteRel(string $field, string $style): void
    {
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = ?)",
            [$field, $style]
        );
    }

    /** @param string|null $config JSON string or null */
    private function createField(string $name, string $type, int $display, ?string $config): void
    {
        $this->addSql(
            "INSERT INTO `fields` (`name`, id_field_types, `display`, `config`)
             SELECT ?, ft.id, ?, ? FROM `field_types` ft WHERE ft.`name` = ?",
            [$name, $display, $config, $type]
        );
    }

    /** FK-safe full removal of a field across every referencing table. */
    private function dropFieldEverywhere(string $name): void
    {
        foreach (self::FIELD_REF_TABLES as $table) {
            $this->addSql(
                "DELETE FROM `$table` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
                [$name]
            );
        }
        $this->addSql('DELETE FROM `fields` WHERE `name` = ?', [$name]);
    }

    private function fieldExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `fields` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
