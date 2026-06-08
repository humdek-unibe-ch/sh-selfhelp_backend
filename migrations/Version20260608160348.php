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
 * Instance-scoped system maintenance / update (SelfHelp Manager / Docker
 * Distribution MVP).
 *
 * Creates the `system_update_operations` audit table and registers the four
 * admin endpoints that drive the CMS side of the update flow:
 *
 *   - GET  /admin/system/version          -> SystemController::getVersion
 *   - GET  /admin/system/update/preflight -> SystemController::getUpdatePreflight
 *   - POST /admin/system/update/request   -> SystemController::requestUpdate
 *   - GET  /admin/system/update/status    -> SystemController::getUpdateStatus
 *
 * The three read routes are guarded by the new `admin.system.read` permission;
 * the write route by `admin.system.update`. Both permissions are granted to the
 * `admin` role. The CMS never controls Docker — these endpoints only read
 * version facts, compute a compatibility preflight, and record an
 * instance-scoped update request that the SelfHelp Manager performs.
 *
 * `down()` fully reverses the migration: drops the table and removes the routes,
 * permission links, role grants, and the two permissions it created.
 */
final class Version20260608160348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add system_update_operations table + instance-scoped /admin/system version & update routes/permissions (SelfHelp Manager MVP).';
    }

    public function up(Schema $schema): void
    {
        // -- Audit table (generated from the SystemUpdateOperation entity) ----
        $this->addSql('CREATE TABLE system_update_operations (id INT AUTO_INCREMENT NOT NULL, instance_id VARCHAR(190) NOT NULL, operation_id VARCHAR(64) NOT NULL, target_version VARCHAR(50) NOT NULL, preflight_id VARCHAR(64) DEFAULT NULL, status VARCHAR(20) DEFAULT \'requested\' NOT NULL COMMENT \'Operation lifecycle status; CMS writes requested, manager writes execution states\', progress_percent INT DEFAULT 0 NOT NULL, steps_json JSON DEFAULT NULL COMMENT \'Ordered execution steps written back by the manager\', message LONGTEXT DEFAULT NULL, accepted_migration_risk TINYINT DEFAULT 0 NOT NULL, requested_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, id_requested_by_users INT DEFAULT NULL, INDEX idx_system_update_operations_instance_id (instance_id), INDEX idx_system_update_operations_status (status), INDEX idx_system_update_operations_requested_at (requested_at), INDEX fk_system_update_operations_id_requested_by_users (id_requested_by_users), UNIQUE INDEX uq_system_update_operations_operation_id (operation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE system_update_operations ADD CONSTRAINT FK_6FB368695334FEBE FOREIGN KEY (id_requested_by_users) REFERENCES users (id) ON DELETE SET NULL');

        // -- Permissions ------------------------------------------------------
        $this->addSql(
            "INSERT IGNORE INTO `permissions` (`name`, `description`) "
            . "VALUES ('admin.system.read', 'Can read system version and update status for the current instance')"
        );
        $this->addSql(
            "INSERT IGNORE INTO `permissions` (`name`, `description`) "
            . "VALUES ('admin.system.update', 'Can request system updates for the current instance')"
        );

        // Grant both to the admin role (idempotent).
        $this->addSql(
            "INSERT IGNORE INTO `rel_permissions_roles` (`id_permissions`, `id_roles`) "
            . "SELECT p.id, r.id FROM `permissions` p JOIN `roles` r ON r.name = 'admin' "
            . "WHERE p.name IN ('admin.system.read', 'admin.system.update')"
        );

        // -- Routes -----------------------------------------------------------
        $routes = [
            ['admin_system_version', 'GET', '/admin/system/version', 'SystemController::getVersion', 'admin.system.read'],
            ['admin_system_update_preflight', 'GET', '/admin/system/update/preflight', 'SystemController::getUpdatePreflight', 'admin.system.read'],
            ['admin_system_update_request', 'POST', '/admin/system/update/request', 'SystemController::requestUpdate', 'admin.system.update'],
            ['admin_system_update_status', 'GET', '/admin/system/update/status', 'SystemController::getUpdateStatus', 'admin.system.read'],
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
        $routeNames = "'admin_system_version', 'admin_system_update_preflight', 'admin_system_update_request', 'admin_system_update_status'";

        // Route -> permission links, then the routes themselves.
        $this->addSql(
            "DELETE rarp FROM `rel_api_routes_permissions` rarp "
            . "JOIN `api_routes` ar ON ar.id = rarp.id_api_routes "
            . "WHERE ar.route_name IN ({$routeNames}) AND ar.version = 'v1'"
        );
        $this->addSql(
            "DELETE FROM `api_routes` WHERE `route_name` IN ({$routeNames}) AND `version` = 'v1'"
        );

        // Role grants + the two permissions this migration created.
        $this->addSql(
            "DELETE rpr FROM `rel_permissions_roles` rpr "
            . "JOIN `permissions` p ON p.id = rpr.id_permissions "
            . "WHERE p.name IN ('admin.system.read', 'admin.system.update')"
        );
        $this->addSql(
            "DELETE FROM `permissions` WHERE `name` IN ('admin.system.read', 'admin.system.update')"
        );

        // Audit table.
        $this->addSql('ALTER TABLE system_update_operations DROP FOREIGN KEY FK_6FB368695334FEBE');
        $this->addSql('DROP TABLE system_update_operations');
    }
}
