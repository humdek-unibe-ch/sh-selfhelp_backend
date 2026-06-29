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
 * Remove the now-unused GET /admin/sections/{section_id}/data-variables route
 * (added by Version20260629063147).
 *
 * Interpolation v2 replaced every per-surface variable picker with the single
 * unified endpoint GET /admin/interpolation/variables (context=section|page|
 * action|global, Version20260629110606). The frontend's `useSectionDataVariables`
 * now delegates to that endpoint, so the per-section route has no remaining
 * consumer (supports.frontend is >=0.1.55, all of which use the unified picker),
 * and its controller action + response schema were removed. This drops the
 * leftover `api_routes` row + its `admin.page.read` link.
 *
 * Reversible: down() re-inserts the route + permission link exactly as
 * Version20260629063147::up() did (the controller action would have to be
 * restored separately for the route to resolve).
 */
final class Version20260629170535 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const ROUTE_NAME = 'admin_sections_data_variables_get';
    private const PERMISSION = 'admin.page.read';

    public function getDescription(): string
    {
        return 'Remove unused admin_sections_data_variables_get route (superseded by admin_interpolation_variables_get).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'DELETE rarp FROM `rel_api_routes_permissions` rarp JOIN `api_routes` ar ON ar.id = rarp.id_api_routes WHERE ar.route_name = ? AND ar.version = ?',
            [self::ROUTE_NAME, self::VERSION]
        );
        $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [self::ROUTE_NAME, self::VERSION]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [self::ROUTE_NAME, self::VERSION]);
        $this->addSql(
            'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)',
            [
                self::ROUTE_NAME,
                self::VERSION,
                '/admin/sections/{section_id}/data-variables',
                'App\\Controller\\Api\\V1\\Admin\\AdminSectionController::getSectionDataVariables',
                'GET',
                '{"section_id": "[0-9]+"}',
            ]
        );
        $this->addSql(
            'INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) '
            . 'SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = ? WHERE ar.route_name = ? AND ar.version = ?',
            [self::PERMISSION, self::ROUTE_NAME, self::VERSION]
        );
    }
}
