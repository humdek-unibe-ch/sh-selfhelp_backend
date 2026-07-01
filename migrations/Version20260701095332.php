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
 * Navigation admin API routes, search routes, last-visited route, permissions
 * (granted to the admin role), search_visibility field.
 */
final class Version20260701095332 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const PERM_READ = 'admin.navigation.read';
    private const PERM_UPDATE = 'admin.navigation.update';

    public function getDescription(): string
    {
        return 'Navigation admin/search API routes, admin.navigation permissions (granted to admin role), search_visibility page field seed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES (?, 'Read navigation menus and settings')",
            [self::PERM_READ]
        );
        $this->addSql(
            "INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES (?, 'Update navigation menus, items, and settings')",
            [self::PERM_UPDATE]
        );
        $this->addSql(
            'INSERT IGNORE INTO `rel_permissions_roles` (`id_permissions`, `id_roles`) '
            . 'SELECT p.id, r.id FROM `permissions` p JOIN `roles` r ON r.name = ? '
            . 'WHERE p.name IN (?, ?)',
            ['admin', self::PERM_READ, self::PERM_UPDATE],
        );

        $routes = [
            ['admin_navigation_get', '/admin/navigation', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::getOverview', 'GET', self::PERM_READ],
            ['admin_navigation_menu_preview', '/admin/navigation/menus/{menu_key}/preview', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::previewMenu', 'GET', self::PERM_READ],
            ['admin_navigation_menu_update', '/admin/navigation/menus/{menu_key}', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::updateMenu', 'PUT', self::PERM_UPDATE],
            ['admin_navigation_menu_item_create', '/admin/navigation/menus/{menu_key}/items', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::createMenuItem', 'POST', self::PERM_UPDATE],
            ['admin_navigation_menu_item_update', '/admin/navigation/items/{item_id}', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::updateMenuItem', 'PUT', self::PERM_UPDATE],
            ['admin_navigation_menu_item_delete', '/admin/navigation/items/{item_id}', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::deleteMenuItem', 'DELETE', self::PERM_UPDATE],
            ['admin_navigation_menu_reorder', '/admin/navigation/menus/{menu_key}/reorder', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::reorderMenuItems', 'PUT', self::PERM_UPDATE],
            ['admin_navigation_settings_update', '/admin/navigation/settings', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::updateSettings', 'PUT', self::PERM_UPDATE],
            ['search_get', '/search', 'App\\Controller\\Api\\V1\\Frontend\\SearchController::search', 'GET', null],
            ['search_pages_get', '/search/pages', 'App\\Controller\\Api\\V1\\Frontend\\SearchController::searchPages', 'GET', null],
            ['navigation_last_visited_put', '/navigation/last-visited', 'App\\Controller\\Api\\V1\\Frontend\\NavigationController::recordLastVisited', 'PUT', null],
        ];

        foreach ($routes as [$name, $path, $controller, $methods, $permission]) {
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$name, self::VERSION]);
            $this->addSql(
                'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
                [$name, self::VERSION, $path, $controller, $methods]
            );
            if ($permission !== null) {
                $this->addSql(
                    'INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) '
                    . 'SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = ? WHERE ar.route_name = ? AND ar.version = ?',
                    [$permission, $name, self::VERSION]
                );
            }
        }

        $this->seedSearchVisibilityField();
    }

    private function seedSearchVisibilityField(): void
    {
        $config = json_encode([
            'options' => [
                ['value' => 'inherit', 'text' => 'Use global setting'],
                ['value' => 'visible', 'text' => 'Show in search'],
                ['value' => 'hidden', 'text' => 'Hide from search'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->addSql(
            <<<'SQL'
            INSERT INTO fields (name, id_field_types, display, config)
            SELECT 'search_visibility', ft.id, 0, ?
            FROM field_types ft
            WHERE ft.name = 'select'
            LIMIT 1
            ON DUPLICATE KEY UPDATE
                id_field_types = VALUES(id_field_types),
                config = VALUES(config),
                display = VALUES(display)
            SQL,
            [$config],
        );

        foreach (['core', 'experiment'] as $pageType) {
            $this->addSql(
                <<<'SQL'
                INSERT IGNORE INTO rel_fields_page_types (id_page_types, id_fields, title, help, default_value)
                SELECT pt.id, f.id, ?, ?, 'inherit'
                FROM page_types pt
                JOIN fields f ON f.name = 'search_visibility'
                WHERE pt.name = ?
                SQL,
                [
                    'Search visibility',
                    'Controls whether this page can appear in website/app search results. Access permissions still apply.',
                    $pageType,
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $routeNames = [
            'admin_navigation_get',
            'admin_navigation_menu_preview',
            'admin_navigation_menu_update',
            'admin_navigation_menu_item_create',
            'admin_navigation_menu_item_update',
            'admin_navigation_menu_item_delete',
            'admin_navigation_menu_reorder',
            'admin_navigation_settings_update',
            'search_get',
            'search_pages_get',
            'navigation_last_visited_put',
        ];

        foreach ($routeNames as $name) {
            $this->addSql(
                'DELETE rarp FROM `rel_api_routes_permissions` rarp JOIN `api_routes` ar ON ar.id = rarp.id_api_routes WHERE ar.route_name = ? AND ar.version = ?',
                [$name, self::VERSION]
            );
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$name, self::VERSION]);
        }

        $this->addSql(
            'DELETE rpr FROM `rel_permissions_roles` rpr JOIN `permissions` p ON p.id = rpr.id_permissions WHERE p.name IN (?, ?)',
            [self::PERM_READ, self::PERM_UPDATE],
        );
        $this->addSql('DELETE FROM `permissions` WHERE name IN (?, ?)', [self::PERM_READ, self::PERM_UPDATE]);
    }
}
