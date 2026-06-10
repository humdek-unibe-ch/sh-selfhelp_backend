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
 * Replace GET /admin/sections/{section_id}/pages (single ID, path param) with
 * GET /admin/sections/pages?ids[]=1&ids[]=2 (batch, query param).
 */
final class Version20260610123849 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace admin_sections_pages route with batch variant: GET /admin/sections/pages?ids[]=…';
    }

    public function up(Schema $schema): void
    {
        // Remove old single-ID route and its permission links
        $this->addSql(<<<SQL
            DELETE rp FROM `rel_api_routes_permissions` rp
            JOIN `api_routes` ar ON ar.id = rp.id_api_routes
            WHERE ar.route_name = 'admin_sections_pages' AND ar.version = 'v1'
        SQL);
        $this->addSql("DELETE FROM `api_routes` WHERE `route_name` = 'admin_sections_pages' AND `version` = 'v1'");

        // Add new batch route (no path requirements, controller method renamed)
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `path`, `controller`, `methods`, `requirements`, `params`)
            VALUES (
                'admin_sections_pages_batch',
                'v1',
                '/admin/sections/pages',
                'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminSectionUtilityController::getPagesBySections',
                'GET',
                NULL,
                NULL
            )
        SQL);
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.name = 'admin.page.update'
            WHERE ar.route_name = 'admin_sections_pages_batch' AND ar.version = 'v1'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Remove batch route
        $this->addSql(<<<SQL
            DELETE rp FROM `rel_api_routes_permissions` rp
            JOIN `api_routes` ar ON ar.id = rp.id_api_routes
            WHERE ar.route_name = 'admin_sections_pages_batch' AND ar.version = 'v1'
        SQL);
        $this->addSql("DELETE FROM `api_routes` WHERE `route_name` = 'admin_sections_pages_batch' AND `version` = 'v1'");

        // Restore old single-ID route
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
}
