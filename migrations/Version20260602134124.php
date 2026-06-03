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
 * Wire the orphaned admin CMS-preferences read endpoint.
 *
 * `AdminCmsPreferenceController::getCmsPreferences()` (GET /admin/cms-preferences)
 * shipped without an `api_routes` row, so `ApiRouteLoader` never registered it
 * and the frontend `useCmsPreferences()` call resolved to a 404. The guarding
 * permission `admin.cms_preferences.read` already exists in the reference seed
 * (Version20260501000100); this migration registers the route, links it to that
 * permission, and ensures the admin role holds it. Every statement is
 * INSERT IGNORE / conditional so the migration is idempotent on installs that
 * already have the permission and grant.
 *
 * Data-only (no schema change). `down()` reverses cleanly by removing the route
 * and its permission link (the link also cascades from the route FK); the
 * pre-existing permission and role grant are intentionally left intact.
 */
final class Version20260602134124 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Register the admin CMS-preferences read route (GET /admin/cms-preferences) and link it to admin.cms_preferences.read.';
    }

    public function up(Schema $schema): void
    {
        // Permission already ships in the reference seed; keep this idempotent
        // for any install that somehow lacks it.
        $this->addSql(
            "INSERT IGNORE INTO `permissions` (`name`, `description`) "
            . "VALUES ('admin.cms_preferences.read', 'Can read CMS preferences')"
        );

        // Ensure the admin role holds the permission (no-op if already granted).
        $this->addSql(
            "INSERT IGNORE INTO `rel_permissions_roles` (`id_permissions`, `id_roles`) "
            . "SELECT p.id, r.id FROM `permissions` p JOIN `roles` r ON r.name = 'admin' "
            . "WHERE p.name = 'admin.cms_preferences.read'"
        );

        // Register the route the frontend admin settings page consumes.
        $this->addSql(
            "INSERT IGNORE INTO `api_routes` "
            . "(`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`) "
            . "VALUES ('admin_cms_preferences_get', 'v1', 'GET', '/admin/cms-preferences', "
            . "'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminCmsPreferenceController::getCmsPreferences', '[]', '[]')"
        );

        // Guard the route with the read permission.
        $this->addSql(
            "INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) "
            . "SELECT ar.id, p.id FROM `api_routes` ar "
            . "JOIN `permissions` p ON p.name = 'admin.cms_preferences.read' "
            . "WHERE ar.route_name = 'admin_cms_preferences_get' AND ar.version = 'v1'"
        );
    }

    public function down(Schema $schema): void
    {
        // Remove only what this migration registered. The permission + role
        // grant predate it (reference seed) and stay in place.
        $this->addSql(
            "DELETE FROM `rel_api_routes_permissions` "
            . "WHERE `id_api_routes` IN ("
            . "SELECT t.id FROM ("
            . "SELECT `id` FROM `api_routes` WHERE `route_name` = 'admin_cms_preferences_get' AND `version` = 'v1'"
            . ") AS t)"
        );
        $this->addSql(
            "DELETE FROM `api_routes` WHERE `route_name` = 'admin_cms_preferences_get' AND `version` = 'v1'"
        );
    }
}
