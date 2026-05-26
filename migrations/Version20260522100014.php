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
 * Seeds the register system page with a container wrapper and a register
 * section inside it. All IDs are resolved by name so the migration is
 * portable across environments.
 *
 * Also removes the unused `text_md` and `email_user` fields from the
 * `resetPassword` style.
 *
 * Down removes the sections by name prefix and the page row by keyword,
 * and restores the removed style–field links.
 */
final class Version20260522100014 extends AbstractMigration
{
    private const KEYWORD = 'register';
    private const PREFIX  = 'register-sys';

    public function getDescription(): string
    {
        return 'Seed register system page with a container + register section; remove unused text_md and email_user fields from resetPassword style.';
    }

    public function up(Schema $schema): void
    {
        // Insert the page row. id_page_access_types is copied from login
        // (the canonical open-access auth page) so no value is hardcoded.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `pages` (`keyword`, `url`, `id_page_types`, `id_page_access_types`, `is_system`, `is_headless`, `is_open_access`)
            SELECT 'register', '/register', pt.id, login.id_page_access_types, 1, 0, 1
            FROM `page_types` pt, `pages` login
            WHERE pt.`name` = 'core' AND login.`keyword` = 'login'
        SQL);

        // ACL: all groups get select; admin additionally gets update.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `page_acl_groups`
                (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
            SELECT g.id, p.id, 1, 0, IF(g.`name` = 'admin', 1, 0), 0
            FROM `groups` g, `pages` p
            WHERE p.`keyword` = 'register'
        SQL);

        // Remove unused fields from the resetPassword style.
        $this->addSql(<<<SQL
            DELETE rfs FROM `rel_fields_styles` rfs
            JOIN `styles` s ON s.id = rfs.id_styles
            JOIN `fields` f ON f.id = rfs.id_fields
            WHERE s.`name` = 'resetPassword' AND f.`name` IN ('text_md', 'email_user')
        SQL);

        // Sections.
        $this->insertSection(
            name: self::PREFIX . '-wrapper',
            style: 'container',
            parent: null,
        );
        $this->insertSection(
            name: self::PREFIX . '-form',
            style: 'register',
            parent: self::PREFIX . '-wrapper',
        );
    }

    public function down(Schema $schema): void
    {
        // Restore removed style–field links.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_fields_styles` (`id_styles`, `id_fields`)
            SELECT s.id, f.id
            FROM `styles` s, `fields` f
            WHERE s.`name` = 'resetPassword' AND f.`name` IN ('text_md', 'email_user')
        SQL);

        $this->addSql("DELETE FROM `sections` WHERE `name` LIKE '" . self::PREFIX . "-%'");
        $this->addSql("DELETE FROM `pages` WHERE `keyword` = '" . self::KEYWORD . "'");
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertSection(string $name, string $style, ?string $parent): void
    {
        $this->addSql(<<<SQL
            INSERT INTO `sections` (`id_styles`, `name`)
            SELECT s.id, '{$name}'
            FROM `styles` s
            WHERE s.`name` = '{$style}'
        SQL);

        if ($parent === null) {
            $this->addSql(<<<SQL
                INSERT INTO `rel_pages_sections` (`id_pages`, `id_sections`, `position`)
                SELECT p.id, sec.id, 10
                FROM `pages` p, `sections` sec
                WHERE p.`keyword` = 'register' AND sec.`name` = '{$name}'
            SQL);
        } else {
            $this->addSql(<<<SQL
                INSERT INTO `rel_sections_hierarchy` (`id_parent_section`, `id_child_section`, `position`)
                SELECT parent_sec.id, child_sec.id, 10
                FROM `sections` parent_sec, `sections` child_sec
                WHERE parent_sec.`name` = '{$parent}' AND child_sec.`name` = '{$name}'
            SQL);
        }
    }
}
