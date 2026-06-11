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
 * Make the reset-password "set a new password" UI CMS-manageable.
 *
 * The existing `resetPassword` style already powers both reset modes:
 *   - `/reset`             -> request a recovery mail
 *   - `/reset/{id}/{token}` -> choose a new password
 *
 * Until now only the request-mail screen had CMS-backed fields; the
 * set-password form still relied on hardcoded labels / messages in the web
 * renderer. This migration adds the missing reset-mode fields, links them to
 * the `resetPassword` style with English defaults, and seeds en-GB / de-CH
 * translations onto the shipped `reset-sys-form` section so new installs have
 * a complete editable flow out of the box.
 */
final class Version20260608075829 extends AbstractMigration
{
    private const LOCALES = ['en-GB', 'de-CH'];
    private const STYLE = 'resetPassword';
    private const SECTION = 'reset-sys-form';

    /**
     * @return list<array{field: string, type: string, help: string, en: string, de: string}>
     */
    private function labels(): array
    {
        return [
            [
                'field' => 'reset_title',
                'type' => 'text',
                'help' => 'Heading shown above the set-new-password form after the user opens a valid recovery link.',
                'en' => 'Set a new password',
                'de' => 'Neues Passwort festlegen',
            ],
            [
                'field' => 'reset_label_pw',
                'type' => 'text',
                'help' => 'Label shown above the new-password input on the set-password form.',
                'en' => 'New password',
                'de' => 'Neues Passwort',
            ],
            [
                'field' => 'reset_pw_placeholder',
                'type' => 'text',
                'help' => 'Placeholder shown inside the new-password input on the set-password form.',
                'en' => 'Choose a new password',
                'de' => 'Neues Passwort wahlen',
            ],
            [
                'field' => 'reset_label_pw_confirm',
                'type' => 'text',
                'help' => 'Label shown above the confirm-password input on the set-password form.',
                'en' => 'Confirm new password',
                'de' => 'Neues Passwort bestatigen',
            ],
            [
                'field' => 'reset_pw_confirm_placeholder',
                'type' => 'text',
                'help' => 'Placeholder shown inside the confirm-password input on the set-password form.',
                'en' => 'Repeat your new password',
                'de' => 'Neues Passwort wiederholen',
            ],
            [
                'field' => 'reset_label_submit',
                'type' => 'text',
                'help' => 'Label of the button that submits the new password.',
                'en' => 'Set new password',
                'de' => 'Neues Passwort speichern',
            ],
            [
                'field' => 'reset_success_title',
                'type' => 'text',
                'help' => 'Alert title shown after the password has been reset successfully.',
                'en' => 'Password updated',
                'de' => 'Passwort aktualisiert',
            ],
            [
                'field' => 'reset_alert_success',
                'type' => 'text',
                'help' => 'Alert body shown after the password has been reset successfully.',
                'en' => 'Your password has been reset.',
                'de' => 'Ihr Passwort wurde zuruckgesetzt.',
            ],
            [
                'field' => 'reset_redirect_text',
                'type' => 'text',
                'help' => 'Shown after a successful password reset while redirecting to login. Use {seconds} as the countdown placeholder.',
                'en' => 'Redirecting to sign in in {seconds}s...',
                'de' => 'Weiterleitung zur Anmeldung in {seconds}s ...',
            ],
            [
                'field' => 'reset_error_invalid_token',
                'type' => 'text',
                'help' => 'Fallback message shown when the recovery token is invalid or has expired.',
                'en' => 'This reset link is invalid or has expired. Please request a new one.',
                'de' => 'Dieser Reset-Link ist ungultig oder abgelaufen. Bitte fordern Sie einen neuen an.',
            ],
            [
                'field' => 'reset_error_pw_short',
                'type' => 'text',
                'help' => 'Validation message shown when the new password is shorter than the minimum length.',
                'en' => 'Your new password must be at least 8 characters long.',
                'de' => 'Ihr neues Passwort muss mindestens 8 Zeichen lang sein.',
            ],
            [
                'field' => 'reset_error_pw_mismatch',
                'type' => 'text',
                'help' => 'Validation message shown when the two entered passwords do not match.',
                'en' => 'The two passwords do not match.',
                'de' => 'Die beiden Passworter stimmen nicht uberein.',
            ],
        ];
    }

    public function getDescription(): string
    {
        return 'Add CMS label fields for the reset-password set-password screen and seed en-GB/de-CH defaults onto the reset system section.';
    }

    public function up(Schema $schema): void
    {
        $style = $this->escape(self::STYLE);
        $section = $this->escape(self::SECTION);

        foreach ($this->labels() as $row) {
            $field = $this->escape($row['field']);
            $type = $this->escape($row['type']);

            $this->addSql(<<<SQL
                INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`)
                SELECT '{$field}', ft.id, 1
                FROM `field_types` ft
                WHERE ft.`name` = '{$type}'
            SQL);

            $default = $this->escape($row['en']);
            $help = $this->escape($row['help']);
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `rel_fields_styles` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`)
                SELECT s.id, f.id, '{$default}', '{$help}', 0, 0
                FROM `styles` s
                JOIN `fields` f ON f.`name` = '{$field}'
                WHERE s.`name` = '{$style}'
            SQL);

            foreach (self::LOCALES as $locale) {
                $content = $this->escape($locale === 'de-CH' ? $row['de'] : $row['en']);
                $loc = $this->escape($locale);
                $this->addSql(<<<SQL
                    INSERT IGNORE INTO `sections_fields_translation` (`id_sections`, `id_fields`, `id_languages`, `content`, `meta`)
                    SELECT sec.id, f.id, l.id, '{$content}', NULL
                    FROM `sections` sec
                    JOIN `fields` f ON f.`name` = '{$field}'
                    JOIN `languages` l ON l.`locale` = '{$loc}'
                    WHERE sec.`name` = '{$section}'
                SQL);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $names = array_map(fn(array $row): string => "'" . $this->escape($row['field']) . "'", $this->labels());
        $inList = implode(', ', $names);

        $this->addSql(<<<SQL
            DELETE sft FROM `sections_fields_translation` sft
            JOIN `fields` f ON f.id = sft.id_fields
            WHERE f.`name` IN ({$inList})
        SQL);

        $this->addSql(<<<SQL
            DELETE rfs FROM `rel_fields_styles` rfs
            JOIN `fields` f ON f.id = rfs.id_fields
            WHERE f.`name` IN ({$inList})
        SQL);

        $this->addSql("DELETE FROM `fields` WHERE `name` IN ({$inList})");
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
