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
 * Re-create the profile page (deleted during development) and wrap the
 * existing profile-sys-* sections in a box for a better visual layout.
 *
 * Structure after migration:
 *   box (css: full-page background + padding)  ← new
 *     profile-sys-wrapper (container > profile) ← already exists
 */
final class Version20260520120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-create profile page and add box wrapper around existing profile sections.';
    }

    public function up(Schema $schema): void
    {
        // 1. Re-insert the profile page row
        $this->addSql("
            INSERT IGNORE INTO `pages`
                (`keyword`, `url`, `id_parent_page`, `is_headless`, `nav_position`, `footer_position`,
                 `id_page_types`, `id_page_access_types`, `is_open_access`, `is_system`,
                 `id_published_page_versions`)
            VALUES (
                'profile', '/profile', NULL, 0, NULL, NULL,
                (SELECT id FROM `page_types` WHERE `name` = 'core' LIMIT 1),
                (SELECT id FROM `lookups`
                    WHERE `type_code` = 'pageAccessTypes' AND `lookup_code` = 'mobile_and_web' LIMIT 1),
                0, 1, NULL
            )
        ");

        // 2. ACL rows — every group gets select access to the profile page
        $this->addSql("
            INSERT IGNORE INTO `page_acl_groups`
                (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
            SELECT g.id, p.id, 1, 0, 0, 0
            FROM `groups` g, `pages` p
            WHERE p.`keyword` = 'profile'
        ");

        // 3. Box wrapper — full-page background with comfortable padding
        $this->addSql("
            INSERT INTO `sections` (`id_styles`, `name`, `css`)
            SELECT s.id, 'profile-v2-box',
                'min-h-screen w-full bg-gray-50 dark:bg-gray-950 px-4 py-10 sm:px-6 sm:py-16'
            FROM `styles` s WHERE s.`name` = 'box'
        ");
        $this->addSql("
            INSERT INTO `rel_pages_sections` (`id_pages`, `id_sections`, `position`)
            SELECT p.id, sec.id, 10
            FROM `pages` p, `sections` sec
            WHERE p.`keyword` = 'profile' AND sec.`name` = 'profile-v2-box'
        ");

        // 4. Re-attach existing profile-sys-wrapper as child of the box
        $this->addSql("
            INSERT INTO `rel_sections_hierarchy` (`id_parent_section`, `id_child_section`, `position`)
            SELECT box.id, wrapper.id, 10
            FROM `sections` box, `sections` wrapper
            WHERE box.`name` = 'profile-v2-box' AND wrapper.`name` = 'profile-sys-wrapper'
        ");
    }

    public function down(Schema $schema): void
    {
        // Detach profile-sys-wrapper from the box (restore it to orphan state)
        $this->addSql("
            DELETE rsh FROM `rel_sections_hierarchy` rsh
            JOIN `sections` box     ON box.id     = rsh.id_parent_section
            JOIN `sections` wrapper ON wrapper.id = rsh.id_child_section
            WHERE box.`name` = 'profile-v2-box' AND wrapper.`name` = 'profile-sys-wrapper'
        ");

        $this->addSql("DELETE FROM `sections` WHERE `name` = 'profile-v2-box'");

        $this->addSql("
            DELETE pag FROM `page_acl_groups` pag
            JOIN `pages` p ON p.id = pag.id_pages
            WHERE p.`keyword` = 'profile'
        ");

        $this->addSql("DELETE FROM `pages` WHERE `keyword` = 'profile'");
    }
}
