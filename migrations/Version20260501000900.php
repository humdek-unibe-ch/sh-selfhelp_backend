<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Auth\MailTemplateDefaults;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed migration: global mail configuration page (`sh-mail-config`) plus the
 * fields and default English + German translations that drive the SelfHelp
 * mail subsystem.
 *
 * What this migration adds:
 *   - `page_types` row: `mail_config`.
 *   - `pages` row: `sh-mail-config` (one-off system page, no parent).
 *   - `fields` rows:
 *       * Global sender properties (`mail_from_email`, `mail_from_name`,
 *         `mail_reply_to`, `mail_is_html`) — non-translatable, language id 1.
 *       * Per-type subject + body fields for 2FA, confirmation, welcome,
 *         and password-recovery — translatable.
 *   - `rel_fields_page_types` rows linking every field to the new page type,
 *     with admin-facing title + help text describing the supported
 *     `{{placeholders}}` (sourced from {@see MailTemplateDefaults::FIELD_METADATA}).
 *   - `pages_fields_translation` rows:
 *       * Global sender defaults (props) sourced from
 *         {@see MailTemplateDefaults::FROM_EMAIL} / `FROM_NAME` / `REPLY_TO` /
 *         `IS_HTML`.
 *       * Subject + body defaults for every type in every shipped locale
 *         (`en-GB`, `de-CH`) sourced from {@see MailTemplateDefaults::SUBJECTS}
 *         and the matching `templates/emails/<type>.<locale>.html` files.
 *
 * The runtime service ({@see MailTemplateService}) reads these rows first and
 * falls back to the constants on the same class — meaning admins can freely
 * edit or even delete rows without breaking outbound mail.
 *
 * Depends on:
 *   - Version20260501000000 (schema: pages, fields, languages, …).
 *   - Version20260501000100 (seed: lookups, languages, page_types).
 *   - Version20260501000200 (seed: fields, field_types).
 */
final class Version20260501000900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed mail configuration page (sh-mail-config) with fields, hints and default English + German content.';
    }

    public function up(Schema $schema): void
    {
        $this->seedPageType();
        $this->seedPage();
        $this->seedFields();
        $this->seedPageTypeFieldLinks();
        $this->seedGlobalSenderDefaults();
        $this->seedTemplateDefaults();
    }

    public function down(Schema $schema): void
    {
        $pageKeyword = MailTemplateDefaults::PAGE_KEYWORD;
        $pageType    = MailTemplateDefaults::PAGE_TYPE;
        $fieldNames  = $this->allFieldNames();
        $fieldList   = "'" . implode("','", $fieldNames) . "'";

        // Drop translations on the page first (cascades from the page would also work,
        // but we keep this explicit so the down migration is safe even if the page row
        // is left behind by a previous partial up()).
        $this->addSql("
            DELETE FROM pages_fields_translation
            WHERE id_pages = (SELECT id FROM pages WHERE keyword = '{$pageKeyword}' LIMIT 1)
              AND id_fields IN (SELECT id FROM fields WHERE name IN ({$fieldList}))
        ");

        $this->addSql("
            DELETE FROM rel_fields_page_types
            WHERE id_page_types = (SELECT id FROM page_types WHERE name = '{$pageType}' LIMIT 1)
              AND id_fields IN (SELECT id FROM fields WHERE name IN ({$fieldList}))
        ");

        $this->addSql("DELETE FROM pages WHERE keyword = '{$pageKeyword}'");
        $this->addSql("DELETE FROM fields WHERE name IN ({$fieldList})");
        $this->addSql("DELETE FROM page_types WHERE name = '{$pageType}'");
    }

    private function seedPageType(): void
    {
        $name = MailTemplateDefaults::PAGE_TYPE;
        $this->addSql("INSERT IGNORE INTO page_types (name) VALUES ('{$name}')");
    }

    private function seedPage(): void
    {
        $keyword  = MailTemplateDefaults::PAGE_KEYWORD;
        $pageType = MailTemplateDefaults::PAGE_TYPE;

        $this->addSql("
            INSERT IGNORE INTO pages (
                `keyword`,
                `url`,
                `id_parent_page`,
                `is_headless`,
                `nav_position`,
                `footer_position`,
                `id_page_types`,
                `id_page_access_types`,
                `is_open_access`,
                `is_system`,
                `id_published_page_versions`
            ) VALUES (
                '{$keyword}',
                NULL,
                NULL,
                0,
                NULL,
                NULL,
                (SELECT id FROM page_types WHERE name = '{$pageType}' LIMIT 1),
                (SELECT id FROM lookups WHERE type_code = 'pageAccessTypes' AND lookup_code = 'mobile_and_web' LIMIT 1),
                0,
                1,
                NULL
            )
        ");
    }

    private function seedFields(): void
    {
        // Global sender configuration (props, never translated).
        $this->addSql("
            INSERT IGNORE INTO fields (name, id_field_types, display) VALUES
            ('mail_from_email', (SELECT id FROM field_types WHERE name = 'text'     LIMIT 1), 0),
            ('mail_from_name',  (SELECT id FROM field_types WHERE name = 'text'     LIMIT 1), 0),
            ('mail_reply_to',   (SELECT id FROM field_types WHERE name = 'text'     LIMIT 1), 0),
            ('mail_is_html',    (SELECT id FROM field_types WHERE name = 'checkbox' LIMIT 1), 0)
        ");

        // Per-type subject + body (translatable).
        $this->addSql("
            INSERT IGNORE INTO fields (name, id_field_types, display) VALUES
            ('mail_2fa_subject',      (SELECT id FROM field_types WHERE name = 'text'     LIMIT 1), 1),
            ('mail_2fa_body',         (SELECT id FROM field_types WHERE name = 'textarea' LIMIT 1), 1),
            ('mail_confirm_subject',  (SELECT id FROM field_types WHERE name = 'text'     LIMIT 1), 1),
            ('mail_confirm_body',     (SELECT id FROM field_types WHERE name = 'textarea' LIMIT 1), 1),
            ('mail_recovery_subject', (SELECT id FROM field_types WHERE name = 'text'     LIMIT 1), 1),
            ('mail_recovery_body',    (SELECT id FROM field_types WHERE name = 'textarea' LIMIT 1), 1),
            ('mail_welcome_subject',  (SELECT id FROM field_types WHERE name = 'text'     LIMIT 1), 1),
            ('mail_welcome_body',     (SELECT id FROM field_types WHERE name = 'textarea' LIMIT 1), 1)
        ");
    }

    private function seedPageTypeFieldLinks(): void
    {
        $pageType = MailTemplateDefaults::PAGE_TYPE;

        foreach (MailTemplateDefaults::FIELD_METADATA as $fieldName => $meta) {
            $title = $this->escape($meta['title']);
            $help  = $this->escape($meta['help']);

            $this->addSql("
                INSERT IGNORE INTO rel_fields_page_types (id_page_types, id_fields, title, help)
                VALUES (
                    (SELECT id FROM page_types WHERE name = '{$pageType}' LIMIT 1),
                    (SELECT id FROM fields WHERE name = '{$fieldName}' LIMIT 1),
                    '{$title}',
                    '{$help}'
                )
            ");
        }
    }

    private function seedGlobalSenderDefaults(): void
    {
        $keyword = MailTemplateDefaults::PAGE_KEYWORD;
        $propsLocale = MailTemplateDefaults::PROPS_LOCALE;

        $globals = [
            'mail_from_email' => MailTemplateDefaults::FROM_EMAIL,
            'mail_from_name'  => MailTemplateDefaults::FROM_NAME,
            'mail_reply_to'   => MailTemplateDefaults::REPLY_TO,
            'mail_is_html'    => MailTemplateDefaults::IS_HTML ? '1' : '0',
        ];

        foreach ($globals as $fieldName => $value) {
            $escaped = $this->escape($value);
            $this->addSql("
                INSERT IGNORE INTO pages_fields_translation (id_pages, id_fields, id_languages, content)
                VALUES (
                    (SELECT id FROM pages WHERE keyword = '{$keyword}' LIMIT 1),
                    (SELECT id FROM fields WHERE name = '{$fieldName}' LIMIT 1),
                    (SELECT id FROM languages WHERE locale = '{$propsLocale}' LIMIT 1),
                    '{$escaped}'
                )
            ");
        }
    }

    private function seedTemplateDefaults(): void
    {
        $keyword = MailTemplateDefaults::PAGE_KEYWORD;

        foreach (MailTemplateDefaults::TYPES as $type) {
            foreach (MailTemplateDefaults::LOCALES as $locale) {
                $subject = MailTemplateDefaults::getSubject($type, $locale);
                $body    = MailTemplateDefaults::getBody($type, $locale);

                if ($subject !== '') {
                    $this->insertTranslation(
                        $keyword,
                        "{$type}_subject",
                        $locale,
                        $subject
                    );
                }

                if ($body !== '') {
                    $this->insertTranslation(
                        $keyword,
                        "{$type}_body",
                        $locale,
                        $body
                    );
                }
            }
        }
    }

    private function insertTranslation(string $pageKeyword, string $fieldName, string $locale, string $content): void
    {
        $escapedContent = $this->escape($content);

        $this->addSql("
            INSERT IGNORE INTO pages_fields_translation (id_pages, id_fields, id_languages, content)
            VALUES (
                (SELECT id FROM pages WHERE keyword = '{$pageKeyword}' LIMIT 1),
                (SELECT id FROM fields WHERE name = '{$fieldName}' LIMIT 1),
                (SELECT id FROM languages WHERE locale = '{$locale}' LIMIT 1),
                '{$escapedContent}'
            )
        ");
    }

    /**
     * Minimal MySQL string-literal escape for inline INSERTs. We use this
     * because Doctrine migrations expect a single SQL string per addSql call.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "'"],
            ['\\\\', "''"],
            $value
        );
    }

    /**
     * @return list<string>
     */
    private function allFieldNames(): array
    {
        return array_keys(MailTemplateDefaults::FIELD_METADATA);
    }
}
