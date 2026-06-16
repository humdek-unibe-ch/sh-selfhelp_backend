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
 * Register the instance-scoped FRONTEND-only update endpoints:
 *
 *   - GET  /admin/system/update/frontend/releases  -> SystemController::getUpdateFrontendReleases
 *   - GET  /admin/system/update/frontend/preflight -> SystemController::getUpdateFrontendPreflight
 *   - POST /admin/system/update/frontend/request   -> SystemController::requestFrontendUpdate
 *
 * The frontend ships independently of the core: an instance already on the
 * newest core can still update to a newer compatible frontend. These mirror the
 * core update endpoints — the two reads are guarded by the existing
 * `admin.system.read` permission, the write by `admin.system.update` (both
 * created by Version20260608160348), so no new permission is introduced.
 *
 * `down()` removes the routes and their permission links only (the shared
 * permissions are owned by the earlier migration).
 */
final class Version20260615130915 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Register /admin/system/update/frontend (releases + preflight + request) routes under existing system permissions.';
    }

    public function up(Schema $schema): void
    {
        $routes = [
            ['admin_system_update_frontend_releases', 'GET', '/admin/system/update/frontend/releases', 'SystemController::getUpdateFrontendReleases', 'admin.system.read'],
            ['admin_system_update_frontend_preflight', 'GET', '/admin/system/update/frontend/preflight', 'SystemController::getUpdateFrontendPreflight', 'admin.system.read'],
            ['admin_system_update_frontend_request', 'POST', '/admin/system/update/frontend/request', 'SystemController::requestFrontendUpdate', 'admin.system.update'],
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
        $routeNames = "'admin_system_update_frontend_releases', 'admin_system_update_frontend_preflight', 'admin_system_update_frontend_request'";

        $this->addSql(
            "DELETE rarp FROM `rel_api_routes_permissions` rarp "
            . "JOIN `api_routes` ar ON ar.id = rarp.id_api_routes "
            . "WHERE ar.route_name IN ({$routeNames}) AND ar.version = 'v1'"
        );
        $this->addSql(
            "DELETE FROM `api_routes` WHERE `route_name` IN ({$routeNames}) AND `version` = 'v1'"
        );
    }
}
