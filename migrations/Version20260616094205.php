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
 * Fix the seeded maintenance page: move the alert body from the wrong
 * `value` field to the `content` field the renderer actually reads.
 *
 * {@see Version20260615150000} seeded the operator-message alert
 * (`maintenance-sys-message`) with its body in a `value` field. The
 * `alert` style has no `value` field — its body field is `content`
 * (see the style/field catalog and the frontend `AlertStyle`, which
 * reads `style.content.content`). As a result the seeded maintenance
 * page rendered an empty alert and the `{{system.maintenance_message}}`
 * interpolation never reached the visitor, even though the chain
 * (`MaintenanceModeService` -> `VariableResolverService` ->
 * `{{system.maintenance_message}}`) was otherwise correct.
 *
 * This is a data-only fix. It "renames" the field of the existing
 * translation rows for that one section from `value` to `content`,
 * carrying whatever locales were seeded (or a later admin edit kept in
 * `value`). `INSERT IGNORE` means an instance that already has a
 * `content` row (e.g. an operator who fixed it by hand) keeps it
 * untouched; only the stray `value` rows are dropped.
 */
final class Version20260616094205 extends AbstractMigration
{
    private const SECTION = 'maintenance-sys-message';

    public function getDescription(): string
    {
        return 'Fix seeded maintenance alert body: move translation rows from the `value` field to the renderer\'s `content` field.';
    }

    public function up(Schema $schema): void
    {
        $this->moveSectionField('value', 'content');
    }

    public function down(Schema $schema): void
    {
        $this->moveSectionField('content', 'value');
    }

    /**
     * Copy every translation row of `maintenance-sys-message` from the
     * `$from` field onto the `$to` field (preserving locale + content +
     * meta), then delete the `$from` rows. `INSERT IGNORE` keeps a
     * pre-existing `$to` row intact so we never clobber a manual edit.
     */
    private function moveSectionField(string $from, string $to): void
    {
        $section = self::SECTION;

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `sections_fields_translation`
                (`id_sections`, `id_fields`, `id_languages`, `content`, `meta`)
            SELECT sft.`id_sections`, to_field.`id`, sft.`id_languages`, sft.`content`, sft.`meta`
            FROM `sections_fields_translation` sft
            JOIN `sections` sec ON sec.`id` = sft.`id_sections`
            JOIN `fields` from_field ON from_field.`id` = sft.`id_fields` AND from_field.`name` = '{$from}'
            JOIN `fields` to_field ON to_field.`name` = '{$to}'
            WHERE sec.`name` = '{$section}'
        SQL);

        $this->addSql(<<<SQL
            DELETE sft FROM `sections_fields_translation` sft
            JOIN `sections` sec ON sec.`id` = sft.`id_sections`
            JOIN `fields` f ON f.`id` = sft.`id_fields`
            WHERE sec.`name` = '{$section}' AND f.`name` = '{$from}'
        SQL);
    }
}
