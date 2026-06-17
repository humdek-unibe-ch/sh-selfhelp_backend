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
 * Add DataTable feature-flag and Mantine Table styling fields to showUserInput.
 *
 * DataTable controls (new fields):
 *   dt_sortable            checkbox  — enable column sorting
 *   dt_searching           checkbox  — show search box
 *   dt_paginate            checkbox  — enable pagination
 *   dt_info                checkbox  — show "Showing X–Y of Z" footer
 *   dt_default_order_column text     — column name for default sort
 *   dt_default_order_dir   select    — default sort direction: asc / desc
 *
 * Mantine Table props (new fields):
 *   mantine_table_striped            checkbox — striped rows
 *   mantine_table_highlight_on_hover checkbox — highlight row on hover
 *   mantine_table_with_table_border  checkbox — outer table border
 *   mantine_table_with_column_borders checkbox — column dividers
 *   mantine_table_with_row_borders   checkbox — row dividers
 *   mantine_table_sticky_header      checkbox — sticky thead
 *   mantine_table_caption_side       segment  — caption position: top / bottom
 *
 * Action controls (new fields):
 *   csv_export   checkbox — show a CSV export button
 *   delete_entry checkbox — show per-row delete buttons
 *
 * Reused existing fields (already in DB):
 *   mantine_spacing_margin_padding — maps to horizontalSpacing + verticalSpacing
 *                                    (frontend splits it into the two Table props)
 */
final class Version20260611090106 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dt_* feature-flag fields and Mantine Table styling fields to showUserInput.';
    }

    public function up(Schema $schema): void
    {
        // ---------------------------------------------------------------
        // 1. Create new fields — each addSql call uses its own param set
        //    to avoid named-parameter key collision across loop iterations.
        // ---------------------------------------------------------------

        // DataTable checkbox controls (four separate inserts)
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'dt_sortable', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'dt_searching', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'dt_paginate', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'dt_info', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );

        // Default sort column — text (stores column name, not index)
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'dt_default_order_column', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'text'"
        );

        // Default sort direction — select
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'dt_default_order_dir', ft.id, 0, :config FROM `field_types` ft WHERE ft.`name` = 'select'",
            ['config' => json_encode(['options' => [
                ['value' => 'asc',  'text' => 'Ascending'],
                ['value' => 'desc', 'text' => 'Descending'],
            ]])]
        );

        // Action control checkboxes
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'csv_export', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'delete_entry', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );

        // Mantine Table boolean props (six separate inserts)
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'mantine_table_striped', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'mantine_table_highlight_on_hover', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'mantine_table_with_table_border', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'mantine_table_with_column_borders', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'mantine_table_with_row_borders', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'mantine_table_sticky_header', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );

        // Caption side — segment (top / bottom)
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'mantine_table_caption_side', ft.id, 0, :config FROM `field_types` ft WHERE ft.`name` = 'segment'",
            ['config' => json_encode(['options' => [
                ['value' => 'top',    'text' => 'Top'],
                ['value' => 'bottom', 'text' => 'Bottom'],
            ]])]
        );

        // ---------------------------------------------------------------
        // 2. Link all fields to showUserInput
        // ---------------------------------------------------------------

        // DataTable controls
        $this->linkField('showUserInput', 'dt_sortable',             '0',   'Sortable',              'Enable sorting on all columns.');
        $this->linkField('showUserInput', 'dt_searching',            '0',   'Search',                'Show a search box above the table.');
        $this->linkField('showUserInput', 'dt_paginate',             '0',   'Pagination',            'Group rows into pages.');
        $this->linkField('showUserInput', 'dt_info',                 '0',   'Row Info',              'Show "Showing X–Y of Z entries" footer.');
        $this->linkField('showUserInput', 'dt_default_order_column', '',    'Default Sort Column',   'Name of the column to sort by default. Leave empty for no default sort.');
        $this->linkField('showUserInput', 'dt_default_order_dir',    'asc', 'Default Sort Direction','Direction for the default column sort.');

        // Action controls
        $this->linkField('showUserInput', 'csv_export',   '0', 'CSV Export', 'Show a button to export the table as CSV.');
        $this->linkField('showUserInput', 'delete_entry', '1', 'Delete',     'Show per-row delete buttons (subject to own_entries_only restriction).');

        // Mantine Table styling (reused existing field)
        $this->linkField('showUserInput', 'mantine_spacing_margin_padding', '', 'Table Spacing',         'Controls horizontal and vertical cell spacing (xs / sm / md / lg / xl).');

        // Mantine Table styling (new fields)
        $this->linkField('showUserInput', 'mantine_table_striped',             '0', 'Striped',            'Alternate row background colours.');
        $this->linkField('showUserInput', 'mantine_table_highlight_on_hover',  '1', 'Highlight on Hover', 'Highlight the row the cursor is over.');
        $this->linkField('showUserInput', 'mantine_table_with_table_border',   '1', 'Table Border',       'Draw a border around the entire table.');
        $this->linkField('showUserInput', 'mantine_table_with_column_borders', '1', 'Column Borders',     'Draw borders between columns.');
        $this->linkField('showUserInput', 'mantine_table_with_row_borders',    '0', 'Row Borders',        'Draw borders between rows.');
        $this->linkField('showUserInput', 'mantine_table_sticky_header',       '0', 'Sticky Header',      'Keep the header row visible while scrolling.');
        $this->linkField('showUserInput', 'mantine_table_caption_side',        '',  'Caption Side',       'Position of the table caption: top or bottom.');
    }

    public function down(Schema $schema): void
    {
        $allFields = [
            'dt_sortable', 'dt_searching', 'dt_paginate', 'dt_info',
            'dt_default_order_column', 'dt_default_order_dir',
            'csv_export', 'delete_entry',
            'mantine_table_striped', 'mantine_table_highlight_on_hover',
            'mantine_table_with_table_border', 'mantine_table_with_column_borders',
            'mantine_table_with_row_borders', 'mantine_table_sticky_header',
            'mantine_table_caption_side',
            'mantine_spacing_margin_padding', // reused — unlink only, do not delete
        ];

        foreach ($allFields as $field) {
            $this->addSql(
                "DELETE rfs FROM `rel_fields_styles` rfs
                 JOIN `styles` s ON s.id = rfs.id_styles
                 JOIN `fields` f ON f.id = rfs.id_fields
                 WHERE s.`name` = 'showUserInput' AND f.`name` = :field",
                ['field' => $field]
            );
        }

        // Delete only the fields created by this migration (not the reused mantine_spacing_margin_padding)
        $newFields = [
            'dt_sortable', 'dt_searching', 'dt_paginate', 'dt_info',
            'dt_default_order_column', 'dt_default_order_dir',
            'csv_export', 'delete_entry',
            'mantine_table_striped', 'mantine_table_highlight_on_hover',
            'mantine_table_with_table_border', 'mantine_table_with_column_borders',
            'mantine_table_with_row_borders', 'mantine_table_sticky_header',
            'mantine_table_caption_side',
        ];

        foreach ($newFields as $field) {
            $this->addSql('DELETE FROM `fields` WHERE `name` = :name', ['name' => $field]);
        }
    }

    private function linkField(string $style, string $field, string $defaultValue, string $title, string $help): void
    {
        $this->addSql(
            "INSERT IGNORE INTO `rel_fields_styles`
                (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
             SELECT s.id, f.id, :defaultValue, :help, 0, 0, :title
             FROM `styles` s, `fields` f
             WHERE s.`name` = :style AND f.`name` = :field",
            [
                'defaultValue' => $defaultValue,
                'help'         => $help,
                'title'        => $title,
                'style'        => $style,
                'field'        => $field,
            ]
        );
    }
}
