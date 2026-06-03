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
 * Seed a baseline `register` page so self-registration works out of the box.
 *
 * Structure created:
 *   pages: keyword=register, url=/register, core/mobile_and_web, system page
 *     rel_pages_sections → container section (id_styles=container)
 *       rel_sections_hierarchy → register section (id_styles=register)
 *
 * Field values default to the register style's `default_value`
 * (open_registration=0, group=3) — admins can override per section
 * via the CMS.
 */
final class Version20260601095658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed baseline /register page with container + register section.';
    }

    public function up(Schema $schema): void
    {
        // 1. Register page
        $this->addSql("
            INSERT IGNORE INTO `pages`
                (`keyword`, `url`, `id_parent_page`, `is_headless`, `nav_position`, `footer_position`,
                 `id_page_types`, `id_page_access_types`, `is_open_access`, `is_system`,
                 `id_published_page_versions`)
            VALUES (
                'register', '/register', NULL, 0, NULL, NULL,
                (SELECT id FROM `page_types` WHERE `name` = 'core' LIMIT 1),
                (SELECT id FROM `lookups`
                    WHERE `type_code` = 'pageAccessTypes' AND `lookup_code` = 'mobile_and_web' LIMIT 1),
                1, 1, NULL
            )
        ");

        // 2. Container wrapper section
        $this->addSql("
            INSERT INTO `sections` (`id_styles`, `name`, `css`)
            SELECT s.id, 'register-sys-container', ''
            FROM `styles` s WHERE s.`name` = 'container'
        ");
        $this->addSql("
            INSERT INTO `rel_pages_sections` (`id_pages`, `id_sections`, `position`)
            SELECT p.id, sec.id, 10
            FROM `pages` p, `sections` sec
            WHERE p.`keyword` = 'register' AND sec.`name` = 'register-sys-container'
        ");

        // 3. Register section (style 'register'), child of the container
        $this->addSql("
            INSERT INTO `sections` (`id_styles`, `name`, `css`)
            SELECT s.id, 'register-sys-form', ''
            FROM `styles` s WHERE s.`name` = 'register'
        ");
        $this->addSql("
            INSERT INTO `rel_sections_hierarchy` (`id_parent_section`, `id_child_section`, `position`)
            SELECT parent.id, child.id, 10
            FROM `sections` parent, `sections` child
            WHERE parent.`name` = 'register-sys-container' AND child.`name` = 'register-sys-form'
        ");
    }

    public function down(Schema $schema): void
    {
        // Drop hierarchy link
        $this->addSql("
            DELETE rsh FROM `rel_sections_hierarchy` rsh
            JOIN `sections` parent ON parent.id = rsh.id_parent_section
            JOIN `sections` child  ON child.id  = rsh.id_child_section
            WHERE parent.`name` = 'register-sys-container' AND child.`name` = 'register-sys-form'
        ");

        // Drop page→section link
        $this->addSql("
            DELETE rps FROM `rel_pages_sections` rps
            JOIN `pages` p     ON p.id   = rps.id_pages
            JOIN `sections` s  ON s.id   = rps.id_sections
            WHERE p.`keyword` = 'register'
              AND s.`name` IN ('register-sys-container', 'register-sys-form')
        ");

        // Drop sections
        $this->addSql("DELETE FROM `sections` WHERE `name` IN ('register-sys-container', 'register-sys-form')");

        // Drop page
        $this->addSql("DELETE FROM `pages` WHERE `keyword` = 'register'");
    }
}
