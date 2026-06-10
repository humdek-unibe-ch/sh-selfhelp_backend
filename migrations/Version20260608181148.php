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
 * Registers the aggregated, instance-scoped system health endpoint:
 *
 *   - GET /admin/system/health -> SystemController::getHealth
 *
 * Guarded by the existing `admin.system.read` permission (created by
 * Version20260608160348). No schema change — route + permission link only.
 *
 * `down()` removes the route and its permission link.
 */
final class Version20260608181148 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Register GET /admin/system/health (aggregated instance health/status) under admin.system.read.';
    }

    public function up(Schema $schema): void
    {
        $controller = 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\SystemController::getHealth';
        $this->addSql(
            "INSERT IGNORE INTO `api_routes` "
            . "(`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`) "
            . "VALUES ('admin_system_health', 'v1', 'GET', '/admin/system/health', '{$controller}', '[]', '[]')"
        );
        $this->addSql(
            "INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) "
            . "SELECT ar.id, p.id FROM `api_routes` ar "
            . "JOIN `permissions` p ON p.name = 'admin.system.read' "
            . "WHERE ar.route_name = 'admin_system_health' AND ar.version = 'v1'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE rarp FROM `rel_api_routes_permissions` rarp "
            . "JOIN `api_routes` ar ON ar.id = rarp.id_api_routes "
            . "WHERE ar.route_name = 'admin_system_health' AND ar.version = 'v1'"
        );
        $this->addSql(
            "DELETE FROM `api_routes` WHERE `route_name` = 'admin_system_health' AND `version` = 'v1'"
        );
    }
}
