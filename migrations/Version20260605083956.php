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
 * Make the profile "communication preferences" UI text CMS-manageable (issue #29).
 *
 * The web `ProfileStyle` and mobile `Profile`/fallback screens render a
 * notifications + emails preference card. They previously relied on hardcoded
 * English fallbacks. This migration adds the matching CMS label fields, links
 * them to the `profile` style with English `default_value`s, and seeds
 * en-GB / de-CH translations onto the out-of-the-box `profile-sys-profile`
 * section so admins get an editable starting point that mirrors the existing
 * name-change / password-reset / delete blocks.
 *
 * Field types follow the established profile convention: short labels use
 * `text`, the longer description blocks use `textarea`. All inserts are
 * idempotent (INSERT IGNORE on the unique field name / style-field /
 * section-field-language tuples). down() removes only the fields this
 * migration introduced.
 */
final class Version20260605083956 extends AbstractMigration
{
    private const LOCALES = ['en-GB', 'de-CH'];
    private const STYLE = 'profile';
    private const SECTION = 'profile-sys-profile';

    /**
     * One row per new label field. `type` is the field_types name.
     *
     * @return list<array{field: string, type: string, help: string, en: string, de: string}>
     */
    private function labels(): array
    {
        return [
            [
                'field' => 'profile_communication_preferences_title', 'type' => 'text',
                'help' => 'Heading of the communication-preferences card on the profile page.',
                'en' => 'Communication Preferences', 'de' => 'Kommunikationseinstellungen',
            ],
            [
                'field' => 'profile_communication_preferences_description', 'type' => 'textarea',
                'help' => 'Intro text shown under the communication-preferences heading. Account and security messages are always delivered regardless of these settings.',
                'en' => '<p>Choose which messages SelfHelp may send you. Account and security messages are always delivered.</p>',
                'de' => '<p>Wählen Sie, welche Nachrichten SelfHelp Ihnen senden darf. Konto- und Sicherheitsnachrichten werden immer zugestellt.</p>',
            ],
            [
                'field' => 'profile_receive_notifications_label', 'type' => 'text',
                'help' => 'Label of the "receive notifications" toggle.',
                'en' => 'Receive notifications', 'de' => 'Benachrichtigungen erhalten',
            ],
            [
                'field' => 'profile_receive_notifications_description', 'type' => 'textarea',
                'help' => 'Helper text under the "receive notifications" toggle.',
                'en' => 'Allow scheduled push notifications from SelfHelp.',
                'de' => 'Geplante Push-Benachrichtigungen von SelfHelp zulassen.',
            ],
            [
                'field' => 'profile_receive_emails_label', 'type' => 'text',
                'help' => 'Label of the "receive emails" toggle.',
                'en' => 'Receive emails', 'de' => 'E-Mails erhalten',
            ],
            [
                'field' => 'profile_receive_emails_description', 'type' => 'textarea',
                'help' => 'Helper text under the "receive emails" toggle.',
                'en' => 'Allow scheduled (non-essential) emails from SelfHelp.',
                'de' => 'Geplante (nicht zwingende) E-Mails von SelfHelp zulassen.',
            ],
            [
                'field' => 'profile_communication_preferences_button', 'type' => 'text',
                'help' => 'Label of the button that saves the communication preferences.',
                'en' => 'Update Preferences', 'de' => 'Einstellungen aktualisieren',
            ],
            [
                'field' => 'profile_communication_preferences_success', 'type' => 'text',
                'help' => 'Success message shown after the communication preferences are saved.',
                'en' => 'Communication preferences updated successfully!',
                'de' => 'Kommunikationseinstellungen erfolgreich aktualisiert!',
            ],
            [
                'field' => 'profile_communication_preferences_error_general', 'type' => 'text',
                'help' => 'Generic error message shown when saving the communication preferences fails.',
                'en' => 'Failed to update communication preferences. Please try again.',
                'de' => 'Aktualisierung der Kommunikationseinstellungen fehlgeschlagen. Bitte versuchen Sie es erneut.',
            ],
        ];
    }

    public function getDescription(): string
    {
        return 'Add CMS label fields for profile communication preferences (issue #29) and seed en-GB/de-CH defaults onto the profile section.';
    }

    public function up(Schema $schema): void
    {
        $style = $this->escape(self::STYLE);
        $section = $this->escape(self::SECTION);

        foreach ($this->labels() as $row) {
            $field = $this->escape($row['field']);
            $type = $this->escape($row['type']);

            // 1. The field definition (translatable).
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`)
                SELECT '{$field}', ft.id, 1
                FROM `field_types` ft
                WHERE ft.`name` = '{$type}'
            SQL);

            // 2. Link it to the profile style with an English default + help.
            $default = $this->escape($row['en']);
            $help = $this->escape($row['help']);
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `rel_fields_styles` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`)
                SELECT s.id, f.id, '{$default}', '{$help}', 0, 0
                FROM `styles` s
                JOIN `fields` f ON f.`name` = '{$field}'
                WHERE s.`name` = '{$style}'
            SQL);

            // 3. Seed the out-of-the-box profile section translations per locale.
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

        // Translations and style links would cascade off the field rows, but
        // remove them explicitly so the rollback is unambiguous.
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

    /**
     * Escape a literal for direct interpolation into a single-quoted SQL
     * string. Only handles the characters our seeded content can produce
     * (single quote, backslash); we never accept user input here.
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
