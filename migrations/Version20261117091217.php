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
 * Make `entry-form-list` its own form: copy the form authoring fields from
 * `form-record` onto the list and update its description. No content is
 * converted; existing sections keep their stored values.
 */
final class Version20261117091217 extends AbstractMigration
{
    private const OLD_DESCRIPTION = 'A list of the records of a form with search, sort, filters and paging. A visitor adds, views, edits and deletes records in a modal or on a page; by default only their own. Put the form that creates and edits the records inside it.';

    private const NEW_DESCRIPTION = 'A list of records with search, sort, filters and paging that is also the form that creates and edits them: put the inputs directly inside it. Leave Data table empty to own the table; set it to another table to only delete and run quick actions.';

    /** Form fields copied from form-record onto entry-form-list (contract item 1). */
    private const FORM_FIELDS = [
        'name',
        'btn_save_label',
        'btn_save_color',
        'btn_update_label',
        'btn_update_color',
        'btn_cancel_label',
        'btn_cancel_color',
        'alert_success',
        'alert_success_title',
        'alert_error',
        'alert_error_title',
        'confirm_submit',
        'confirm_message',
        'buttons_size',
        'buttons_radius',
        'buttons_variant',
        'buttons_position',
        'buttons_order',
        'redirect_at_end',
    ];

    public function getDescription(): string
    {
        return 'Link the form fields of form-record onto entry-form-list and update its description (list is the form).';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function up(Schema $schema): void
    {
        $fieldList = "'" . implode("','", self::FORM_FIELDS) . "'";
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_fields_styles`
                (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
            SELECT target_style.`id`, rfs.`id_fields`, rfs.`default_value`, rfs.`help`, rfs.`disabled`, rfs.`hidden`, rfs.`title`
            FROM `rel_fields_styles` rfs
            INNER JOIN `styles` source_style ON source_style.`id` = rfs.`id_styles`
            INNER JOIN `fields` field ON field.`id` = rfs.`id_fields`
            CROSS JOIN `styles` target_style
            WHERE source_style.`name` = 'form-record'
              AND target_style.`name` = 'entry-form-list'
              AND field.`name` IN ({$fieldList})
            SQL);

        $this->addSql(
            'UPDATE `styles` SET `description` = ? WHERE `name` = ?',
            [self::NEW_DESCRIPTION, 'entry-form-list'],
        );

        // Data table help: empty = own table; set = read-only override.
        $this->addSql(<<<'SQL'
            UPDATE `rel_fields_styles` rfs
            INNER JOIN `styles` st ON st.`id` = rfs.`id_styles`
            INNER JOIN `fields` f ON f.`id` = rfs.`id_fields`
            SET rfs.`help` = 'Leave empty so this list owns its table (created on first save). Set to another table to show that table and only delete or run quick actions (add and edit stay off; no inputs).'
            WHERE st.`name` = 'entry-form-list' AND f.`name` = 'data_table'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $fieldList = "'" . implode("','", self::FORM_FIELDS) . "'";
        $this->addSql(<<<SQL
            DELETE rfs FROM `rel_fields_styles` rfs
            INNER JOIN `styles` st ON st.`id` = rfs.`id_styles`
            INNER JOIN `fields` f ON f.`id` = rfs.`id_fields`
            WHERE st.`name` = 'entry-form-list'
              AND f.`name` IN ({$fieldList})
            SQL);

        $this->addSql(
            'UPDATE `styles` SET `description` = ? WHERE `name` = ?',
            [self::OLD_DESCRIPTION, 'entry-form-list'],
        );

        $this->addSql(<<<'SQL'
            UPDATE `rel_fields_styles` rfs
            INNER JOIN `styles` st ON st.`id` = rfs.`id_styles`
            INNER JOIN `fields` f ON f.`id` = rfs.`id_fields`
            SET rfs.`help` = 'The table the list reads. Leave empty to use the table of the form inside the list.'
            WHERE st.`name` = 'entry-form-list' AND f.`name` = 'data_table'
            SQL);
    }
}
