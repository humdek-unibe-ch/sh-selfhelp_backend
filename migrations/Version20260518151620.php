<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds global mail configuration and email template fields';
    }

    public function up(Schema $schema): void
    {
        // =====================================
        // 1. Create mail template page
        // =====================================
        $this->addSql("
            INSERT IGNORE INTO `selfhelp2`.`pages` (
                `keyword`,
                `url`,
                `parent`,
                `is_headless`,
                `nav_position`,
                `footer_position`,
                `id_type`,
                `id_pageAccessTypes`,
                `is_open_access`,
                `is_system`,
                `published_version_id`
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
            INSERT IGNORE INTO fields (name, id_type, display) VALUES
            ('mail_from_email', get_field_type_id('text'), 0),
            ('mail_from_name', get_field_type_id('text'), 0),
            ('mail_reply_to', get_field_type_id('text'), 0),
            ('mail_is_html', get_field_type_id('checkbox'), 0)
        ");

        // =====================================
        // 3. Email template fields
        // =====================================
        $this->addSql("
            INSERT IGNORE INTO fields (name, id_type, display) VALUES
            ('mail_2fa_subject', get_field_type_id('text'), 1),
            ('mail_2fa_body', get_field_type_id('textarea'), 1),
            ('mail_confirm_subject', get_field_type_id('text'), 1),
            ('mail_confirm_body', get_field_type_id('textarea'), 1),
            ('mail_recovery_subject', get_field_type_id('text'), 1),
            ('mail_recovery_body', get_field_type_id('textarea'), 1),
            ('mail_welcome_subject', get_field_type_id('text'), 1),
            ('mail_welcome_body', get_field_type_id('textarea'), 1)
        ");

        // =====================================
        // 4. PageType mapping (Email templates = 13)
        // =====================================
        $this->addSql("
            INSERT IGNORE INTO pageType_fields (id_pageType, id_fields, title, help) VALUES

            -- Global config
            (13, get_field_id('mail_from_email'), 'Mail: From Email', 'Email address used as sender'),
            (13, get_field_id('mail_from_name'), 'Mail: From Name', 'Display name used as sender'),
            (13, get_field_id('mail_reply_to'), 'Mail: Reply-To', 'Reply-To email address'),
            (13, get_field_id('mail_is_html'), 'Mail: HTML Enabled', 'Defines whether emails are sent as HTML'),

            -- 2FA
            (13, get_field_id('mail_2fa_subject'), '2FA: Subject', 'Subject line for 2FA code email'),
            (13, get_field_id('mail_2fa_body'), '2FA: Body', 'Email body content for 2FA'),

            -- Confirmation
            (13, get_field_id('mail_confirm_subject'), 'Confirmation: Subject', 'Subject line for account confirmation email'),
            (13, get_field_id('mail_confirm_body'), 'Confirmation: Body', 'Email body for account confirmation'),

            -- Recovery
            (13, get_field_id('mail_recovery_subject'), 'Recovery: Subject', 'Subject line for password recovery email'),
            (13, get_field_id('mail_recovery_body'), 'Recovery: Body', 'Email body for password recovery'),

            -- Welcome
            (13, get_field_id('mail_welcome_subject'), 'Welcome: Subject', 'Subject line for welcome email'),
            (13, get_field_id('mail_welcome_body'), 'Welcome: Body', 'Email body for welcome email')
        ");
    }

    public function down(Schema $schema): void
    {
        // Remove mappings only (safe rollback)
        $this->addSql("
            DELETE FROM pageType_fields 
            WHERE id_pageType = 13
            AND id_fields IN (
                get_field_id('mail_from_email'),
                get_field_id('mail_from_name'),
                get_field_id('mail_reply_to'),
                get_field_id('mail_is_html'),
                get_field_id('mail_2fa_subject'),
                get_field_id('mail_2fa_body'),
                get_field_id('mail_confirm_subject'),
                get_field_id('mail_confirm_body'),
                get_field_id('mail_recovery_subject'),
                get_field_id('mail_recovery_body'),
                get_field_id('mail_welcome_subject'),
                get_field_id('mail_welcome_body')
            )
        ");
    }
}
