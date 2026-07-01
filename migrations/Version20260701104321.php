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
 * Navigation cleanup: drop legacy page menu columns, refresh get_user_acl, add admin routes,
 * remove deprecated web_nav_render / mobile_nav_render page property fields.
 */
final class Version20260701104321 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const PERM_UPDATE = 'admin.navigation.update';

    public function getDescription(): string
    {
        return 'Drop pages.nav_position/footer_position, update get_user_acl, navigation admin routes, remove nav render fields.';
    }

    public function up(Schema $schema): void
    {
        $this->refreshGetUserAclProcedure();
        $this->addSql('ALTER TABLE pages DROP nav_position, DROP footer_position');
        $this->seedNavigationAdminRoutes();
        $this->removeLegacyNavRenderFields();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pages ADD nav_position INT DEFAULT NULL, ADD footer_position INT DEFAULT NULL');

        $routeNames = [
            'admin_navigation_item_exclusion_add',
            'admin_navigation_item_exclusion_remove',
            'admin_navigation_item_convert_auto_children',
        ];
        foreach ($routeNames as $name) {
            $this->addSql(
                'DELETE rarp FROM `rel_api_routes_permissions` rarp JOIN `api_routes` ar ON ar.id = rarp.id_api_routes WHERE ar.route_name = ? AND ar.version = ?',
                [$name, self::VERSION]
            );
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$name, self::VERSION]);
        }

        // Field re-seed is handled by Version20260630130327 on down of that migration in isolation;
        // we only unlink here on rollback of this cleanup step.
        $this->addSql(<<<'SQL'
            DELETE rfs FROM rel_fields_styles rfs
            INNER JOIN fields f ON f.id = rfs.id_fields
            WHERE f.name IN ('web_nav_render', 'mobile_nav_render')
            SQL);
    }

    private function refreshGetUserAclProcedure(): void
    {
        $this->addSql('DROP PROCEDURE IF EXISTS `get_user_acl`');
        $this->addSql(<<<'SQL'
            CREATE PROCEDURE `get_user_acl`(
                IN param_user_id INT,
                IN param_page_id INT
            )
            BEGIN
                SELECT
                    param_user_id AS id_users,
                    id_pages,
                    MAX(acl_select) AS acl_select,
                    MAX(acl_insert) AS acl_insert,
                    MAX(acl_update) AS acl_update,
                    MAX(acl_delete) AS acl_delete,
                    keyword,
                    url,
                    id_parent_page,
                    is_headless,
                    id_page_types,
                    id_page_access_types,
                    is_system
                FROM (
                    SELECT
                        ug.id_users,
                        acl.id_pages,
                        acl.acl_select,
                        acl.acl_insert,
                        acl.acl_update,
                        acl.acl_delete,
                        p.keyword,
                        p.url,
                        p.id_parent_page,
                        p.is_headless,
                        p.id_page_types,
                        p.id_page_access_types,
                        p.is_system
                    FROM rel_groups_users ug
                    JOIN users u             ON ug.id_users   = u.id
                    JOIN page_acl_groups acl ON acl.id_groups = ug.id_groups
                    JOIN pages p             ON p.id          = acl.id_pages
                    WHERE ug.id_users = param_user_id
                      AND (param_page_id = -1 OR acl.id_pages = param_page_id)

                    UNION ALL

                    SELECT
                        param_user_id AS id_users,
                        p.id          AS id_pages,
                        1             AS acl_select,
                        0             AS acl_insert,
                        0             AS acl_update,
                        0             AS acl_delete,
                        p.keyword,
                        p.url,
                        p.id_parent_page,
                        p.is_headless,
                        p.id_page_types,
                        p.id_page_access_types,
                        p.is_system
                    FROM pages p
                    WHERE p.is_open_access = 1
                      AND (param_page_id = -1 OR p.id = param_page_id)
                ) AS combined_acl
                GROUP BY
                    id_pages,
                    keyword,
                    url,
                    id_parent_page,
                    is_headless,
                    id_page_types,
                    is_system,
                    id_page_access_types;
            END
            SQL);
    }

    private function seedNavigationAdminRoutes(): void
    {
        $routes = [
            ['admin_navigation_item_exclusion_add', '/admin/navigation/items/{item_id}/exclusions', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::addExclusion', 'POST'],
            ['admin_navigation_item_exclusion_remove', '/admin/navigation/items/{item_id}/exclusions/{page_id}', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::removeExclusion', 'DELETE'],
            ['admin_navigation_item_convert_auto_children', '/admin/navigation/items/{item_id}/convert-auto-children', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::convertAutoChildren', 'POST'],
        ];

        foreach ($routes as [$name, $path, $controller, $methods]) {
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$name, self::VERSION]);
            $this->addSql(
                'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
                [$name, self::VERSION, $path, $controller, $methods]
            );
            $this->addSql(
                'INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) '
                . 'SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = ? WHERE ar.route_name = ? AND ar.version = ?',
                [self::PERM_UPDATE, $name, self::VERSION]
            );
        }
    }

    private function removeLegacyNavRenderFields(): void
    {
        $this->addSql(<<<'SQL'
            DELETE pft FROM pages_fields_translation pft
            INNER JOIN fields f ON f.id = pft.id_fields
            WHERE f.name IN ('web_nav_render', 'mobile_nav_render')
            SQL);
        $this->addSql(<<<'SQL'
            DELETE rfs FROM rel_fields_styles rfs
            INNER JOIN fields f ON f.id = rfs.id_fields
            WHERE f.name IN ('web_nav_render', 'mobile_nav_render')
            SQL);
        $this->addSql("DELETE FROM fields WHERE name IN ('web_nav_render', 'mobile_nav_render')");
    }
}
