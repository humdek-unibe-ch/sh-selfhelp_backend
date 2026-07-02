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
 * Navigation bundle export/import: translation presentation fields, depth cap,
 * export/import API routes and permissions.
 */
final class Version20260702164932 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const PERM_EXPORT = 'admin.navigation.export';
    private const PERM_IMPORT = 'admin.navigation.import';

    public function getDescription(): string
    {
        return 'Navigation bundle export/import routes, permissions, menu translation description/aria_label, max_depth=2.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE navigation_menu_item_translations ADD description VARCHAR(500) DEFAULT NULL, ADD aria_label VARCHAR(255) DEFAULT NULL');
        $this->addSql('UPDATE navigation_menus SET max_depth = 2 WHERE max_depth IS NULL OR max_depth > 2');

        $this->addSql(
            "INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES (?, 'Export navigation menus and settings')",
            [self::PERM_EXPORT],
        );
        $this->addSql(
            "INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES (?, 'Import navigation menus and settings')",
            [self::PERM_IMPORT],
        );
        $this->addSql(
            'INSERT IGNORE INTO `rel_permissions_roles` (`id_permissions`, `id_roles`) '
            . 'SELECT p.id, r.id FROM `permissions` p JOIN `roles` r ON r.name = ? '
            . 'WHERE p.name IN (?, ?)',
            ['admin', self::PERM_EXPORT, self::PERM_IMPORT],
        );

        $routes = [
            ['admin_navigation_export', '/admin/navigation/export', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::exportNavigation', 'POST', self::PERM_EXPORT],
            ['admin_navigation_import_validate', '/admin/navigation/import/validate', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::validateImportNavigation', 'POST', self::PERM_IMPORT],
            ['admin_navigation_import', '/admin/navigation/import', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::importNavigation', 'POST', self::PERM_IMPORT],
        ];

        foreach ($routes as [$name, $path, $controller, $methods, $permission]) {
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$name, self::VERSION]);
            $this->addSql(
                'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
                [$name, self::VERSION, $path, $controller, $methods],
            );
            $this->addSql(
                'INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) '
                . 'SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = ? WHERE ar.route_name = ? AND ar.version = ?',
                [$permission, $name, self::VERSION],
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['admin_navigation_export', 'admin_navigation_import_validate', 'admin_navigation_import'] as $routeName) {
            $this->addSql('DELETE FROM `rel_api_routes_permissions` WHERE id_api_routes IN (SELECT id FROM `api_routes` WHERE route_name = ? AND version = ?)', [$routeName, self::VERSION]);
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$routeName, self::VERSION]);
        }

        $this->addSql('DELETE FROM `rel_permissions_roles` WHERE id_permissions IN (SELECT id FROM `permissions` WHERE name IN (?, ?))', [self::PERM_EXPORT, self::PERM_IMPORT]);
        $this->addSql('DELETE FROM `permissions` WHERE name IN (?, ?)', [self::PERM_EXPORT, self::PERM_IMPORT]);
        $this->addSql('ALTER TABLE navigation_menu_item_translations DROP description, DROP aria_label');
    }
}
