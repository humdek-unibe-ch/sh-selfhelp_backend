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
 * Repair: entry-record must use `load_record_from` (not `url_param` / `filter`).
 *
 * Version20260710093048 UP performs this swap, but some databases marked that
 * migration executed while the field-link steps were incomplete. CMS App public
 * detail scaffolds then omit `load_record_from`. Idempotent.
 */
final class Version20260710163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure entry-record uses load_record_from and drops url_param/filter (idempotent repair).';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `rel_fields_styles`
                (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
            SELECT s.`id`, f.`id`, 'record_id', 'The name of the url parameter.', 0, 0, 'Load record from route parameter'
            FROM `styles` s
            CROSS JOIN `fields` f
            WHERE s.`name` = 'entry-record'
              AND f.`name` = 'load_record_from'
            SQL);

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
        $this->write('Skipping down(): entry-record field contract repair is non-destructive.');
    }
}
