<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Single source of truth for the SelfHelp mail subsystem.
 *
 * Holds:
 *   - Default sender (from_email / from_name / reply_to / is_html).
 *   - Mail type identifiers.
 *   - Supported locales for seeded translations.
 *   - Available `{{placeholders}}` per mail type.
 *   - CMS field metadata (title + help text) for `rel_fields_page_types`.
 *   - Hardcoded fallback subjects and bodies in every supported locale.
 *
 * Resolution chain at runtime (see {@see MailTemplateService}):
 *   1. `pages_fields_translation` for the `sh-mail-config` CMS page (admin-editable).
 *   2. The hardcoded constants in this class (loaded from `templates/emails/*.html`).
 *
 * The matching install-time seed migration copies the same hardcoded content into
 * `pages_fields_translation` so the CMS shows the templates to admins out of the
 * box. If an admin deletes a row, runtime simply falls back to this class.
 *
 * Adding a new locale: add the locale string to {@see self::LOCALES} and add the
 * matching keys to {@see self::SUBJECTS} / the template files in
 * `templates/emails/`.
 */
final class MailTemplateDefaults
{
    public const FROM_EMAIL = 'selfhelp@unibe.ch';
    public const FROM_NAME = 'SelfHelp Platform';
    public const REPLY_TO = 'selfhelp@unibe.ch';
    public const IS_HTML = true;

    public const TYPE_2FA = 'mail_2fa';
    public const TYPE_CONFIRM = 'mail_confirm';
    public const TYPE_WELCOME = 'mail_welcome';
    public const TYPE_RECOVERY = 'mail_recovery';
    public const TYPE_PASSWORD_CHANGED = 'mail_password_changed';

    public const TYPES = [
        self::TYPE_2FA,
        self::TYPE_CONFIRM,
        self::TYPE_WELCOME,
        self::TYPE_RECOVERY,
        self::TYPE_PASSWORD_CHANGED,
    ];

    public const LOCALES = ['en-GB', 'de-CH'];
    public const PROPS_LOCALE = 'all';
    public const PAGE_KEYWORD = 'sh-mail-config';
    public const PAGE_TYPE = 'mail_config';

    /**
     * @var array<string, list<string>>
     */
    public const PLACEHOLDERS = [
        self::TYPE_2FA => ['system.user_name', 'system.user_code'],
        self::TYPE_CONFIRM => ['system.user_name', 'system.special.activation_link'],
        self::TYPE_WELCOME => ['system.user_name', 'system.special.platform_link'],
        self::TYPE_RECOVERY => ['system.user_name', 'system.special.reset_link'],
        self::TYPE_PASSWORD_CHANGED => ['system.user_name', 'system.special.platform_link'],
    ];

    /**
     * @var array<string, array<string, string>>
     */
    public const SUBJECTS = [
        self::TYPE_2FA => [
            'en-GB' => 'Your verification code',
            'de-CH' => 'Ihr Verifizierungscode',
        ],
        self::TYPE_CONFIRM => [
            'en-GB' => 'Please validate your account',
            'de-CH' => 'Bitte bestätigen Sie Ihr Konto',
        ],
        self::TYPE_WELCOME => [
            'en-GB' => 'Welcome to SelfHelp Platform - your account is now active!',
            'de-CH' => 'Willkommen bei der SelfHelp-Plattform - Ihr Konto ist nun aktiv!',
        ],
        self::TYPE_RECOVERY => [
            'en-GB' => 'Reset your password',
            'de-CH' => 'Passwort zurücksetzen',
        ],
        self::TYPE_PASSWORD_CHANGED => [
            'en-GB' => 'Your password was changed',
            'de-CH' => 'Ihr Passwort wurde geändert',
        ],
    ];

    /**
     * @var array<string, array{title: string, help: string}>
     */
    public const FIELD_METADATA = [
        'mail_from_email' => [
            'title' => 'Mail: From Email',
            'help' => 'Sender email address shown in the From header. Defaults to ' . self::FROM_EMAIL . ' when left empty.',
        ],
        'mail_from_name' => [
            'title' => 'Mail: From Name',
            'help' => 'Display name shown next to the From email. Defaults to "' . self::FROM_NAME . '" when left empty.',
        ],
        'mail_reply_to' => [
            'title' => 'Mail: Reply-To',
            'help' => 'Reply-To email address. Defaults to the From email when left empty.',
        ],
        'mail_is_html' => [
            'title' => 'Mail: HTML Enabled',
            'help' => 'When checked, emails are sent as HTML. When unchecked, the body is sent as plain text and any HTML tags are visible to the recipient.',
        ],
        'mail_2fa_subject' => [
            'title' => '2FA: Subject',
            'help' => 'Subject line of the 2FA email. Available placeholders: {{system.user_name}}, {{system.user_code}}.',
        ],
        'mail_2fa_body' => [
            'title' => '2FA: Body',
            'help' => 'Body of the 2FA email. Available placeholders: {{system.user_name}} (full name or email fallback), {{system.user_code}} (6-digit verification code).',
        ],
        'mail_confirm_subject' => [
            'title' => 'Confirmation: Subject',
            'help' => 'Subject line of the account confirmation email. Available placeholders: {{system.user_name}}, {{system.special.activation_link}}.',
        ],
        'mail_confirm_body' => [
            'title' => 'Confirmation: Body',
            'help' => 'Body of the account confirmation email. Available placeholders: {{system.user_name}} (full name or email fallback), {{system.special.activation_link}} (one-time activation link, valid for 24 hours).',
        ],
        'mail_welcome_subject' => [
            'title' => 'Welcome: Subject',
            'help' => 'Subject line of the welcome email sent after account validation. Available placeholders: {{system.user_name}}, {{system.special.platform_link}}.',
        ],
        'mail_welcome_body' => [
            'title' => 'Welcome: Body',
            'help' => 'Body of the welcome email. Available placeholders: {{system.user_name}} (full name or email fallback), {{system.special.platform_link}} (link to the platform home).',
        ],
        'mail_recovery_subject' => [
            'title' => 'Recovery: Subject',
            'help' => 'Subject line of the password recovery email. Available placeholders: {{system.user_name}}, {{system.special.reset_link}}.',
        ],
        'mail_recovery_body' => [
            'title' => 'Recovery: Body',
            'help' => 'Body of the password recovery email. Available placeholders: {{system.user_name}} (full name or email fallback), {{system.special.reset_link}} (one-time reset link, valid for 1 hour).',
        ],
        'mail_password_changed_subject' => [
            'title' => 'Password Changed: Subject',
            'help' => 'Subject line of the password-changed confirmation email. Available placeholders: {{system.user_name}}, {{system.special.platform_link}}.',
        ],
        'mail_password_changed_body' => [
            'title' => 'Password Changed: Body',
            'help' => 'Body of the password-changed confirmation email. Available placeholders: {{system.user_name}} (full name or email fallback), {{system.special.platform_link}} (link to the platform home/login).',
        ],
    ];

    private function __construct()
    {
    }

    public static function getSubject(string $type, string $locale): string
    {
        return self::SUBJECTS[$type][$locale]
            ?? self::SUBJECTS[$type]['en-GB']
            ?? '';
    }

    public static function getBody(string $type, string $locale): string
    {
        $primary = self::templatePath($type, $locale);
        $fallback = self::templatePath($type, 'en-GB');

        if (is_file($primary)) {
            return (string) file_get_contents($primary);
        }

        if (is_file($fallback)) {
            return (string) file_get_contents($fallback);
        }

        return '';
    }

    public static function templatePath(string $type, string $locale): string
    {
        return self::templatesDir() . DIRECTORY_SEPARATOR . sprintf('%s.%s.html', $type, $locale);
    }

    public static function templatesDir(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'emails';
    }
}
