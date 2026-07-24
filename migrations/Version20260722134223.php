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
 * Register the group-scoped asset-folder ACL API, which lives alongside the
 * page-ACL surface on the admin Groups page:
 *   GET  /admin/groups/{groupId}/asset-acls -> admin.group.acl
 *   PUT  /admin/groups/{groupId}/asset-acls -> admin.group.acl
 *
 * Storage is the assets_folders_groups table created by Version20260722092220.
 */
final class Version20260722134223 extends AbstractMigration
{
    private const VERSION = 'v1';

    /**
     * @var list<array{name: string, path: string, controller: string, method: string, permission: string}>
     */
    private const ROUTES = [
        [
            'name' => 'admin_groups_asset_acls_get',
            'path' => '/admin/groups/{groupId}/asset-acls',
            'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminGroupController::getGroupAssetAcls',
            'method' => 'GET',
            'permission' => 'admin.group.acl',
        ],
        [
            'name' => 'admin_groups_asset_acls_update',
            'path' => '/admin/groups/{groupId}/asset-acls',
            'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminGroupController::updateGroupAssetAcls',
            'method' => 'PUT',
            'permission' => 'admin.group.acl',
        ],
    ];

    public function getDescription(): string
    {
        return 'Register the group-scoped asset-folder ACL API routes (/admin/groups/{groupId}/asset-acls, admin.group.acl).';
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
                . 'VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
                [$route['name'], self::VERSION, $route['path'], $route['controller'], $route['method']]
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
