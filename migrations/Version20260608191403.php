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
 * Maintenance-mode read/write API for the instance-scoped system maintenance UI.
 *
 * Registers two endpoints on the existing SystemController:
 *
 *   - GET /admin/system/maintenance -> SystemController::getMaintenance
 *     (read; guarded by the existing `admin.system.read` permission)
 *   - PUT /admin/system/maintenance -> SystemController::setMaintenance
 *     (write; guarded by the NEW `admin.system.maintenance` permission)
 *
 * Adds the `admin.system.maintenance` permission and grants it to the `admin`
 * role. No schema change — permission + routes + grants only.
 *
 * `down()` fully reverses: removes the routes, their permission links, the role
 * grant, and the permission this migration created.
 */
final class Version20260608191403 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin.system.maintenance permission + GET/PUT /admin/system/maintenance routes (maintenance-mode read/write).';
    }

    public function up(Schema $schema): void
    {
        // -- Permission -------------------------------------------------------
        $this->addSql(
            "INSERT IGNORE INTO `permissions` (`name`, `description`) "
            . "VALUES ('admin.system.maintenance', 'Can enable/disable maintenance mode for the current instance')"
        );
        $this->addSql(
            "INSERT IGNORE INTO `rel_permissions_roles` (`id_permissions`, `id_roles`) "
            . "SELECT p.id, r.id FROM `permissions` p JOIN `roles` r ON r.name = 'admin' "
            . "WHERE p.name = 'admin.system.maintenance'"
        );

        // -- Routes -----------------------------------------------------------
        $routes = [
            ['admin_system_maintenance_get', 'GET', '/admin/system/maintenance', 'SystemController::getMaintenance', 'admin.system.read'],
            ['admin_system_maintenance_set', 'PUT', '/admin/system/maintenance', 'SystemController::setMaintenance', 'admin.system.maintenance'],
        ];

        foreach ($routes as [$routeName, $method, $path, $action, $permission]) {
            $controller = 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\' . $action;
            $this->addSql(
                "INSERT IGNORE INTO `api_routes` "
                . "(`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`) "
                . "VALUES ('{$routeName}', 'v1', '{$method}', '{$path}', '{$controller}', '[]', '[]')"
            );
            $this->addSql(
                "INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) "
                . "SELECT ar.id, p.id FROM `api_routes` ar "
                . "JOIN `permissions` p ON p.name = '{$permission}' "
                . "WHERE ar.route_name = '{$routeName}' AND ar.version = 'v1'"
            );
        }
    }

    public function down(Schema $schema): void
    {
        $routeNames = "'admin_system_maintenance_get', 'admin_system_maintenance_set'";

        // Route -> permission links, then the routes themselves.
        $this->addSql(
            "DELETE rarp FROM `rel_api_routes_permissions` rarp "
            . "JOIN `api_routes` ar ON ar.id = rarp.id_api_routes "
            . "WHERE ar.route_name IN ({$routeNames}) AND ar.version = 'v1'"
        );
        $this->addSql(
            "DELETE FROM `api_routes` WHERE `route_name` IN ({$routeNames}) AND `version` = 'v1'"
        );

        // Role grant + the permission this migration created.
        $this->addSql(
            "DELETE rpr FROM `rel_permissions_roles` rpr "
            . "JOIN `permissions` p ON p.id = rpr.id_permissions "
            . "WHERE p.name = 'admin.system.maintenance'"
        );
        $this->addSql(
            "DELETE FROM `permissions` WHERE `name` = 'admin.system.maintenance'"
        );
    }
}
