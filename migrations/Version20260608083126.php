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

final class Version20260608083126 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password-changed confirmation mail fields to sh-mail-config and seed en-GB/de-CH defaults.';
    }

    public function up(Schema $schema): void
    {
        $pageType = MailTemplateDefaults::PAGE_TYPE;
        $keyword = MailTemplateDefaults::PAGE_KEYWORD;

        $this->addSql("
            INSERT IGNORE INTO fields (name, id_field_types, display) VALUES
            ('mail_password_changed_subject', (SELECT id FROM field_types WHERE name = 'text' LIMIT 1), 1),
            ('mail_password_changed_body',    (SELECT id FROM field_types WHERE name = 'textarea' LIMIT 1), 1)
        ");

        foreach ([
            'mail_password_changed_subject',
            'mail_password_changed_body',
        ] as $fieldName) {
            $meta = MailTemplateDefaults::FIELD_METADATA[$fieldName];
            $title = $this->escape($meta['title']);
            $help = $this->escape($meta['help']);

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

        foreach (MailTemplateDefaults::LOCALES as $locale) {
            $subject = MailTemplateDefaults::getSubject(MailTemplateDefaults::TYPE_PASSWORD_CHANGED, $locale);
            $body = MailTemplateDefaults::getBody(MailTemplateDefaults::TYPE_PASSWORD_CHANGED, $locale);

            if ($subject !== '') {
                $this->insertTranslation($keyword, 'mail_password_changed_subject', $locale, $subject);
            }

            if ($body !== '') {
                $this->insertTranslation($keyword, 'mail_password_changed_body', $locale, $body);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            DELETE FROM pages_fields_translation
            WHERE id_pages = (SELECT id FROM pages WHERE keyword = '" . MailTemplateDefaults::PAGE_KEYWORD . "' LIMIT 1)
              AND id_fields IN (
                SELECT id FROM fields WHERE name IN ('mail_password_changed_subject', 'mail_password_changed_body')
              )
        ");

        $this->addSql("
            DELETE FROM rel_fields_page_types
            WHERE id_page_types = (SELECT id FROM page_types WHERE name = '" . MailTemplateDefaults::PAGE_TYPE . "' LIMIT 1)
              AND id_fields IN (
                SELECT id FROM fields WHERE name IN ('mail_password_changed_subject', 'mail_password_changed_body')
              )
        ");

        $this->addSql("DELETE FROM fields WHERE name IN ('mail_password_changed_subject', 'mail_password_changed_body')");
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

    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "'"],
            ['\\\\', "''"],
            $value
        );
    }
}
