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
 * Make the brute-force "too many attempts" messages CMS-manageable.
 *
 * Adds the translatable `alert_rate_limited` text field, links it to the
 * `login`, `two-factor-auth` and `reset-password` styles with an English
 * `default_value`, and seeds en-GB / de-CH content onto their seeded system
 * sections. The renderers show it when the AuthController rate limiter answers
 * 429; `{minutes}` is replaced with the remaining wait, rounded up.
 *
 * Idempotent (INSERT IGNORE). down() removes only the field it introduced
 * (its style links and translations go with it).
 */
final class Version20261027085407 extends AbstractMigration
{
    private const FIELD = 'alert_rate_limited';
    private const FIELD_TYPE = 'text';

    /**
     * One row per style: its seeded system section and the seeded texts.
     *
     * @return list<array{style: string, section: string, help: string, en: string, de: string}>
     */
    private function styles(): array
    {
        $signIn = [
            'help' => 'Message shown when too many failed attempts block sign-in for a while. Use {minutes} for the remaining wait.',
            'en' => 'Too many failed attempts. Please try again in {minutes} min.',
            'de' => 'Zu viele fehlgeschlagene Versuche. Bitte versuchen Sie es in {minutes} Min. erneut.',
        ];

        return [
            ['style' => 'login', 'section' => 'login-sys-form'] + $signIn,
            ['style' => 'two-factor-auth', 'section' => 'twofa-sys-form'] + $signIn,
            [
                'style' => 'reset-password', 'section' => 'reset-sys-form',
                'help' => 'Message shown when too many password-reset emails were requested for an address. Use {minutes} for the remaining wait.',
                'en' => 'Too many reset requests. Please try again in {minutes} min.',
                'de' => 'Zu viele Anfragen zum Zurücksetzen. Bitte versuchen Sie es in {minutes} Min. erneut.',
            ],
        ];
    }

    public function getDescription(): string
    {
        return 'Add the CMS alert_rate_limited field to the login, two-factor-auth and reset-password styles and seed en-GB/de-CH defaults.';
    }

    public function up(Schema $schema): void
    {
        $field = self::FIELD;
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`)
            SELECT '{$field}', ft.id, 1
            FROM `field_types` ft
            WHERE ft.`name` = '{$this->escape(self::FIELD_TYPE)}'
        SQL);

        foreach ($this->styles() as $row) {
            $style = $this->escape($row['style']);
            $section = $this->escape($row['section']);
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `rel_fields_styles` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`)
                SELECT s.id, f.id, '{$this->escape($row['en'])}', '{$this->escape($row['help'])}', 0, 0
                FROM `styles` s
                JOIN `fields` f ON f.`name` = '{$field}'
                WHERE s.`name` = '{$style}'
            SQL);

            foreach (['en-GB' => $row['en'], 'de-CH' => $row['de']] as $locale => $content) {
                $this->addSql(<<<SQL
                    INSERT IGNORE INTO `sections_fields_translation` (`id_sections`, `id_fields`, `id_languages`, `content`, `meta`)
                    SELECT sec.id, f.id, l.id, '{$this->escape($content)}', NULL
                    FROM `sections` sec
                    JOIN `fields` f ON f.`name` = '{$field}'
                    JOIN `languages` l ON l.`locale` = '{$locale}'
                    WHERE sec.`name` = '{$section}'
                SQL);
            }
        }
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

    /**
     * Escape a literal for a single-quoted SQL string (our own seed text only).
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
