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
 * Seed the `showUserInput` style.
 *
 * showUserInput is a read-only display style that renders a user's previously
 * submitted form entries as a table. It is the view companion to `form-record`
 * (which handles submission). All four pre-existing fields are reused; only
 * `show_timestamp` is new.
 *
 * Fields linked to the style:
 *   - data_table       (select-data_table) which data table to read from
 *   - fields_map       (json, translatable) column display/label config
 *   - own_entries_only (checkbox) restrict rows to the current user's entries
 *   - show_timestamp   (checkbox, NEW) show timestamp as the leading column
 *   - anchor           (anchor-section) optional URL hash anchor
 */
final class Version20260609130712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed showUserInput style with data_table, fields_map, own_entries_only, show_timestamp and anchor fields.';
    }

    public function up(Schema $schema): void
    {
        // 1. Create the new show_timestamp field (does not exist yet).
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
            SELECT 'show_timestamp', ft.id, 0, NULL
            FROM `field_types` ft
            WHERE ft.`name` = 'checkbox'
        SQL);

        // 2. Insert the showUserInput style into the Form style group.
        // id_style_groups is resolved by name from an existing Form-group style
        // (form-record) so no group id is hardcoded.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `styles` (`name`, `id_style_groups`, `description`, `can_have_children`)
            SELECT :name, s.id_style_groups, :description, 0
            FROM `styles` s
            WHERE s.`name` = 'form-record'
        SQL, [
            'name'        => 'showUserInput',
            'description' => 'Displays user-submitted form entries from a data table in a read-only table view.',
        ]);

        // 3. Link all five fields.
        $this->linkField('showUserInput', 'data_table',       '',  'Data Table',       'The data table whose entries to display.');
        $this->linkField('showUserInput', 'fields_map',       '',  'Fields Map',       'JSON array of column definitions controlling which fields are shown and with what labels.');
        $this->linkField('showUserInput', 'own_entries_only', '1', 'Own Entries Only', 'When enabled, users see only their own submissions. Disabling allows viewing all entries (subject to data-access permissions).');
        $this->linkField('showUserInput', 'show_timestamp',   '0', 'Show Timestamp',   'When enabled, the leading column shows the submission timestamp instead of the internal record ID.');
        $this->linkField('showUserInput', 'anchor',           '',  'Anchor',           'Optional HTML anchor ID for deep-linking to this section via URL hash.');
    }

    public function down(Schema $schema): void
    {
        // Remove style-field links first (FK constraint).
        $this->addSql(<<<SQL
            DELETE rfs FROM `rel_fields_styles` rfs
            JOIN `styles` s ON s.id = rfs.id_styles
            WHERE s.`name` = 'showUserInput'
        SQL);

        $this->addSql("DELETE FROM `styles` WHERE `name` = 'showUserInput'");

        // Remove only the field created by this migration.
        // data_table, fields_map, own_entries_only and anchor were seeded
        // before this migration and must NOT be removed here.
        $this->addSql("DELETE FROM `fields` WHERE `name` = 'show_timestamp'");
    }

    private function linkField(string $style, string $field, string $defaultValue, string $title, string $help): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_fields_styles`
                (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
            SELECT s.id, f.id, :defaultValue, :help, 0, 0, :title
            FROM `styles` s, `fields` f
            WHERE s.`name` = :style AND f.`name` = :field
        SQL, [
            'defaultValue' => $defaultValue,
            'help'         => $help,
            'title'        => $title,
            'style'        => $style,
            'field'        => $field,
        ]);
    }
}
