<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601000900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds global mail configuration and email template fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
        INSERT INTO page_types (id, name) VALUES (13, 'mail_config')
        ");
        // =====================================
        // 1. Create mail template page
        // =====================================
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
                'sh-mail-config',
                NULL,
                NULL,
                '0', 
                NULL,
                NULL,
                '13',
                '63',
                '0',
                '0',
                NULL
            )
        ");

        // =====================================
        // 2. Global mail configuration fields
        // =====================================
        $this->addSql("
            INSERT IGNORE INTO fields (name, id_field_types, display) VALUES
            ('mail_from_email', (SELECT id FROM field_types WHERE name = 'text' LIMIT 1), 0),
            ('mail_from_name',  (SELECT id FROM field_types WHERE name = 'text' LIMIT 1), 0),
            ('mail_reply_to',   (SELECT id FROM field_types WHERE name = 'text' LIMIT 1), 0),
            ('mail_is_html',    (SELECT id FROM field_types WHERE name = 'checkbox' LIMIT 1), 0)
        ");

        // =====================================
        // 3. Email template fields
        // =====================================
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

        // =====================================
        // 4. PageType mapping (Email templates = 13)
        // =====================================
        $this->addSql("
            INSERT IGNORE INTO rel_fields_page_types (id_page_types, id_fields, title, help) VALUES

            -- Global config
            (13, (SELECT id FROM fields WHERE name = 'mail_from_email' LIMIT 1), 'Mail: From Email',   'Email address used as sender'),
            (13, (SELECT id FROM fields WHERE name = 'mail_from_name'  LIMIT 1), 'Mail: From Name',    'Display name used as sender'),
            (13, (SELECT id FROM fields WHERE name = 'mail_reply_to'   LIMIT 1), 'Mail: Reply-To',     'Reply-To email address'),
            (13, (SELECT id FROM fields WHERE name = 'mail_is_html'    LIMIT 1), 'Mail: HTML Enabled', 'If unchecked, all HTML will be sent in the email.'),

            -- 2FA
            (13, (SELECT id FROM fields WHERE name = 'mail_2fa_subject' LIMIT 1), '2FA: Subject', 'Subject line for 2FA code email'),
            (13, (SELECT id FROM fields WHERE name = 'mail_2fa_body'    LIMIT 1), '2FA: Body',    'Email body content for 2FA'),

            -- Confirmation
            (13, (SELECT id FROM fields WHERE name = 'mail_confirm_subject' LIMIT 1), 'Confirmation: Subject', 'Subject line for account confirmation email'),
            (13, (SELECT id FROM fields WHERE name = 'mail_confirm_body'    LIMIT 1), 'Confirmation: Body',    'Email body for account confirmation'),

            -- Recovery
            (13, (SELECT id FROM fields WHERE name = 'mail_recovery_subject' LIMIT 1), 'Recovery: Subject', 'Subject line for password recovery email'),
            (13, (SELECT id FROM fields WHERE name = 'mail_recovery_body'    LIMIT 1), 'Recovery: Body',    'Email body for password recovery'),

            -- Welcome
            (13, (SELECT id FROM fields WHERE name = 'mail_welcome_subject' LIMIT 1), 'Welcome: Subject', 'Subject line for welcome email'),
            (13, (SELECT id FROM fields WHERE name = 'mail_welcome_body'    LIMIT 1), 'Welcome: Body',    'Email body for welcome email')
        ");

        // =====================================
        // 5. Default value for mail_is_html (true)
        // =====================================
        $this->addSql("
        INSERT IGNORE INTO pages_fields_translation (id_pages, id_fields, id_languages, content)
        VALUES (
            (SELECT id_pages FROM pages WHERE keyword = 'sh-mail-config' LIMIT 1),
            (SELECT id FROM fields WHERE name = 'mail_is_html' LIMIT 1),
            1,
            'true'
        )
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            DELETE FROM rel_fields_page_types 
            WHERE id_page_types = 13
            AND id_fields IN (
                (SELECT id FROM fields WHERE name = 'mail_from_email'      LIMIT 1),
                (SELECT id FROM fields WHERE name = 'mail_from_name'       LIMIT 1),
                (SELECT id FROM fields WHERE name = 'mail_reply_to'        LIMIT 1),
                (SELECT id FROM fields WHERE name = 'mail_is_html'         LIMIT 1),
                (SELECT id FROM fields WHERE name = 'mail_2fa_subject'     LIMIT 1),
                (SELECT id FROM fields WHERE name = 'mail_2fa_body'        LIMIT 1),
                (SELECT id FROM fields WHERE name = 'mail_confirm_subject' LIMIT 1),
                (SELECT id FROM fields WHERE name = 'mail_confirm_body'    LIMIT 1),
                (SELECT id FROM fields WHERE name = 'mail_recovery_subject' LIMIT 1),
                (SELECT id FROM fields WHERE name = 'mail_recovery_body'   LIMIT 1),
                (SELECT id FROM fields WHERE name = 'mail_welcome_subject' LIMIT 1),
                (SELECT id FROM fields WHERE name = 'mail_welcome_body'    LIMIT 1)
            )
        ");

        $this->addSql("
        DELETE FROM pages_fields_translation
        WHERE id_pages = (SELECT id_pages FROM pages WHERE keyword = 'sh-mail-config' LIMIT 1)
        AND id_fields = (SELECT id FROM fields WHERE name = 'mail_is_html' LIMIT 1)
        AND id_languages = 1
        ");
    }
}
