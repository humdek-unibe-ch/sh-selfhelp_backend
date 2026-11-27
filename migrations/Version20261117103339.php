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
 * Admin routes for plugin data-access resource types (H6): the types that
 * enabled plugins add to the `resourceTypes` lookup, and the resources of one
 * type, for the "Permissions for role" screen. Same permission as the
 * existing role data-access reads.
 */
final class Version20261117103339 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const PERMISSION = 'admin.role.read';

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string|null}> */
    private const ROUTES = [
        [
            'admin_data_access_plugin_resource_types',
            '/admin/data-access/plugin-resource-types',
            'App\\Controller\\Api\\V1\\Admin\\AdminDataAccessController::getPluginResourceTypes',
            'GET',
            null,
        ],
        [
            'admin_data_access_plugin_resources',
            '/admin/data-access/plugin-resource-types/{typeCode}/resources',
            'App\\Controller\\Api\\V1\\Admin\\AdminDataAccessController::getPluginResources',
            'GET',
            '{"typeCode":"[A-Za-z0-9_.-]+"}',
        ],
    ];

    public function getDescription(): string
    {
        return 'Register admin routes listing plugin data-access resource types and their resources (admin.role.read).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROUTES as [$name, $path, $controller, $method, $requirements]) {
            $this->addSql('DELETE FROM api_routes WHERE route_name = ? AND version = ?', [$name, self::VERSION]);
            $this->addSql(
                'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)',
                [$name, self::VERSION, $path, $controller, $method, $requirements],
            );
            $this->addSql(
                'INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
                . 'SELECT ar.id, p.id FROM api_routes ar JOIN permissions p ON p.name = ? '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [self::PERMISSION, $name, self::VERSION],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $names = array_column(self::ROUTES, 0);
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $this->addSql(
            "DELETE rarp FROM rel_api_routes_permissions rarp "
            . "INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes "
            . "WHERE ar.route_name IN ($placeholders) AND ar.version = ?",
            [...$names, self::VERSION],
        );
        $this->addSql(
            "DELETE FROM api_routes WHERE route_name IN ($placeholders) AND version = ?",
            [...$names, self::VERSION],
        );
    }
}
