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
 * Add GET /admin/sections/{section_id}/pages — returns the list of pages
 * (id + keyword) that reference a given section anywhere in their hierarchy.
 * Used by the FE to show which pages will be affected before deleting a
 * shared section such as a refContainer.
 */
final class Version20260609113717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin_sections_pages route: GET /admin/sections/{section_id}/pages.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `path`, `controller`, `methods`, `requirements`, `params`)
            VALUES (
                'admin_sections_pages',
                'v1',
                '/admin/sections/{section_id}/pages',
                'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminSectionUtilityController::getPagesBySection',
                'GET',
                JSON_OBJECT('section_id', '[0-9]+'),
                NULL
            )
        SQL);
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.name = 'admin.page.update'
            WHERE ar.route_name = 'admin_sections_pages' AND ar.version = 'v1'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE rp FROM `rel_api_routes_permissions` rp
            JOIN `api_routes` ar ON ar.id = rp.id_api_routes
            WHERE ar.route_name = 'admin_sections_pages' AND ar.version = 'v1'
        SQL);
        $this->addSql("DELETE FROM `api_routes` WHERE `route_name` = 'admin_sections_pages' AND `version` = 'v1'");
    }
}
