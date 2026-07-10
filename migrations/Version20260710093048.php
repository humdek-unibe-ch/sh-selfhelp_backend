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
 * Final CMS entry-binding and editor catalog for the branch.
 *
 * This migration intentionally applies the final contract directly. It does
 * not remove the merge-base entry-list / entry-record / loop fields only to
 * recreate them later, and it does not seed the superseded entry-record SQL
 * filter or url_param contract.
 */
final class Version20260710093048 extends AbstractMigration
{
    private const ENTRY_RECORD_FORM_DESCRIPTION = 'Route-aware form for CMS and public surfaces: blank route creates a row; a route record id loads that row for edit (permission-gated).';

    /** @var array<string, string> style name => language-neutral catalog field */
    private const OPTION_CATALOGS = [
        'select' => 'options',
        'radio' => 'radio_options',
        'combobox' => 'combobox_options',
        'segmented-control' => 'segmented_control_data',
    ];

    /** @var list<string> */
    private const NEW_FIELDS = [
        'option_labels',
        'selected_columns',
        'show_language_preview',
        'fields_map_labels',
        'load_record_from',
    ];

    public function getDescription(): string
    {
        return 'Apply the final CMS entry binding, form, option-label, entry-table editor, query-preview route, and selected-column procedure contract.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `field_types` (`name`, `position`)
            VALUES
                ('select-data_table_columns', 8),
                ('select-data_table_column', 8),
                ('entry-filter', 12),
                ('fields-map', 46)
            SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
            SELECT 'option_labels', ft.`id`, 1, NULL
            FROM `field_types` ft
            WHERE ft.`name` = 'json'
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
            SELECT 'selected_columns', ft.`id`, 0, NULL
            FROM `field_types` ft
            WHERE ft.`name` = 'select-data_table_columns'
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
            SELECT 'show_language_preview', ft.`id`, 0, NULL
            FROM `field_types` ft
            WHERE ft.`name` = 'checkbox'
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
            SELECT 'fields_map_labels', ft.`id`, 1, NULL
            FROM `field_types` ft
            WHERE ft.`name` = 'json'
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
            SELECT 'load_record_from', ft.`id`, 0, NULL
            FROM `field_types` ft
            WHERE ft.`name` = 'text'
            SQL);

        $this->configureOptionCatalogs();

        // The links added by Version20260710093044 follow the style row by id.
        $this->addSql(<<<'SQL'
            UPDATE `styles`
            SET `name` = 'entry-table',
                `description` = 'Built-in admin data grid for a form''s entries: search, sorting, pagination, CSV export and add/edit/delete row actions.'
            WHERE `name` = 'show-user-input'
            SQL);

        // Bootstrap's visual type is not consumed by the entry delete renderers.
        $this->deleteFieldReferences('type');
        $this->addSql("DELETE FROM `fields` WHERE `name` = 'type'");

        $this->retargetFieldType('add_url', 'select-page-keyword');
        $this->retargetFieldType('edit_url', 'select-page-keyword');
        $this->retargetFieldType('redirect_on_save', 'select-page-keyword');
        $this->retargetFieldType('dt_default_order_column', 'select-data_table_column');
        $this->retargetFieldType('fields_map', 'fields-map');
        $this->retargetFieldType('filter', 'entry-filter');
        $this->addSql("UPDATE `fields` SET `display` = 0 WHERE `name` = 'fields_map'");

        $this->updateLink(
            'entry-list',
            'data_table',
            '',
            'Data table',
            'Select a data table which will be linked to the style',
        );
        $this->updateLink(
            'entry-list',
            'filter',
            null,
            'Filter',
            'SQL filter fragment (AND ...). Route and scope tokens may be interpolated; rejected or unresolved filters return no rows.',
        );
        $this->updateLink(
            'entry-list',
            'load_as_table',
            '0',
            'Load as table',
            'If enabled, the children are loaded inside a table.',
        );
        $this->updateLink(
            'entry-list',
            'own_entries_only',
            '1',
            'Own entries only',
            'If selected the entry list will load only the records entered by the user.',
        );
        $this->updateLink(
            'entry-list',
            'scope',
            '',
            'Scope',
            'If the variable `scope` is defined, it serves as a prefix for naming the variables',
        );
        $this->linkField(
            'entry-list',
            'selected_columns',
            '',
            'Selected columns',
            'Optional comma-separated list of data columns to load. Leave empty to load all columns.',
        );

        // entry-record uses the same visible route-param contract as the edit
        // form. Preserve custom legacy url_param values where they exist.
        $this->linkField(
            'entry-record',
            'load_record_from',
            'record_id',
            'Load record from route parameter',
            'Route parameter carrying the record id (e.g. `record_id` on `/team-members/{record_id}`). The holder loads that single row; when the param is missing or empty, nothing is shown.',
        );
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `sections_fields_translation`
                (`id_sections`, `id_fields`, `id_languages`, `content`, `meta`)
            SELECT sft.`id_sections`, target_field.`id`, sft.`id_languages`,
                   CASE WHEN TRIM(COALESCE(sft.`content`, '')) = '' THEN 'record_id' ELSE sft.`content` END,
                   sft.`meta`
            FROM `sections_fields_translation` sft
            INNER JOIN `sections` sec ON sec.`id` = sft.`id_sections`
            INNER JOIN `styles` style ON style.`id` = sec.`id_styles`
            INNER JOIN `fields` source_field ON source_field.`id` = sft.`id_fields`
            CROSS JOIN `fields` target_field
            WHERE style.`name` = 'entry-record'
              AND source_field.`name` = 'url_param'
              AND target_field.`name` = 'load_record_from'
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `sections_fields_translation`
                (`id_sections`, `id_fields`, `id_languages`, `content`, `meta`)
            SELECT sec.`id`, target_field.`id`, 1, 'record_id', NULL
            FROM `sections` sec
            INNER JOIN `styles` style ON style.`id` = sec.`id_styles`
            CROSS JOIN `fields` target_field
            WHERE style.`name` = 'entry-record'
              AND target_field.`name` = 'load_record_from'
              AND NOT EXISTS (
                  SELECT 1
                  FROM `sections_fields_translation` existing
                  WHERE existing.`id_sections` = sec.`id`
                    AND existing.`id_fields` = target_field.`id`
                    AND existing.`id_languages` = 1
              )
            SQL);
        $this->deleteStyleFieldValues('entry-record', 'filter');
        $this->deleteStyleFieldValues('entry-record', 'url_param');
        $this->unlinkField('entry-record', 'filter');
        $this->unlinkField('entry-record', 'url_param');
        $this->updateLink(
            'entry-record',
            'data_table',
            '',
            'Data table',
            'Select a data table which will be linked to the style',
        );
        $this->updateLink(
            'entry-record',
            'own_entries_only',
            '1',
            'Own entries only',
            'If selected the entry record will load only when it belongs to the current user.',
        );
        $this->updateLink(
            'entry-record',
            'scope',
            '',
            'Scope',
            'If the variable `scope` is defined, it serves as a prefix for naming the variables',
        );
        $this->deleteFieldReferences('url_param');
        $this->addSql("DELETE FROM `fields` WHERE `name` = 'url_param'");

        // form-log selects a table; plain form-record keeps its section-owned
        // name and remains scoped to the current user's single record.
        $this->unlinkField('form-log', 'name');
        $this->linkField(
            'form-log',
            'data_table',
            '',
            'Data table',
            'Data table that stores submissions for this form section.',
        );
        $this->updateLink(
            'form-record',
            'name',
            '',
            'Data table name',
            'Human-readable table name slug (the runtime table is still owned by this section id).',
        );
        $this->linkField(
            'form-record',
            'own_entries_only',
            '1',
            'Own entries only',
            'When enabled the form only ever loads and updates the current user\'s own records. Disable for shared/admin editing: another user\'s record can then be loaded and updated, but only with UPDATE permission on the form\'s data table.',
        );

        $this->addSql(<<<SQL
            INSERT INTO `styles` (`name`, `id_style_groups`, `can_have_children`, `description`, `id_render_target`)
            SELECT 'entry-record-form', `id_style_groups`, `can_have_children`, '{$this->sqlEscape(self::ENTRY_RECORD_FORM_DESCRIPTION)}', `id_render_target`
            FROM `styles`
            WHERE `name` = 'form-record'
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `rel_fields_styles`
                (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
            SELECT target_style.`id`, rfs.`id_fields`, rfs.`default_value`, rfs.`help`, rfs.`disabled`, rfs.`hidden`, rfs.`title`
            FROM `rel_fields_styles` rfs
            INNER JOIN `styles` source_style ON source_style.`id` = rfs.`id_styles`
            INNER JOIN `fields` field ON field.`id` = rfs.`id_fields`
            CROSS JOIN `styles` target_style
            WHERE source_style.`name` = 'form-record'
              AND target_style.`name` = 'entry-record-form'
              AND field.`name` NOT IN ('name', 'data_table', 'load_record_from', 'own_entries_only')
            SQL);
        $this->linkField(
            'entry-record-form',
            'data_table',
            '',
            'Data table',
            'Data table for this form. Pick an existing table or leave empty to use the table owned by this section (created automatically).',
        );
        $this->linkField(
            'entry-record-form',
            'load_record_from',
            'record_id',
            'Load record from route parameter',
            'Route parameter carrying the record id (e.g. `record_id` on `/cms/team/{record_id}`). When present the form loads that record; when absent the form stays empty (create mode).',
        );
        $this->linkField(
            'entry-record-form',
            'own_entries_only',
            '0',
            'Own entries only',
            'When enabled the form only loads/updates the current user\'s own records. Disable for shared/admin editing (foreign records need table UPDATE permission).',
        );

        $this->updateLink(
            'entry-table',
            'add_url',
            '',
            'Add new URL',
            'Page or custom URL for the create form. When set, an "Add new" button is shown above the table.',
        );
        $this->updateLink(
            'entry-table',
            'edit_url',
            '',
            'Open/edit URL',
            'Page or custom URL template for row edit (use {record_id} as placeholder).',
        );
        $this->updateLink(
            'entry-table',
            'fields_map',
            '',
            'Column mapping',
            'Ordered list of field_key values to show in the grid. Header labels are configured in Column header labels.',
        );
        $this->linkField(
            'entry-table',
            'fields_map_labels',
            '',
            'Column header labels',
            'Per-language labels keyed by field_key (e.g. {"section_230":"Name"}). The column order comes from Column mapping.',
        );
        $this->linkField(
            'entry-table',
            'show_language_preview',
            '0',
            'Show language preview',
            'When enabled, the web table shows a language selector above the grid and reloads translatable column values for the chosen locale. Mainly for CMS app content lists.',
        );

        $this->replaceSelectedColumnsProcedure();
        $this->seedQueryPreviewRoute();
    }

    public function down(Schema $schema): void
    {
        $entryRecordFormCount = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM `sections` sec
            INNER JOIN `styles` style ON style.`id` = sec.`id_styles`
            WHERE style.`name` = 'entry-record-form'
            SQL);
        $this->abortIf(
            is_numeric($entryRecordFormCount) && (int) $entryRecordFormCount > 0,
            'Refusing rollback: entry-record-form sections exist. Remove or migrate those sections first.',
        );

        $this->removeQueryPreviewRoute();
        $this->restoreBaseFilteredProcedure();

        $this->addSql(<<<'SQL'
            DELETE rfs
            FROM `rel_fields_styles` rfs
            INNER JOIN `styles` style ON style.`id` = rfs.`id_styles`
            WHERE style.`name` = 'entry-record-form'
            SQL);
        $this->addSql("DELETE FROM `styles` WHERE `name` = 'entry-record-form'");

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
            SELECT 'url_param', ft.`id`, 0, NULL
            FROM `field_types` ft
            WHERE ft.`name` = 'text'
            SQL);
        $this->linkField(
            'entry-record',
            'filter',
            null,
            '',
            'Filter the data source; Use SQL syntax',
        );
        $this->linkField(
            'entry-record',
            'url_param',
            'record_id',
            '',
            'The name of the url parameter that will be taken from the url. This parameter is used to filter the form based on the`record_id` and return one entry.',
        );
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `sections_fields_translation`
                (`id_sections`, `id_fields`, `id_languages`, `content`, `meta`)
            SELECT sft.`id_sections`, target_field.`id`, sft.`id_languages`, sft.`content`, sft.`meta`
            FROM `sections_fields_translation` sft
            INNER JOIN `sections` sec ON sec.`id` = sft.`id_sections`
            INNER JOIN `styles` style ON style.`id` = sec.`id_styles`
            INNER JOIN `fields` source_field ON source_field.`id` = sft.`id_fields`
            CROSS JOIN `fields` target_field
            WHERE style.`name` = 'entry-record'
              AND source_field.`name` = 'load_record_from'
              AND target_field.`name` = 'url_param'
            SQL);
        $this->deleteStyleFieldValues('entry-record', 'load_record_from');
        $this->unlinkField('entry-record', 'load_record_from');
        $this->updateLink(
            'entry-record',
            'data_table',
            '',
            '',
            'Select a data table which will be linked to the style',
        );
        $this->updateLink(
            'entry-record',
            'own_entries_only',
            '1',
            '',
            'If selected the entry list will load only the records entered by the user.',
        );
        $this->updateLink(
            'entry-record',
            'scope',
            '',
            '',
            'If the variable `scope` is defined, it serves as a prefix for naming the variables',
        );

        $this->unlinkField('form-log', 'data_table');
        $this->linkField(
            'form-log',
            'name',
            null,
            'Form Name',
            'Sets the form name for identification and API calls',
        );
        $this->unlinkField('form-record', 'own_entries_only');
        $this->updateLink(
            'form-record',
            'name',
            null,
            'Form Name',
            'Sets the form name for identification and API calls',
        );

        $this->unlinkField('entry-list', 'selected_columns');
        $this->updateLink(
            'entry-list',
            'data_table',
            '',
            '',
            'Select a data table which will be linked to the style',
        );
        $this->updateLink(
            'entry-list',
            'filter',
            null,
            '',
            'Filter the data source; Use SQL syntax',
        );
        $this->updateLink(
            'entry-list',
            'load_as_table',
            '0',
            '',
            'If enabled, the children are loaded inside a table.',
        );
        $this->updateLink(
            'entry-list',
            'own_entries_only',
            '1',
            '',
            'If selected the entry list will load only the records entered by the user.',
        );
        $this->updateLink(
            'entry-list',
            'scope',
            '',
            '',
            'If the variable `scope` is defined, it serves as a prefix for naming the variables',
        );

        $this->unlinkField('entry-table', 'fields_map_labels');
        $this->unlinkField('entry-table', 'show_language_preview');
        $this->updateLink(
            'entry-table',
            'fields_map',
            '',
            'Fields Map',
            'JSON array that selects, orders and relabels the columns shown. Each entry maps a column to a new header. Example: [{"field_name":"name","field_new_name":"Full name"},{"field_name":"email","field_new_name":"E-mail"}]',
        );
        $this->updateLink(
            'entry-table',
            'add_url',
            '',
            'Add new URL',
            'Page or custom URL for the create form. When set, an "Add new" button is shown above the table.',
        );
        $this->updateLink(
            'entry-table',
            'edit_url',
            '',
            'Open/edit URL',
            'Page or custom URL template for row edit (use {record_id} as placeholder).',
        );

        // These three fields are owned by Version20260710093044 and already
        // use select-page-keyword there; leave that preceding state intact.
        $this->retargetFieldType('add_url', 'select-page-keyword');
        $this->retargetFieldType('edit_url', 'select-page-keyword');
        $this->retargetFieldType('redirect_on_save', 'select-page-keyword');
        $this->retargetFieldType('dt_default_order_column', 'text');
        $this->retargetFieldType('fields_map', 'json');
        $this->retargetFieldType('filter', 'code');
        $this->addSql("UPDATE `fields` SET `display` = 1 WHERE `name` = 'fields_map'");

        $this->addSql(<<<'SQL'
            UPDATE `styles`
            SET `name` = 'show-user-input',
                `description` = 'Displays user-submitted form entries from a data table in a read-only table view.'
            WHERE `name` = 'entry-table'
            SQL);

        $this->restoreOptionCatalogs();

        foreach (self::NEW_FIELDS as $fieldName) {
            $this->deleteFieldReferences($fieldName);
            $this->addSql('DELETE FROM `fields` WHERE `name` = ?', [$fieldName]);
        }
        $this->addSql("DELETE FROM `field_types` WHERE `name` IN ('select-data_table_columns', 'select-data_table_column', 'entry-filter', 'fields-map')");

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
            SELECT 'type', ft.`id`, 0, NULL
            FROM `field_types` ft
            WHERE ft.`name` = 'style-bootstrap'
            SQL);
        $this->linkField(
            'entry-record-delete',
            'type',
            'danger',
            '',
            'The visual appearance of the button as predefined by [Bootstrap](!https://getbootstrap.com/docs/4.6/utilities/colors/).',
        );
    }

    private function configureOptionCatalogs(): void
    {
        $catalogHelp = <<<'HELP'
Define the stable option codes stored in submitted data. Enter translated display labels in the grid for each CMS language.

Example catalog:
```json
[{"value":"release","sort":1},{"value":"feature","sort":2,"disabled":false}]
```

Codes are language-neutral. Labels are saved separately per language. Generated template variables `{{_field_label}}` and `{{_field_labels}}` are read-only.
HELP;
        $labelsHelp = <<<'HELP'
Map each stable code to the translated label for this CMS language. Codes must match the option catalog.

Example labels for one language:
```json
{"release":"Release","feature":"Feature","notice":"Notice"}
```

In list/detail templates use the generated read-only fields `{{_field_label}}` (single choice) or `{{_field_labels}}` (multi choice).
HELP;

        foreach (self::OPTION_CATALOGS as $styleName => $fieldName) {
            $this->addSql(
                "UPDATE `fields` field INNER JOIN `field_types` type ON type.`name` = 'json' SET field.`display` = 0, field.`id_field_types` = type.`id` WHERE field.`name` = ?",
                [$fieldName],
            );
            $this->updateLink($styleName, $fieldName, null, 'Options', $catalogHelp, false);
            $this->linkField($styleName, 'option_labels', '{}', 'Option labels', $labelsHelp);
        }
    }

    private function restoreOptionCatalogs(): void
    {
        $baseLinks = [
            'select' => [
                'field' => 'options',
                'title' => 'Options',
                'help' => 'Sets the options for the select field as JSON array. Format: [{"value":"option1","label":"Option 1"}]',
            ],
            'radio' => [
                'field' => 'radio_options',
                'title' => 'Options',
                'help' => 'Sets the options for the radio group as JSON array. If provided, renders as Radio.Group. Format: [{"value":"1","text":"Item1","description":"Optional description"}]. For more information check https://mantine.dev/core/radio',
            ],
            'combobox' => [
                'field' => 'combobox_options',
                'title' => 'Options',
                'help' => 'Sets the data/options for the combobox as JSON array. Format: [{"value":"option1","text":"Option 1"}]. For more information check https://mantine.dev/core/combobox',
            ],
            'segmented-control' => [
                'field' => 'segmented_control_data',
                'title' => 'Data',
                'help' => 'Sets the data/options for the segmented control as JSON array. Format: [{"value":"option1","label":"Option 1"}]. For more information check https://mantine.dev/core/segmented-control',
            ],
        ];

        foreach ($baseLinks as $styleName => $link) {
            $this->addSql("UPDATE `fields` SET `display` = 1 WHERE `name` = ?", [$link['field']]);
            $this->updateLink($styleName, $link['field'], null, $link['title'], $link['help'], false);
        }
    }

    private function replaceSelectedColumnsProcedure(): void
    {
        $this->addSql('DROP PROCEDURE IF EXISTS `get_data_table_filtered`');
        $this->addSql('DROP FUNCTION IF EXISTS `build_dynamic_columns_with_selection`');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION `build_dynamic_columns_with_selection`(
                table_id_param INT,
                selected_columns_param VARCHAR(4000)
            ) RETURNS TEXT CHARSET utf8mb3
                READS SQL DATA
                DETERMINISTIC
            BEGIN
                DECLARE result_sql TEXT;
                SELECT GROUP_CONCAT(DISTINCT CONCAT('MAX(IF(col.field_key = ''', REPLACE(c.field_key, '''', ''''''), ''', cell.value, NULL)) AS `', REPLACE(c.field_key, '`', '``'), '`')
                       ORDER BY c.id SEPARATOR ', ')
                INTO result_sql
                FROM data_cols c
                WHERE c.id_data_tables = table_id_param
                  AND (
                      IFNULL(TRIM(selected_columns_param), '') = ''
                      OR FIND_IN_SET(c.field_key, selected_columns_param) > 0
                  );
                RETURN result_sql;
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE PROCEDURE `get_data_table_filtered`(
                IN table_id_param INT,
                IN user_id_param INT,
                IN filter_param VARCHAR(1000),
                IN exclude_deleted_param BOOLEAN,
                IN language_id_param INT,
                IN timezone_code_param VARCHAR(100),
                IN selected_columns_param VARCHAR(4000)
            )
                READS SQL DATA
                DETERMINISTIC
            BEGIN
                SET @@group_concat_max_len = 32000000;
                SET @sql = build_dynamic_columns_with_selection(table_id_param, selected_columns_param);

                IF (@sql IS NULL) THEN
                    SELECT `name` FROM data_tables WHERE 1=2;
                ELSE
                    BEGIN
                        SET @user_filter = '';
                        IF user_id_param > 0 THEN
                            SET @user_filter = CONCAT(' AND r.id_users = ', user_id_param);
                        END IF;

                        SET @sql = CONCAT('SELECT * FROM (SELECT r.id AS record_id, convert_entry_date_timezone(r.`timestamp`, "', timezone_code_param, '") AS entry_date, r.id_users, u.`name` AS user_name, vc.code AS user_code, r.id_action_trigger_types, l.lookup_code AS triggerType,', @sql,
                            ' FROM data_tables t
                            INNER JOIN data_rows r ON (t.id = r.id_data_tables)
                            INNER JOIN data_cells cell ON (cell.id_data_rows = r.id)
                            INNER JOIN data_cols col ON (col.id = cell.id_data_cols)
                            LEFT JOIN users u ON (r.id_users = u.id)
                            LEFT JOIN validation_codes vc ON (u.id = vc.id_users)
                            LEFT JOIN lookups l ON (l.id = r.id_action_trigger_types)
                            WHERE t.id = ', table_id_param, @user_filter, build_time_period_filter(filter_param), build_exclude_deleted_filter(exclude_deleted_param), build_language_filter(language_id_param),
                            ' GROUP BY r.id ) AS r WHERE 1=1 ', filter_param);

                        PREPARE stmt FROM @sql;
                        EXECUTE stmt;
                        DEALLOCATE PREPARE stmt;
                    END;
                END IF;
            END
            SQL);
    }

    private function restoreBaseFilteredProcedure(): void
    {
        $this->addSql('DROP PROCEDURE IF EXISTS `get_data_table_filtered`');
        $this->addSql('DROP FUNCTION IF EXISTS `build_dynamic_columns_with_selection`');
        $this->addSql(<<<'SQL'
            CREATE PROCEDURE `get_data_table_filtered`(
                IN table_id_param INT,
                IN user_id_param INT,
                IN filter_param VARCHAR(1000),
                IN exclude_deleted_param BOOLEAN,
                IN language_id_param INT,
                IN timezone_code_param VARCHAR(100)
            )
                READS SQL DATA
                DETERMINISTIC
            BEGIN
                SET @@group_concat_max_len = 32000000;
                SET @sql = build_dynamic_columns(table_id_param);

                IF (@sql IS NULL) THEN
                    SELECT `name` FROM data_tables WHERE 1=2;
                ELSE
                    BEGIN
                        SET @user_filter = '';
                        IF user_id_param > 0 THEN
                            SET @user_filter = CONCAT(' AND r.id_users = ', user_id_param);
                        END IF;

                        SET @sql = CONCAT('SELECT * FROM (SELECT r.id AS record_id, convert_entry_date_timezone(r.`timestamp`, "', timezone_code_param, '") AS entry_date, r.id_users, u.`name` AS user_name, vc.code AS user_code, r.id_action_trigger_types, l.lookup_code AS triggerType,', @sql,
                            ' FROM data_tables t
                            INNER JOIN data_rows r ON (t.id = r.id_data_tables)
                            INNER JOIN data_cells cell ON (cell.id_data_rows = r.id)
                            INNER JOIN data_cols col ON (col.id = cell.id_data_cols)
                            LEFT JOIN users u ON (r.id_users = u.id)
                            LEFT JOIN validation_codes vc ON (u.id = vc.id_users)
                            LEFT JOIN lookups l ON (l.id = r.id_action_trigger_types)
                            WHERE t.id = ', table_id_param, @user_filter, build_time_period_filter(filter_param), build_exclude_deleted_filter(exclude_deleted_param), build_language_filter(language_id_param),
                            ' GROUP BY r.id ) AS r WHERE 1=1 ', filter_param);

                        PREPARE stmt FROM @sql;
                        EXECUTE stmt;
                        DEALLOCATE PREPARE stmt;
                    END;
                END IF;
            END
            SQL);
    }

    private function seedQueryPreviewRoute(): void
    {
        $this->addSql(
            'DELETE rarp FROM `rel_api_routes_permissions` rarp INNER JOIN `api_routes` route ON route.`id` = rarp.`id_api_routes` WHERE route.`route_name` = ? AND route.`version` = ?',
            ['admin_data_query_preview_post', 'v1'],
        );
        $this->addSql(
            'DELETE FROM `api_routes` WHERE `route_name` = ? AND `version` = ?',
            ['admin_data_query_preview_post', 'v1'],
        );
        $this->addSql(
            'INSERT INTO `api_routes` (`route_name`, `version`, `path`, `controller`, `methods`, `requirements`, `params`, `id_plugins`) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
            [
                'admin_data_query_preview_post',
                'v1',
                '/admin/data/query-preview',
                'App\\Controller\\Api\\V1\\Admin\\AdminDataController::queryPreview',
                'POST',
            ],
        );
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT route.`id`, permission.`id`
            FROM `api_routes` route
            INNER JOIN `permissions` permission ON permission.`name` = ?
            WHERE route.`route_name` = ? AND route.`version` = ?
            SQL, ['admin.data.read', 'admin_data_query_preview_post', 'v1']);
    }

    private function removeQueryPreviewRoute(): void
    {
        $this->addSql(
            'DELETE rarp FROM `rel_api_routes_permissions` rarp INNER JOIN `api_routes` route ON route.`id` = rarp.`id_api_routes` WHERE route.`route_name` = ? AND route.`version` = ?',
            ['admin_data_query_preview_post', 'v1'],
        );
        $this->addSql(
            'DELETE FROM `api_routes` WHERE `route_name` = ? AND `version` = ?',
            ['admin_data_query_preview_post', 'v1'],
        );
    }

    private function linkField(
        string $styleName,
        string $fieldName,
        ?string $defaultValue,
        string $title,
        string $help,
    ): void {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `rel_fields_styles`
                (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
            SELECT style.`id`, field.`id`, ?, ?, 0, 0, ?
            FROM `styles` style
            CROSS JOIN `fields` field
            WHERE style.`name` = ? AND field.`name` = ?
            SQL, [$defaultValue, $help, $title, $styleName, $fieldName]);
    }

    private function updateLink(
        string $styleName,
        string $fieldName,
        ?string $defaultValue,
        string $title,
        string $help,
        bool $updateDefault = true,
    ): void {
        $setDefault = $updateDefault ? 'rfs.`default_value` = ?, ' : '';
        $params = $updateDefault
            ? [$defaultValue, $help, $title, $styleName, $fieldName]
            : [$help, $title, $styleName, $fieldName];
        $this->addSql(
            'UPDATE `rel_fields_styles` rfs'
            . ' INNER JOIN `styles` style ON style.`id` = rfs.`id_styles`'
            . ' INNER JOIN `fields` field ON field.`id` = rfs.`id_fields`'
            . ' SET ' . $setDefault . 'rfs.`help` = ?, rfs.`title` = ?'
            . ' WHERE style.`name` = ? AND field.`name` = ?',
            $params,
        );
    }

    private function unlinkField(string $styleName, string $fieldName): void
    {
        $this->addSql(<<<'SQL'
            DELETE rfs
            FROM `rel_fields_styles` rfs
            INNER JOIN `styles` style ON style.`id` = rfs.`id_styles`
            INNER JOIN `fields` field ON field.`id` = rfs.`id_fields`
            WHERE style.`name` = ? AND field.`name` = ?
            SQL, [$styleName, $fieldName]);
    }

    private function deleteStyleFieldValues(string $styleName, string $fieldName): void
    {
        $this->addSql(<<<'SQL'
            DELETE translation
            FROM `sections_fields_translation` translation
            INNER JOIN `sections` section ON section.`id` = translation.`id_sections`
            INNER JOIN `styles` style ON style.`id` = section.`id_styles`
            INNER JOIN `fields` field ON field.`id` = translation.`id_fields`
            WHERE style.`name` = ? AND field.`name` = ?
            SQL, [$styleName, $fieldName]);
    }

    private function deleteFieldReferences(string $fieldName): void
    {
        foreach ([
            'sections_fields_translation',
            'rel_fields_styles',
            'rel_fields_pages',
            'pages_fields_translation',
            'rel_fields_page_types',
        ] as $table) {
            $this->addSql(
                sprintf('DELETE FROM `%s` WHERE `id_fields` = (SELECT `id` FROM `fields` WHERE `name` = ?)', $table),
                [$fieldName],
            );
        }
    }

    private function retargetFieldType(string $fieldName, string $fieldTypeName): void
    {
        $this->addSql(<<<'SQL'
            UPDATE `fields` field
            INNER JOIN `field_types` type ON type.`name` = ?
            SET field.`id_field_types` = type.`id`
            WHERE field.`name` = ?
            SQL, [$fieldTypeName, $fieldName]);
    }

    private function sqlEscape(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
