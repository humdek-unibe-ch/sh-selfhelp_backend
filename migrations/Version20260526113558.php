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
 * Replaces the `type` field with `mantine_color` in the styles that used it
 * (login, register, resetPassword, entryRecordDelete), matching the same
 * color-picker setup used by the button style.
 *
 * Also changes `anonymous_users_registration` on the register style from
 * `markdown` to `textarea`.
 *
 * The `type` field itself is left untouched; only the rel_fields_styles links
 * are swapped.
 *
 * Down restores the original links.
 */
final class Version20260526113558 extends AbstractMigration
{
    /** Styles where `type` is replaced by `mantine_color`. */
    private const STYLES_WITH_TYPE = ['login', 'register', 'resetPassword'];

    public function getDescription(): string
    {
        return 'Replace `type` field with `mantine_color` in auth/entry styles; change anonymous_users_registration to textarea on register style.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::STYLES_WITH_TYPE as $style) {
            $this->addSql(<<<SQL
                UPDATE `rel_fields_styles` rfs
                JOIN `styles` s  ON s.id  = rfs.id_styles
                JOIN `fields` f  ON f.id  = rfs.id_fields
                JOIN `fields` fc ON fc.`name` = 'mantine_color'
                SET rfs.id_fields = fc.id
                WHERE s.`name` = '{$style}' AND f.`name` = 'type'
            SQL);
        }

        // Align title and help on the mantine_color link to match the button style.
        $this->addSql(<<<SQL
            UPDATE `rel_fields_styles` rfs
            JOIN `styles` s ON s.id = rfs.id_styles
            JOIN `fields` f ON f.id = rfs.id_fields
            SET rfs.`title` = 'Color',
                rfs.`help`  = 'Select the color for the submit button. For more information check https://mantine.dev/theming/colors/'
            WHERE s.`name` IN ('login', 'register', 'resetPassword')
              AND f.`name` = 'mantine_color'
        SQL);

        $this->addSql(<<<SQL
            UPDATE `fields` f
            JOIN `field_types` ft ON ft.`name` = 'textarea'
            SET f.`id_field_types` = ft.id
            WHERE f.`name` = 'anonymous_users_registration'
        SQL);

        // Seed use_mantine_style = 1 (hidden) on the affected styles so the
        // Mantine properties panel appears, matching the button style setup.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_fields_styles` (`id_styles`, `id_fields`, `default_value`, `hidden`)
            SELECT s.id, f.id, '1', 1
            FROM `styles` s, `fields` f
            WHERE s.`name` IN ('login', 'register', 'resetPassword')
              AND f.`name` = 'use_mantine_style'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE rfs FROM `rel_fields_styles` rfs
            JOIN `styles` s ON s.id = rfs.id_styles
            JOIN `fields` f ON f.id = rfs.id_fields
            WHERE s.`name` IN ('login', 'register', 'resetPassword')
              AND f.`name` = 'use_mantine_style'
        SQL);

        foreach (self::STYLES_WITH_TYPE as $style) {
            $this->addSql(<<<SQL
                UPDATE `rel_fields_styles` rfs
                JOIN `styles` s  ON s.id  = rfs.id_styles
                JOIN `fields` f  ON f.id  = rfs.id_fields
                JOIN `fields` fo ON fo.`name` = 'type'
                SET rfs.id_fields = fo.id
                WHERE s.`name` = '{$style}' AND f.`name` = 'mantine_color'
            SQL);
        }

        $this->addSql(<<<SQL
            UPDATE `fields` f
            JOIN `field_types` ft ON ft.`name` = 'markdown'
            SET f.`id_field_types` = ft.id
            WHERE f.`name` = 'anonymous_users_registration'
        SQL);
    }
}
