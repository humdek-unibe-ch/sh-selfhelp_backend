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
 * Register the group/role "view members/users" routes and their permissions:
 *   GET /admin/groups/{groupId}/users  -> admin.group.read
 *   GET /admin/roles/{roleId}/users    -> admin.role.read
 */
final class Version20260720132523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed api_routes + permissions for the group/role "view members/users" routes.';
    }

    public function up(Schema $schema): void
    {
        $this->seedRoute(
            'admin_groups_users',
            '/admin/groups/{groupId}/users',
            'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminGroupController::getGroupMembers',
            "JSON_OBJECT('groupId', '[0-9]+')",
            'admin.group.read'
        );

        $this->seedRoute(
            'admin_roles_users',
            '/admin/roles/{roleId}/users',
            'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminRoleController::getRoleUsers',
            "JSON_OBJECT('roleId', '[0-9]+')",
            'admin.role.read'
        );
    }

    public function down(Schema $schema): void
    {
        $routeNames = "'admin_groups_users', 'admin_roles_users'";

        $this->addSql(<<<SQL
            DELETE rap FROM `rel_api_routes_permissions` rap
            JOIN `api_routes` ar ON ar.id = rap.id_api_routes
            WHERE ar.route_name IN ({$routeNames})
              AND ar.version = 'v1'
        SQL);

        $this->addSql(<<<SQL
            DELETE FROM `api_routes`
            WHERE route_name IN ({$routeNames})
              AND version = 'v1'
        SQL);
    }

    /**
     * Insert one v1 API route and link it to a permission. INSERT IGNORE on
     * the unique keys so re-running is safe.
     */
    private function seedRoute(string $routeName, string $path, string $controller, string $requirementsSql, string $permission): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                '{$routeName}',
                'v1',
                'GET',
                '{$path}',
                '{$controller}',
                {$requirementsSql},
                NULL
            )
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.name = '{$permission}'
            WHERE ar.route_name = '{$routeName}'
              AND ar.version = 'v1'
        SQL);
    }
}
