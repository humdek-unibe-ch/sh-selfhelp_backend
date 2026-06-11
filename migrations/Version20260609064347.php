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
 * Register the instance-scoped security advisories endpoint:
 *
 *   - GET /admin/system/advisories -> SystemController::getAdvisories
 *
 * Guarded by the existing `admin.system.read` permission (created by
 * Version20260608160348), so no new permission is introduced. The endpoint reads
 * the registry advisory feed and filters it to the components installed on the
 * current instance; it never controls Docker.
 *
 * `down()` removes the route and its permission link only (the shared
 * `admin.system.read` permission is owned by the earlier migration).
 */
final class Version20260609064347 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Register GET /admin/system/advisories route under admin.system.read (security advisories UI).';
    }

    public function up(Schema $schema): void
    {
        $routeName = 'admin_system_advisories';
        $controller = 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\SystemController::getAdvisories';

        $this->addSql(
            "INSERT IGNORE INTO `api_routes` "
            . "(`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`) "
            . "VALUES ('{$routeName}', 'v1', 'GET', '/admin/system/advisories', '{$controller}', '[]', '[]')"
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
            . "WHERE ar.route_name = 'admin_system_advisories' AND ar.version = 'v1'"
        );
        $this->addSql(
            "DELETE FROM `api_routes` WHERE `route_name` = 'admin_system_advisories' AND `version` = 'v1'"
        );
    }
}
