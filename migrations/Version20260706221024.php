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
 * CMS-in-CMS catalog cleanup (issue #30 polish wave).
 *
 * 1. Renames the `show-user-input` style to `entry-table`: it is the built-in
 *    admin data grid of the entry-* family (search/sort/pagination/CSV plus
 *    add/edit/delete affordances), and the old name predates that role.
 *    Sections reference styles by id, so this is a pure metadata rename.
 * 2. Removes the legacy pre-`data_config` binding fields from the entry
 *    holders. `entry-list` / `entry-record` / `loop` bind their rows through
 *    `data_config` exclusively (server-side hydration, see
 *    PageService::processSectionsRecursively); the standalone `data_table`,
 *    `filter`, `scope`, `load_as_table`, `url_param` and holder-level
 *    `own_entries_only` fields are read by no code path and only mislead
 *    authors in the section editor. Stored section values for the dropped
 *    links are deleted with them.
 * 3. Drops the `type` (legacy Bootstrap button variant) link from
 *    `entry-record-delete` — the web/mobile renderers style the button
 *    themselves and never read it. `own_entries_only` stays: the delete
 *    permission rule reads it.
 * 4. Field definitions that end up unlinked (`filter`, `load_as_table`,
 *    `url_param`, `type`) are removed entirely; `data_table`,
 *    `own_entries_only` and `scope` stay (still used by `entry-table` /
 *    `entry-record-delete` / `data-container`).
 * 5. Gives `form-record` its record edit mode (issue #30 polish wave): a new
 *    `load_record_from` property (route param name carrying the record id to
 *    load/edit) plus an `own_entries_only` link (default on). With
 *    own_entries_only off, loading + updating another user's record
 *    additionally requires UPDATE permission on the form's data table
 *    (DataAccessSecurityService::canUpdateOwnedRecord).
 */
final class Version20260706221024 extends AbstractMigration
{
    private const NEW_STYLE_NAME = 'entry-table';
    private const OLD_STYLE_NAME = 'show-user-input';

    private const NEW_DESCRIPTION = 'Built-in admin data grid for a form\'s entries: search, sorting, pagination, CSV export and add/edit/delete row actions.';
    private const OLD_DESCRIPTION = 'Displays user-submitted form entries from a data table in a read-only table view.';

    /**
     * Legacy style/field links removed by this migration, with the seed
     * attributes needed to restore them in down().
     *
     * @var list<array{style: string, field: string, default_value: string|null, help: string}>
     */
    private const DROPPED_LINKS = [
        ['style' => 'entry-list', 'field' => 'data_table', 'default_value' => '', 'help' => 'Select a data table which will be linked to the style'],
        ['style' => 'entry-list', 'field' => 'filter', 'default_value' => null, 'help' => 'Filter the data source; Use SQL syntax'],
        ['style' => 'entry-list', 'field' => 'load_as_table', 'default_value' => '0', 'help' => 'If enabled, the children are loaded inside a table.'],
        ['style' => 'entry-list', 'field' => 'own_entries_only', 'default_value' => '1', 'help' => 'If selected the entry list will load only the records entered by the user.'],
        ['style' => 'entry-list', 'field' => 'scope', 'default_value' => '', 'help' => 'If the variable `scope` is defined, it serves as a prefix for naming the variables'],
        ['style' => 'entry-record', 'field' => 'data_table', 'default_value' => '', 'help' => 'Select a data table which will be linked to the style'],
        ['style' => 'entry-record', 'field' => 'filter', 'default_value' => null, 'help' => 'Filter the data source; Use SQL syntax'],
        ['style' => 'entry-record', 'field' => 'own_entries_only', 'default_value' => '1', 'help' => 'If selected the entry list will load only the records entered by the user.'],
        ['style' => 'entry-record', 'field' => 'scope', 'default_value' => '', 'help' => 'If the variable `scope` is defined, it serves as a prefix for naming the variables'],
        ['style' => 'entry-record', 'field' => 'url_param', 'default_value' => 'record_id', 'help' => 'The name of the url parameter that will be taken from the url. This parameter is used to filter the form based on the`record_id` and return one entry.'],
        ['style' => 'loop', 'field' => 'scope', 'default_value' => '', 'help' => 'If the variable `scope` is defined, it serves as a prefix for naming the variables'],
        ['style' => 'entry-record-delete', 'field' => 'type', 'default_value' => 'danger', 'help' => 'The visual appearance of the button as predefined by [Bootstrap](!https://getbootstrap.com/docs/4.6/utilities/colors/).'],
    ];

    /**
     * Field definitions left without any style link once the legacy links are
     * gone. Deleting the field cascades to rel_fields_styles and
     * sections_fields_translation.
     *
     * @var array<string, string> field name => field type name
     */
    private const ORPHANED_FIELDS = [
        'filter' => 'code',
        'load_as_table' => 'checkbox',
        'url_param' => 'text',
        'type' => 'style-bootstrap',
    ];

    private const LOAD_RECORD_FROM_HELP = 'Route parameter name that carries the record id to load into this form (e.g. `record_id` for a page URL like /cms/team/{record_id}). When set and the parameter is present, the form loads THAT record for editing (all languages) instead of the user\'s latest own record. Leave empty for the default behavior.';

    private const FORM_RECORD_OWN_ENTRIES_HELP = 'When enabled the form only ever loads and updates the current user\'s own records. Disable for shared/admin editing: another user\'s record can then be loaded and updated, but only with UPDATE permission on the form\'s data table.';

    public function getDescription(): string
    {
        return 'Rename show-user-input to entry-table; drop legacy pre-data_config binding fields from the entry holders; add form-record record edit mode fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf(
            "UPDATE styles SET name = '%s', description = '%s' WHERE name = '%s'",
            self::NEW_STYLE_NAME,
            str_replace("'", "''", self::NEW_DESCRIPTION),
            self::OLD_STYLE_NAME
        ));

        // Delete the stored section values of every dropped link whose field
        // definition survives (the orphaned fields cascade on field delete).
        foreach (self::DROPPED_LINKS as $link) {
            if (isset(self::ORPHANED_FIELDS[$link['field']])) {
                continue;
            }
            $this->addSql(sprintf(
                'DELETE sft FROM sections_fields_translation sft'
                . ' JOIN sections sec ON sec.id = sft.id_sections'
                . ' JOIN styles s ON s.id = sec.id_styles'
                . ' JOIN fields f ON f.id = sft.id_fields'
                . " WHERE s.name = '%s' AND f.name = '%s'",
                $link['style'],
                $link['field']
            ));
            $this->addSql(sprintf(
                'DELETE rfs FROM rel_fields_styles rfs'
                . ' JOIN styles s ON s.id = rfs.id_styles'
                . ' JOIN fields f ON f.id = rfs.id_fields'
                . " WHERE s.name = '%s' AND f.name = '%s'",
                $link['style'],
                $link['field']
            ));
        }

        // FK ON DELETE CASCADE clears rel_fields_styles + stored values.
        $names = implode(', ', array_map(
            static fn (string $n): string => "'" . $n . "'",
            array_keys(self::ORPHANED_FIELDS)
        ));
        $this->addSql(sprintf('DELETE FROM fields WHERE name IN (%s)', $names));

        // form-record record edit mode: `load_record_from` (new internal text
        // property) + an `own_entries_only` link (existing field definition).
        $this->addSql(
            "INSERT INTO fields (name, id_field_types, display, config)"
            . " SELECT 'load_record_from', ft.id, 0, NULL FROM field_types ft WHERE ft.name = 'text'"
        );
        $this->addSql(sprintf(
            'INSERT INTO rel_fields_styles (id_styles, id_fields, default_value, help, disabled, hidden, title)'
            . " SELECT s.id, f.id, '', '%s', 0, 0, 'Load record from route parameter'"
            . " FROM styles s, fields f WHERE s.name = 'form-record' AND f.name = 'load_record_from'",
            str_replace("'", "''", self::LOAD_RECORD_FROM_HELP)
        ));
        $this->addSql(sprintf(
            'INSERT INTO rel_fields_styles (id_styles, id_fields, default_value, help, disabled, hidden, title)'
            . " SELECT s.id, f.id, '1', '%s', 0, 0, 'Own entries only'"
            . " FROM styles s, fields f WHERE s.name = 'form-record' AND f.name = 'own_entries_only'",
            str_replace("'", "''", self::FORM_RECORD_OWN_ENTRIES_HELP)
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf(
            "UPDATE styles SET name = '%s', description = '%s' WHERE name = '%s'",
            self::OLD_STYLE_NAME,
            str_replace("'", "''", self::OLD_DESCRIPTION),
            self::NEW_STYLE_NAME
        ));

        // Remove the form-record record edit mode fields. Deleting the
        // `load_record_from` field cascades its link + stored values; the
        // shared `own_entries_only` field only loses the form-record link.
        $this->addSql("DELETE FROM fields WHERE name = 'load_record_from'");
        $this->addSql(
            'DELETE sft FROM sections_fields_translation sft'
            . ' JOIN sections sec ON sec.id = sft.id_sections'
            . ' JOIN styles s ON s.id = sec.id_styles'
            . ' JOIN fields f ON f.id = sft.id_fields'
            . " WHERE s.name = 'form-record' AND f.name = 'own_entries_only'"
        );
        $this->addSql(
            'DELETE rfs FROM rel_fields_styles rfs'
            . ' JOIN styles s ON s.id = rfs.id_styles'
            . ' JOIN fields f ON f.id = rfs.id_fields'
            . " WHERE s.name = 'form-record' AND f.name = 'own_entries_only'"
        );

        // Recreate the removed field definitions (all internal, display=0).
        foreach (self::ORPHANED_FIELDS as $name => $type) {
            $this->addSql(sprintf(
                "INSERT INTO fields (name, id_field_types, display, config)"
                . " SELECT '%s', ft.id, 0, NULL FROM field_types ft WHERE ft.name = '%s'",
                $name,
                $type
            ));
        }

        // Relink every dropped style/field pair with its seed attributes.
        // Stored section values deleted by up() are not restorable (data loss
        // accepted: the fields were read by no code path).
        foreach (self::DROPPED_LINKS as $link) {
            $this->addSql(sprintf(
                'INSERT INTO rel_fields_styles (id_styles, id_fields, default_value, help, disabled, hidden, title)'
                . " SELECT s.id, f.id, %s, '%s', 0, 0, ''"
                . " FROM styles s, fields f WHERE s.name = '%s' AND f.name = '%s'",
                $link['default_value'] === null ? 'NULL' : "'" . str_replace("'", "''", $link['default_value']) . "'",
                str_replace("'", "''", $link['help']),
                $link['style'],
                $link['field']
            ));
        }
    }
}
