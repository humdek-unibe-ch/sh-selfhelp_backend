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
 * Add MOBILE-PREVIEW-only update support to the system update flow.
 *
 * Schema:
 *   - `system_update_operations.target_mobile_preview_version` : the preview
 *     version a 'mobile-preview' op targets (NULL for core/frontend ops). Doubles
 *     as the enable/bootstrap target when the instance has no preview yet.
 *   - widen the `kind` column comment to mention the new 'mobile-preview' value
 *     (the value itself already fits the existing VARCHAR(16)).
 *
 * Routes (instance-scoped, mirroring the frontend-only update endpoints):
 *   - GET  /admin/system/update/mobile-preview/releases  -> SystemController::getUpdateMobilePreviewReleases
 *   - GET  /admin/system/update/mobile-preview/preflight -> SystemController::getUpdateMobilePreviewPreflight
 *   - POST /admin/system/update/mobile-preview/request   -> SystemController::requestMobilePreviewUpdate
 *
 * The optional `selfhelp-mobile-preview` web image ships independently of the
 * core: an instance already on the newest core can still update (or enable) a
 * newer compatible preview. The two reads reuse the existing `admin.system.read`
 * permission, the write `admin.system.update` (both created by
 * Version20260608160348), so no new permission is introduced.
 *
 * `down()` removes the routes + their permission links and reverts the schema.
 */
final class Version20260623180726 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add target_mobile_preview_version + /admin/system/update/mobile-preview routes (mobile-preview update support).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE system_update_operations "
            . "ADD target_mobile_preview_version VARCHAR(50) DEFAULT NULL, "
            . "CHANGE kind kind VARCHAR(16) DEFAULT 'core' NOT NULL "
            . "COMMENT 'What the operation updates: core (default), frontend (stateless frontend-only swap) or mobile-preview (stateless preview-only swap)'"
        );

        $routes = [
            ['admin_system_update_mobile_preview_releases', 'GET', '/admin/system/update/mobile-preview/releases', 'SystemController::getUpdateMobilePreviewReleases', 'admin.system.read'],
            ['admin_system_update_mobile_preview_preflight', 'GET', '/admin/system/update/mobile-preview/preflight', 'SystemController::getUpdateMobilePreviewPreflight', 'admin.system.read'],
            ['admin_system_update_mobile_preview_request', 'POST', '/admin/system/update/mobile-preview/request', 'SystemController::requestMobilePreviewUpdate', 'admin.system.update'],
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
        $routeNames = "'admin_system_update_mobile_preview_releases', 'admin_system_update_mobile_preview_preflight', 'admin_system_update_mobile_preview_request'";

        $this->addSql(
            "DELETE rarp FROM `rel_api_routes_permissions` rarp "
            . "JOIN `api_routes` ar ON ar.id = rarp.id_api_routes "
            . "WHERE ar.route_name IN ({$routeNames}) AND ar.version = 'v1'"
        );
        $this->addSql(
            "DELETE FROM `api_routes` WHERE `route_name` IN ({$routeNames}) AND `version` = 'v1'"
        );

        $this->addSql(
            "ALTER TABLE system_update_operations "
            . "DROP target_mobile_preview_version, "
            . "CHANGE kind kind VARCHAR(16) DEFAULT 'core' NOT NULL "
            . "COMMENT 'What the operation updates: core (default) or frontend (stateless frontend-only swap)'"
        );
    }
}
