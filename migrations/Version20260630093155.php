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
 * Admin page export/import API routes (issue #30, Phase 5).
 *
 * Adds four admin endpoints that power the page bundle export/import flow:
 *   POST /admin/pages/export                    -> exportPages          (admin.page.export)
 *   GET  /admin/pages/{page_id}/export/suggest  -> suggestExportBundle  (admin.page.export)
 *   POST /admin/pages/import/validate           -> validateImportPages  (admin.page.create)
 *   POST /admin/pages/import                     -> importPages          (admin.page.create)
 *
 * Export reuses the existing `admin.page.export` permission; import creates
 * pages so it is gated by `admin.page.create`. The static `/admin/pages/export`,
 * `/admin/pages/import` and `/admin/pages/import/validate` paths sort before the
 * dynamic `/admin/pages/{page_id}` routes in `ApiRouteLoader`, so they are not
 * shadowed. INSERT IGNORE + a DELETE-first guard keep the migration idempotent.
 */
final class Version20260630093155 extends AbstractMigration
{
    private const VERSION = 'v1';

    /** @var list<array{name: string, path: string, method: string, controller: string, permission: string, requirements: ?string, params: ?string}> */
    private const ROUTES = [
        [
            'name' => 'admin_pages_export',
            'path' => '/admin/pages/export',
            'method' => 'POST',
            'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminPageController::exportPages',
            'permission' => 'admin.page.export',
            'requirements' => null,
            'params' => '{"pageIds": {"in": "body", "required": true, "type": "array"}}',
        ],
        [
            'name' => 'admin_pages_export_suggest',
            'path' => '/admin/pages/{page_id}/export/suggest',
            'method' => 'GET',
            'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminPageController::suggestExportBundle',
            'permission' => 'admin.page.export',
            'requirements' => '{"page_id": "[0-9]+"}',
            'params' => null,
        ],
        [
            'name' => 'admin_pages_import_validate',
            'path' => '/admin/pages/import/validate',
            'method' => 'POST',
            'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminPageController::validateImportPages',
            'permission' => 'admin.page.create',
            'requirements' => null,
            'params' => '{"bundle": {"in": "body", "required": true, "type": "object"}, "options": {"in": "body", "required": false, "type": "object"}}',
        ],
        [
            'name' => 'admin_pages_import',
            'path' => '/admin/pages/import',
            'method' => 'POST',
            'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminPageController::importPages',
            'permission' => 'admin.page.create',
            'requirements' => null,
            'params' => '{"bundle": {"in": "body", "required": true, "type": "object"}, "options": {"in": "body", "required": false, "type": "object"}}',
        ],
    ];

    public function getDescription(): string
    {
        return 'Seed admin page export/import api_routes (issue #30 Phase 5): export, export/suggest, import/validate, import.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROUTES as $route) {
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$route['name'], self::VERSION]);
            $this->addSql(
                'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, NULL)',
                [
                    $route['name'],
                    self::VERSION,
                    $route['path'],
                    $route['controller'],
                    $route['method'],
                    $route['requirements'],
                    $route['params'],
                ]
            );
            $this->addSql(
                'INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) '
                . 'SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = ? '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$route['permission'], $route['name'], self::VERSION]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::ROUTES as $route) {
            $this->addSql(
                'DELETE rarp FROM `rel_api_routes_permissions` rarp '
                . 'JOIN `api_routes` ar ON ar.id = rarp.id_api_routes '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$route['name'], self::VERSION]
            );
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$route['name'], self::VERSION]);
        }
    }
}
