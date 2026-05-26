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
 * Migrates the shared `type` field from the legacy `style-bootstrap` field
 * type to `color-picker` with the standard Mantine colour palette options.
 *
 * Affects every style that uses the `type` field:
 * login, register, resetPassword, entryRecordDelete.
 *
 * Down reverts the field type to `style-bootstrap` and clears the config.
 */
final class Version20260526113558 extends AbstractMigration
{
    private const CONFIG = '{"options": [{"text": "Gray", "value": "gray"}, {"text": "Red", "value": "red"}, {"text": "Grape", "value": "grape"}, {"text": "Violet", "value": "violet"}, {"text": "Blue", "value": "blue"}, {"text": "Cyan", "value": "cyan"}, {"text": "Green", "value": "green"}, {"text": "Lime", "value": "lime"}, {"text": "Yellow", "value": "yellow"}, {"text": "Orange", "value": "orange"}]}';

    public function getDescription(): string
    {
        return 'Migrate the shared `type` field from style-bootstrap to color-picker with Mantine palette options.';
    }

    public function up(Schema $schema): void
    {
        $config = self::CONFIG;
        $this->addSql(<<<SQL
            UPDATE `fields` f
            JOIN `field_types` ft ON ft.`name` = 'color-picker'
            SET f.`id_field_types` = ft.id, f.`config` = '{$config}'
            WHERE f.`name` = 'type'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE `fields` f
            JOIN `field_types` ft ON ft.`name` = 'style-bootstrap'
            SET f.`id_field_types` = ft.id, f.`config` = NULL
            WHERE f.`name` = 'type'
        SQL);
    }
}
