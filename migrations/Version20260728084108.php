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
 * Register the folder-scoped asset open-access API:
 *   GET /admin/assets/folders                       -> admin.asset.read
 *   PUT /admin/assets/folders/{folder}/open-access  -> admin.group.acl
 *
 * The toggle is an access-control decision, so it is gated by the same
 * permission as the group-scoped folder ACL routes (Version20260722134223)
 * rather than by asset-editing permissions. Storage is the assets_folders
 * table created by Version20260728083949.
 */
final class Version20260728084108 extends AbstractMigration
{
    private const VERSION = 'v1';

    /**
     * @var list<array{name: string, path: string, controller: string, method: string, permission: string, requirements: string|null}>
     */
    private const ROUTES = [
        [
            'name' => 'admin_assets_folders_list',
            'path' => '/admin/assets/folders',
            'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::getAssetFolders',
            'method' => 'GET',
            'permission' => 'admin.asset.read',
            'requirements' => null,
        ],
        [
            'name' => 'admin_assets_folder_open_access_update',
            'path' => '/admin/assets/folders/{folder}/open-access',
            'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::updateAssetFolderOpenAccess',
            'method' => 'PUT',
            'permission' => 'admin.group.acl',
            'requirements' => '{"folder":"[a-zA-Z0-9_-]+"}',
        ],
    ];

    public function getDescription(): string
    {
        return 'Register the asset folder open-access API routes (/admin/assets/folders).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROUTES as $route) {
            $this->addSql(
                'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
                [$route['name'], self::VERSION]
            );
            $this->addSql(
                'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) '
                . 'VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)',
                [$route['name'], self::VERSION, $route['path'], $route['controller'], $route['method'], $route['requirements']]
            );
            $this->addSql(
                'INSERT INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
                . 'SELECT ar.id, p.id FROM api_routes ar INNER JOIN permissions p ON p.name = ? '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$route['permission'], $route['name'], self::VERSION]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::ROUTES as $route) {
            $this->addSql(
                'DELETE rarp FROM rel_api_routes_permissions rarp '
                . 'INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$route['name'], self::VERSION]
            );
            $this->addSql(
                'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
                [$route['name'], self::VERSION]
            );
        }
    }
}
