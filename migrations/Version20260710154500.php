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
 * Repair: ensure entry-record / entry-record-form field contracts after
 * Version20260710093048 when that migration was marked executed but its
 * INSERT…SELECT / field-link steps produced incomplete rows (collapsed WIP).
 *
 * Idempotent: safe on clean installs that already applied 93048 fully.
 */
final class Version20260710154500 extends AbstractMigration
{
    private const DESCRIPTION = 'Route-aware form for CMS and public surfaces: blank route creates a row; a route record id loads that row for edit (permission-gated).';

    public function getDescription(): string
    {
        return 'Ensure entry-record / entry-record-form styles and load_record_from field links exist (idempotent repair).';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT INTO `styles` (`name`, `id_style_groups`, `can_have_children`, `description`, `id_render_target`)
            SELECT 'entry-record-form', src.`id_style_groups`, src.`can_have_children`, '{$this->sqlEscape(self::DESCRIPTION)}', src.`id_render_target`
            FROM `styles` src
            WHERE src.`name` = 'form-record'
              AND NOT EXISTS (
                  SELECT 1 FROM `styles` existing WHERE existing.`name` = 'entry-record-form'
              )
            LIMIT 1
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

        $this->ensureFieldLink(
            'entry-record-form',
            'data_table',
            '',
            'Data table',
            'Data table for this form. Pick an existing table or leave empty to use the table owned by this section (created automatically).',
        );
        $this->ensureFieldLink(
            'entry-record-form',
            'load_record_from',
            'record_id',
            'Load record from route parameter',
            'Route parameter carrying the record id (e.g. `record_id` on `/cms/team/{record_id}`). When present the form loads that record; when absent the form stays empty (create mode).',
        );
        $this->ensureFieldLink(
            'entry-record-form',
            'own_entries_only',
            '0',
            'Own entries only',
            'When enabled the form only loads/updates the current user\'s own records. Disable for shared/admin editing (foreign records need table UPDATE permission).',
        );

        // entry-record: same route-param contract as the edit form (93048 UP).
        $this->ensureFieldLink(
            'entry-record',
            'load_record_from',
            'record_id',
            'Load record from route parameter',
            'The name of the url parameter.',
        );
        $this->addSql(<<<'SQL'
            DELETE rfs FROM `rel_fields_styles` rfs
            INNER JOIN `styles` style ON style.`id` = rfs.`id_styles`
            INNER JOIN `fields` field ON field.`id` = rfs.`id_fields`
            WHERE style.`name` = 'entry-record'
              AND field.`name` IN ('url_param', 'filter')
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Non-destructive repair — do not drop styles/links on down (may be in use).
        $this->write('Skipping down(): entry-record / entry-record-form may be referenced by sections.');
    }

    private function ensureFieldLink(
        string $styleName,
        string $fieldName,
        string $defaultValue,
        string $title,
        string $help,
    ): void {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_fields_styles`
                (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
            SELECT s.`id`, f.`id`, '{$this->sqlEscape($defaultValue)}', '{$this->sqlEscape($help)}', 0, 0, '{$this->sqlEscape($title)}'
            FROM `styles` s
            CROSS JOIN `fields` f
            WHERE s.`name` = '{$this->sqlEscape($styleName)}'
              AND f.`name` = '{$this->sqlEscape($fieldName)}'
            SQL);
    }

    private function sqlEscape(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
