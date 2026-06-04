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
 * Make the hardcoded registration-lifecycle UI text CMS-manageable.
 *
 * Adds CMS label fields that the frontend previously hardcoded and links
 * them to the `register`, `login` and `validate` styles with English
 * `default_value`s, then seeds en-GB / de-CH translations onto the three
 * seeded auth sections (`register-sys-form`, `login-sys-form`,
 * `validate-sys-form`) so admins get an editable starting point:
 *
 *   - register: the validation-code label + placeholder and the two
 *     post-registration buttons (Go Home / Go to Login).
 *   - login:    the "create account" registration-link label.
 *   - validate: the activation lifecycle status text users see after the
 *     registration email (loading, invalid-link, success, redirect).
 *
 * All inserts are idempotent (INSERT IGNORE on the unique field name /
 * style-field / section-field-language tuples). down() removes only the
 * fields this migration introduced; the FK cascade would clear the links
 * and translations too, but they are deleted explicitly for clarity.
 */
final class Version20260604111011 extends AbstractMigration
{
    private const FIELD_TYPE = 'text';
    private const LOCALES = ['en-GB', 'de-CH'];

    /**
     * One row per new label field. Each field is owned by exactly one style
     * and seeded onto that style's out-of-the-box section.
     *
     * @return list<array{field: string, style: string, section: string, help: string, en: string, de: string}>
     */
    private function labels(): array
    {
        return [
            // -- register form ------------------------------------------
            [
                'field' => 'label_code', 'style' => 'register', 'section' => 'register-sys-form',
                'help' => 'Label shown above the validation-code input on the registration form (code-required mode).',
                'en' => 'Validation Code', 'de' => 'Validierungscode',
            ],
            [
                'field' => 'code_placeholder', 'style' => 'register', 'section' => 'register-sys-form',
                'help' => 'Placeholder shown inside the validation-code input on the registration form.',
                'en' => 'Enter your code', 'de' => 'Geben Sie Ihren Code ein',
            ],
            [
                'field' => 'label_go_home', 'style' => 'register', 'section' => 'register-sys-form',
                'help' => 'Label of the button that returns to the home page after a successful registration.',
                'en' => 'Go Home', 'de' => 'Zur Startseite',
            ],
            [
                'field' => 'label_go_to_login', 'style' => 'register', 'section' => 'register-sys-form',
                'help' => 'Label of the button that opens the login page after a successful registration.',
                'en' => 'Go to Login', 'de' => 'Zur Anmeldung',
            ],
            // -- login form ---------------------------------------------
            [
                'field' => 'label_register', 'style' => 'login', 'section' => 'login-sys-form',
                'help' => 'Label of the link on the login form that opens the registration page.',
                'en' => 'Create account', 'de' => 'Konto erstellen',
            ],
            // -- validate (activation) form -----------------------------
            [
                'field' => 'loading_title', 'style' => 'validate', 'section' => 'validate-sys-form',
                'help' => 'Heading shown while the account-activation link is being verified.',
                'en' => 'Validating Link', 'de' => 'Link wird überprüft',
            ],
            [
                'field' => 'loading_text', 'style' => 'validate', 'section' => 'validate-sys-form',
                'help' => 'Body text shown while the account-activation link is being verified.',
                'en' => 'Please wait while we validate your account activation link...',
                'de' => 'Bitte warten Sie, während wir Ihren Aktivierungslink überprüfen ...',
            ],
            [
                'field' => 'error_title', 'style' => 'validate', 'section' => 'validate-sys-form',
                'help' => 'Alert title shown when the activation link is invalid or has expired.',
                'en' => 'Invalid Validation Link', 'de' => 'Ungültiger Aktivierungslink',
            ],
            [
                'field' => 'error_heading', 'style' => 'validate', 'section' => 'validate-sys-form',
                'help' => 'Bold heading inside the invalid-activation-link alert.',
                'en' => 'Account validation failed', 'de' => 'Kontoaktivierung fehlgeschlagen',
            ],
            [
                'field' => 'error_text', 'style' => 'validate', 'section' => 'validate-sys-form',
                'help' => 'Fallback message shown when the activation link cannot be validated.',
                'en' => 'This validation link is invalid or has expired. Please request a new validation email.',
                'de' => 'Dieser Aktivierungslink ist ungültig oder abgelaufen. Bitte fordern Sie eine neue Aktivierungs-E-Mail an.',
            ],
            [
                'field' => 'success_title', 'style' => 'validate', 'section' => 'validate-sys-form',
                'help' => 'Alert title shown after the account is successfully activated.',
                'en' => 'Success', 'de' => 'Erfolg',
            ],
            [
                'field' => 'redirect_text', 'style' => 'validate', 'section' => 'validate-sys-form',
                'help' => 'Shown after activation while redirecting to login. Use {seconds} as the countdown placeholder.',
                'en' => 'Redirecting to login in {seconds}s...', 'de' => 'Weiterleitung zur Anmeldung in {seconds}s ...',
            ],
        ];
    }

    public function getDescription(): string
    {
        return 'Add CMS label fields for registration lifecycle UI text (register/login/validate) and seed en-GB/de-CH defaults.';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->labels() as $row) {
            // 1. The field definition (translatable, plain text).
            $field = $this->escape($row['field']);
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`)
                SELECT '{$field}', ft.id, 1
                FROM `field_types` ft
                WHERE ft.`name` = '{$this->fieldType()}'
            SQL);

            // 2. Link it to its owning style with an English default + help.
            $style = $this->escape($row['style']);
            $default = $this->escape($row['en']);
            $help = $this->escape($row['help']);
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `rel_fields_styles` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`)
                SELECT s.id, f.id, '{$default}', '{$help}', 0, 0
                FROM `styles` s
                JOIN `fields` f ON f.`name` = '{$field}'
                WHERE s.`name` = '{$style}'
            SQL);

            // 3. Seed the out-of-the-box section translations per locale.
            $section = $this->escape($row['section']);
            foreach (self::LOCALES as $locale) {
                $content = $this->escape($locale === 'de-CH' ? $row['de'] : $row['en']);
                $this->addSql(<<<SQL
                    INSERT IGNORE INTO `sections_fields_translation` (`id_sections`, `id_fields`, `id_languages`, `content`, `meta`)
                    SELECT sec.id, f.id, l.id, '{$content}', NULL
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

    private function fieldType(): string
    {
        return $this->escape(self::FIELD_TYPE);
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
