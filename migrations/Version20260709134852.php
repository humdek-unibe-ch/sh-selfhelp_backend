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
 * Restore legacy field-based data binding for entry-list / entry-record holders.
 *
 * Reverses the catalog portion of Version20260706221024 for entry holders:
 * re-links data_table, filter, own_entries_only, scope, load_as_table,
 * url_param, selected_columns. Recreates orphaned field definitions (filter,
 * load_as_table, url_param) and adds select-data_table_columns + selected_columns.
 *
 * No data migration — content is reimported from updated bundles.
 */
final class Version20260709134852 extends AbstractMigration
{
    /**
     * @var list<array{style: string, field: string, default_value: string|null, help: string, title: string}>
     */
    private const ENTRY_FIELD_LINKS = [
        ['style' => 'entry-list', 'field' => 'data_table', 'default_value' => '', 'help' => 'Select a data table which will be linked to the style', 'title' => 'Data table'],
        ['style' => 'entry-list', 'field' => 'filter', 'default_value' => null, 'help' => 'Filter the data source; use SQL WHERE syntax. Route and scope tokens may be interpolated.', 'title' => 'Filter'],
        ['style' => 'entry-list', 'field' => 'load_as_table', 'default_value' => '0', 'help' => 'If enabled, the children are loaded inside a table.', 'title' => 'Load as table'],
        ['style' => 'entry-list', 'field' => 'own_entries_only', 'default_value' => '1', 'help' => 'If selected the entry list will load only the records entered by the user.', 'title' => 'Own entries only'],
        ['style' => 'entry-list', 'field' => 'scope', 'default_value' => '', 'help' => 'If the variable `scope` is defined, it serves as a prefix for naming the variables', 'title' => 'Scope'],
        ['style' => 'entry-list', 'field' => 'selected_columns', 'default_value' => '', 'help' => 'Optional comma-separated list of data columns to load. Leave empty to load all columns.', 'title' => 'Selected columns'],
        ['style' => 'entry-record', 'field' => 'data_table', 'default_value' => '', 'help' => 'Select a data table which will be linked to the style', 'title' => 'Data table'],
        ['style' => 'entry-record', 'field' => 'filter', 'default_value' => null, 'help' => 'Additional SQL filter; record id from the route is applied automatically.', 'title' => 'Filter'],
        ['style' => 'entry-record', 'field' => 'own_entries_only', 'default_value' => '1', 'help' => 'If selected the entry record will load only when it belongs to the current user.', 'title' => 'Own entries only'],
        ['style' => 'entry-record', 'field' => 'scope', 'default_value' => '', 'help' => 'If the variable `scope` is defined, it serves as a prefix for naming the variables', 'title' => 'Scope'],
        ['style' => 'entry-record', 'field' => 'url_param', 'default_value' => 'record_id', 'help' => 'Route parameter name used to load one record (default record_id).', 'title' => 'URL parameter'],
        ['style' => 'loop', 'field' => 'scope', 'default_value' => '', 'help' => 'If the variable `scope` is defined, it serves as a prefix for naming the variables', 'title' => 'Scope'],
    ];

    public function getDescription(): string
    {
        return 'Restore entry-list/entry-record legacy property fields for field-based data binding';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `field_types` (`name`, `position`)
            VALUES ('select-data_table_columns', 8)
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
            SELECT 'filter', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'code'
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
            SELECT 'load_as_table', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'checkbox'
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
            SELECT 'url_param', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'text'
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
            SELECT 'selected_columns', ft.id, 0, NULL FROM `field_types` ft WHERE ft.`name` = 'select-data_table_columns'
        SQL);

        foreach (self::ENTRY_FIELD_LINKS as $link) {
            $this->linkField(
                $link['style'],
                $link['field'],
                $link['default_value'],
                $link['title'],
                $link['help'],
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::ENTRY_FIELD_LINKS as $link) {
            $this->addSql(sprintf(
                'DELETE sft FROM sections_fields_translation sft'
                . ' JOIN sections sec ON sec.id = sft.id_sections'
                . ' JOIN styles s ON s.id = sec.id_styles'
                . ' JOIN fields f ON f.id = sft.id_fields'
                . " WHERE s.name = '%s' AND f.name = '%s'",
                $link['style'],
                $link['field'],
            ));
            $this->addSql(sprintf(
                'DELETE rfs FROM rel_fields_styles rfs'
                . ' JOIN styles s ON s.id = rfs.id_styles'
                . ' JOIN fields f ON f.id = rfs.id_fields'
                . " WHERE s.name = '%s' AND f.name = '%s'",
                $link['style'],
                $link['field'],
            ));
        }

        $this->addSql("DELETE FROM fields WHERE name = 'selected_columns'");
        $this->addSql("DELETE FROM fields WHERE name IN ('filter', 'load_as_table', 'url_param')");
        $this->addSql("DELETE FROM field_types WHERE name = 'select-data_table_columns'");
    }

    private function linkField(
        string $styleName,
        string $fieldName,
        ?string $defaultValue,
        string $title,
        string $help,
    ): void {
        $defaultSql = $defaultValue === null
            ? 'NULL'
            : "'" . str_replace("'", "''", $defaultValue) . "'";

        $this->addSql(sprintf(
            'INSERT IGNORE INTO rel_fields_styles (id_styles, id_fields, default_value, help, disabled, hidden, title)'
            . " SELECT s.id, f.id, %s, '%s', 0, 0, '%s'"
            . " FROM styles s, fields f WHERE s.name = '%s' AND f.name = '%s'",
            $defaultSql,
            str_replace("'", "''", $help),
            str_replace("'", "''", $title),
            $styleName,
            $fieldName,
        ));
    }
}
