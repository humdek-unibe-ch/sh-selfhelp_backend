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
 * Register the instance-scoped update releases endpoint:
 *
 *   - GET /admin/system/update/releases -> SystemController::getUpdateReleases
 *
 * Lists the core versions published in the official registry (newest first) so
 * the admin "Request an update" version picker is fed from the registry instead
 * of free-typed guesses. Fail-soft: the endpoint degrades to `available: false`
 * when the registry is unreachable.
 *
 * Guarded by the existing `admin.system.read` permission (created by
 * Version20260608160348), so no new permission is introduced.
 *
 * `down()` removes the route and its permission link only (the shared
 * `admin.system.read` permission is owned by the earlier migration).
 */
final class Version20260610124237 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Register GET /admin/system/update/releases route under admin.system.read (registry version picker).';
    }

    public function up(Schema $schema): void
    {
        $routeName = 'admin_system_update_releases';
        $controller = 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\SystemController::getUpdateReleases';

        $this->addSql(
            "INSERT IGNORE INTO `api_routes` "
            . "(`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`) "
            . "VALUES ('{$routeName}', 'v1', 'GET', '/admin/system/update/releases', '{$controller}', '[]', '[]')"
        );
        $this->addSql(
            "INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) "
            . "SELECT ar.id, p.id FROM `api_routes` ar "
            . "JOIN `permissions` p ON p.name = 'admin.system.read' "
            . "WHERE ar.route_name = '{$routeName}' AND ar.version = 'v1'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE rarp FROM `rel_api_routes_permissions` rarp "
            . "JOIN `api_routes` ar ON ar.id = rarp.id_api_routes "
            . "WHERE ar.route_name = 'admin_system_update_releases' AND ar.version = 'v1'"
        );
        $this->addSql(
            "DELETE FROM `api_routes` WHERE `route_name` = 'admin_system_update_releases' AND `version` = 'v1'"
        );
    }
}
