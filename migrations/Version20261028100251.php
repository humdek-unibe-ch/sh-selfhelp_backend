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
 * Add the `files_public` checkbox to the `file-input` style.
 *
 * Form uploads are private by default (owner + data-table readers only). When
 * this is on, FormFileController serves the field's files to anyone, e.g. photos
 * shown on a public page. It is read on every request, so switching it applies
 * to files uploaded before and after the change.
 *
 * Idempotent (INSERT IGNORE). down() removes only the field it introduced.
 */
final class Version20261028100251 extends AbstractMigration
{
    private const FIELD = 'files_public';

    public function getDescription(): string
    {
        return 'Add the files_public checkbox to the file-input style (form uploads are private by default).';
    }

    public function up(Schema $schema): void
    {
        $field = self::FIELD;
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`)
            SELECT '{$field}', ft.id, 0
            FROM `field_types` ft
            WHERE ft.`name` = 'checkbox'
        SQL);
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_fields_styles` (`id_styles`, `id_fields`, `title`, `default_value`, `help`, `disabled`, `hidden`)
            SELECT s.id, f.id, 'Files Public', '0', 'Make files uploaded through this field readable by anyone, for example images shown on a public page. Off: only the uploader and users who can read the form data can open them. Applies to all files of this field, old and new.', 0, 0
            FROM `styles` s
            JOIN `fields` f ON f.`name` = '{$field}'
            WHERE s.`name` = 'file-input'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $field = self::FIELD;
        $this->addSql(<<<SQL
            DELETE sft FROM `sections_fields_translation` sft
            JOIN `fields` f ON f.id = sft.id_fields
            WHERE f.`name` = '{$field}'
        SQL);
        $this->addSql(<<<SQL
            DELETE rfs FROM `rel_fields_styles` rfs
            JOIN `fields` f ON f.id = rfs.id_fields
            WHERE f.`name` = '{$field}'
        SQL);
        $this->addSql("DELETE FROM `fields` WHERE `name` = '{$field}'");
    }
}
