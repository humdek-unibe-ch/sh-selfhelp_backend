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
    /** Default sender address used everywhere unless overridden in the CMS or by the caller. */
    public const FROM_EMAIL = 'selfhelp@unibe.ch';

    /** Default human-readable sender name. */
    public const FROM_NAME = 'SelfHelp Platform';

    /** Default Reply-To address. */
    public const REPLY_TO = 'selfhelp@unibe.ch';

    /** Default HTML flag — `true` sends `text/html`, `false` sends `text/plain`. */
    public const IS_HTML = true;

    /** Mail type identifiers. Match the CMS field name prefix (`<type>_subject`, `<type>_body`). */
    public const TYPE_2FA = 'mail_2fa';
    public const TYPE_CONFIRM = 'mail_confirm';
    public const TYPE_WELCOME = 'mail_welcome';
    public const TYPE_RECOVERY = 'mail_recovery';

    /** All mail types known to the system. */
    public const TYPES = [
        self::TYPE_2FA,
        self::TYPE_CONFIRM,
        self::TYPE_WELCOME,
        self::TYPE_RECOVERY,
    ];

    /** Locales for which we ship a translated default subject + body. */
    public const LOCALES = ['en-GB', 'de-CH'];

    /** Pseudo-locale used by the CMS for non-translatable / "props" fields. */
    public const PROPS_LOCALE = 'all';

    /** CMS keyword of the page that holds the mail configuration. */
    public const PAGE_KEYWORD = 'sh-mail-config';

    /** CMS page type that contains the mail config fields. */
    public const PAGE_TYPE = 'mail_config';

    /**
     * Available `{{placeholder}}` names per mail type. Used to render runtime
     * variables into subject + body and to document the CMS field help text.
     *
     * Mustache-style ({{name}}) — handled by `App\Service\Core\InterpolationService`.
     *
     * @var array<string, list<string>>
     */
    public const PLACEHOLDERS = [
        self::TYPE_2FA      => ['user_name', 'code'],
        self::TYPE_CONFIRM  => ['user_name', 'validation_url'],
        self::TYPE_WELCOME  => ['user_name', 'platform_url'],
        self::TYPE_RECOVERY => ['user_name', 'reset_url'],
    ];

    /**
     * Built-in default subjects per type + locale. Mirrors the per-locale body
     * files in `templates/emails/<type>.<locale>.html`.
     *
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
            'en-GB' => 'Welcome to SelfHelp Platform — your account is now active!',
            'de-CH' => 'Willkommen bei der SelfHelp-Plattform — Ihr Konto ist nun aktiv!',
        ],
        self::TYPE_RECOVERY => [
            'en-GB' => 'Reset your password',
            'de-CH' => 'Passwort zurücksetzen',
        ],
    ];

    /**
     * CMS field metadata for `rel_fields_page_types` (title + help text).
     *
     * The `help` strings document the supported `{{placeholders}}` so the admin
     * UI shows the user which variables they can use in subject and body.
     *
     * @var array<string, array{title: string, help: string}>
     */
    public const FIELD_METADATA = [
        // ---- Global sender configuration (props, language id = 1) ----
        'mail_from_email' => [
            'title' => 'Mail: From Email',
            'help'  => 'Sender email address shown in the From header. Defaults to ' . self::FROM_EMAIL . ' when left empty.',
        ],
        'mail_from_name' => [
            'title' => 'Mail: From Name',
            'help'  => 'Display name shown next to the From email. Defaults to "' . self::FROM_NAME . '" when left empty.',
        ],
        'mail_reply_to' => [
            'title' => 'Mail: Reply-To',
            'help'  => 'Reply-To email address. Defaults to the From email when left empty.',
        ],
        'mail_is_html' => [
            'title' => 'Mail: HTML Enabled',
            'help'  => 'When checked, emails are sent as HTML. When unchecked, the body is sent as plain text and any HTML tags are visible to the recipient.',
        ],

        // ---- 2FA template ----
        'mail_2fa_subject' => [
            'title' => '2FA: Subject',
            'help'  => 'Subject line of the 2FA email. Available placeholders: {{user_name}}, {{code}}.',
        ],
        'mail_2fa_body' => [
            'title' => '2FA: Body',
            'help'  => 'Body of the 2FA email. Available placeholders: {{user_name}} (full name or email fallback), {{code}} (6-digit verification code).',
        ],

        // ---- Account confirmation / validation template ----
        'mail_confirm_subject' => [
            'title' => 'Confirmation: Subject',
            'help'  => 'Subject line of the account confirmation email. Available placeholders: {{user_name}}, {{validation_url}}.',
        ],
        'mail_confirm_body' => [
            'title' => 'Confirmation: Body',
            'help'  => 'Body of the account confirmation email. Available placeholders: {{user_name}} (full name or email fallback), {{validation_url}} (one-time activation link, valid for 24 hours).',
        ],

        // ---- Welcome (post-activation) template ----
        'mail_welcome_subject' => [
            'title' => 'Welcome: Subject',
            'help'  => 'Subject line of the welcome email sent after account validation. Available placeholders: {{user_name}}, {{platform_url}}.',
        ],
        'mail_welcome_body' => [
            'title' => 'Welcome: Body',
            'help'  => 'Body of the welcome email. Available placeholders: {{user_name}} (full name or email fallback), {{platform_url}} (link to the platform home).',
        ],

        // ---- Password recovery template ----
        'mail_recovery_subject' => [
            'title' => 'Recovery: Subject',
            'help'  => 'Subject line of the password recovery email. Available placeholders: {{user_name}}, {{reset_url}}.',
        ],
        'mail_recovery_body' => [
            'title' => 'Recovery: Body',
            'help'  => 'Body of the password recovery email. Available placeholders: {{user_name}} (full name or email fallback), {{reset_url}} (one-time reset link, valid for 1 hour).',
        ],
    ];

    private function __construct()
    {
        // Static utility class — not instantiable.
    }

    /**
     * Resolve the default subject for a mail type + locale, falling back
     * through `en-GB` and finally to an empty string.
     */
    public static function getSubject(string $type, string $locale): string
    {
        return self::SUBJECTS[$type][$locale]
            ?? self::SUBJECTS[$type]['en-GB']
            ?? '';
    }

    /**
     * Resolve the default body for a mail type + locale by reading the matching
     * file under `templates/emails/<type>.<locale>.html`. Falls back to the
     * English file and finally to an empty string when nothing is shipped.
     */
    public static function getBody(string $type, string $locale): string
    {
        $primary  = self::templatePath($type, $locale);
        $fallback = self::templatePath($type, 'en-GB');

        if (is_file($primary)) {
            return (string) file_get_contents($primary);
        }

        if (is_file($fallback)) {
            return (string) file_get_contents($fallback);
        }

        return '';
    }

    /**
     * Absolute path of the template file for a mail type + locale.
     *
     * Centralised so the runtime and the seed migration agree on the same
     * filesystem layout.
     */
    public static function templatePath(string $type, string $locale): string
    {
        return self::templatesDir() . DIRECTORY_SEPARATOR . sprintf('%s.%s.html', $type, $locale);
    }

    /**
     * Root directory for email template files (`<project>/templates/emails`).
     */
    public static function templatesDir(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'emails';
    }
}
