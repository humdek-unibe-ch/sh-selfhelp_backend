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
 * Mobile-only capability pass: expose HeroUI Native props that have no web /
 * Mantine equivalent, so authors can configure the native look from the CMS.
 *
 * All additive (new `mobile_*` author fields + style links). Backend `src/`
 * reads none of them; they are author-catalog metadata consumed only by the
 * mobile renderers (the web renderer ignores `mobile_*`).
 *
 *  - select (+ combobox): mobile_select_presentation — bottom-sheet / dialog /
 *    popover (HeroUI Native Select.Content presentation; combobox reuses the
 *    mobile Select renderer so it shares the field).
 *  - button: mobile_button_feedback — native press feedback
 *    (scale-highlight / scale-ripple / scale / none).
 *  - slider / range-slider: mobile_slider_show_value / mobile_range_slider_show_value
 *    — toggle the HeroUI Native Slider.Output value bubble.
 *  - text-input / textarea / checkbox: mobile_input_variant / _textarea_variant /
 *    _checkbox_variant — HeroUI Native primary | secondary field variant.
 *
 * down() drops the new fields everywhere (which removes their style links too).
 */
final class Version20260622145334 extends AbstractMigration
{
    /** HeroUI Native Select.Content presentation modes (empty = renderer default bottom-sheet). */
    private const PRESENTATION_CONFIG = '{"options":[{"text":"Bottom sheet","value":"bottom-sheet"},{"text":"Dialog","value":"dialog"},{"text":"Popover","value":"popover"}],"clearable":true,"searchable":false}';

    /** HeroUI Native Button press feedback variants (empty = renderer default scale-highlight). */
    private const FEEDBACK_CONFIG = '{"options":[{"text":"Scale + highlight","value":"scale-highlight"},{"text":"Scale + ripple","value":"scale-ripple"},{"text":"Scale only","value":"scale"},{"text":"None","value":"none"}],"clearable":true,"searchable":false}';

    /** HeroUI Native field variant (primary = bordered, secondary = filled). */
    private const VARIANT_CONFIG = '{"options":[{"text":"Primary","value":"primary"},{"text":"Secondary","value":"secondary"}]}';

    /**
     * New fields to create: name => [type, display, config|null].
     *
     * @var array<string, array{0: string, 1: int, 2: string|null}>
     */
    private const NEW_FIELDS = [
        'mobile_select_presentation' => ['select', 0, self::PRESENTATION_CONFIG],
        'mobile_button_feedback' => ['select', 0, self::FEEDBACK_CONFIG],
        'mobile_slider_show_value' => ['checkbox', 0, null],
        'mobile_range_slider_show_value' => ['checkbox', 0, null],
        'mobile_input_variant' => ['segment', 0, self::VARIANT_CONFIG],
        'mobile_textarea_variant' => ['segment', 0, self::VARIANT_CONFIG],
        'mobile_checkbox_variant' => ['segment', 0, self::VARIANT_CONFIG],
    ];

    /**
     * Style links to create: [style, field, default_value|null, help, title].
     *
     * @var list<array{0: string, 1: string, 2: string|null, 3: string, 4: string}>
     */
    private const LINKS = [
        ['select', 'mobile_select_presentation', '', 'How the option list opens on mobile. Leave empty for the default (bottom sheet).', 'Presentation (mobile)'],
        ['combobox', 'mobile_select_presentation', '', 'How the option list opens on mobile. Leave empty for the default (bottom sheet).', 'Presentation (mobile)'],

        ['button', 'mobile_button_feedback', '', 'Native press feedback on mobile. Leave empty for the default (scale + highlight).', 'Press feedback (mobile)'],

        ['slider', 'mobile_slider_show_value', '1', 'Show the current value bubble above the slider on mobile.', 'Show value (mobile)'],
        ['range-slider', 'mobile_range_slider_show_value', '1', 'Show the current range value above the slider on mobile.', 'Show value (mobile)'],

        ['text-input', 'mobile_input_variant', 'primary', 'Native field style on mobile: primary (bordered) or secondary (filled).', 'Field variant (mobile)'],
        ['textarea', 'mobile_textarea_variant', 'primary', 'Native field style on mobile: primary (bordered) or secondary (filled).', 'Field variant (mobile)'],
        ['checkbox', 'mobile_checkbox_variant', 'primary', 'Native checkbox style on mobile: primary or secondary.', 'Variant (mobile)'],
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
        return 'Mobile-only capability pass: add mobile_select_presentation (select+combobox), mobile_button_feedback (button), mobile_slider_show_value / mobile_range_slider_show_value (slider/range-slider), and mobile_input_variant / mobile_textarea_variant / mobile_checkbox_variant (text-input/textarea/checkbox). All additive HeroUI Native props with no web equivalent.';
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
    }

    public function down(Schema $schema): void
    {
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
