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
 * Admin "Example bundles" listing route (issue #30, decision E).
 *
 * Adds one read-only admin endpoint that powers the import UI's ready-made
 * example page bundles:
 *   GET /admin/pages/examples -> getExampleBundles (admin.page.export)
 *
 * The static `/admin/pages/examples` path sorts before the dynamic
 * `/admin/pages/{page_id}` route in `ApiRouteLoader`, so it is not shadowed.
 * It reuses the read-oriented `admin.page.export` permission (importing the
 * chosen bundle is still gated separately by `admin.page.create`).
 * DELETE-first + INSERT IGNORE keep the migration idempotent.
 */
final class Version20260630132428 extends AbstractMigration
{
    private const VERSION = 'v1';

    /** @var list<array{name: string, path: string, method: string, controller: string, permission: string, requirements: ?string, params: ?string}> */
    private const ROUTES = [
        [
            'name' => 'admin_pages_examples',
            'path' => '/admin/pages/examples',
            'method' => 'GET',
            'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminPageController::getExampleBundles',
            'permission' => 'admin.page.export',
            'requirements' => null,
            'params' => null,
        ],
    ];

    public function getDescription(): string
    {
        return 'Seed admin page "Example bundles" listing api_route (issue #30 decision E): GET /admin/pages/examples.';
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
