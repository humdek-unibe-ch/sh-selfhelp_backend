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
 * Dashboard analytics contract and standalone rendering for the seeded auth
 * and maintenance flows. The analytics tables and branding columns are part of
 * the consolidated structural migration.
 */
final class Version20260710093046 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const PERMISSION = 'admin.analytics.read';

    /** @var list<string> */
    private const HEADLESS_PAGE_KEYWORDS = [
        'register',
        'reset-password',
        'validate',
        'maintenance',
    ];

    /** @var list<array{name: string, path: string, controller: string}> */
    private const ROUTES = [
        [
            'name' => 'admin_analytics_summary',
            'path' => '/admin/analytics/summary',
            'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminAnalyticsController::getSummary',
        ],
        [
            'name' => 'admin_analytics_today',
            'path' => '/admin/analytics/today',
            'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminAnalyticsController::getToday',
        ],
    ];

    public function getDescription(): string
    {
        return 'Seed analytics permission/routes and make the core register, reset, validate, and maintenance pages headless.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT IGNORE INTO permissions (name, description) VALUES (?, 'Read dashboard analytics (page views, today operations)')",
            [self::PERMISSION],
        );
        $this->addSql(
            'INSERT IGNORE INTO rel_permissions_roles (id_permissions, id_roles) '
            . 'SELECT p.id, r.id FROM permissions p JOIN roles r ON r.name = ? WHERE p.name = ?',
            ['admin', self::PERMISSION],
        );

        foreach (self::ROUTES as $route) {
            $this->addSql(
                'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) '
                . 'VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL) '
                . 'ON DUPLICATE KEY UPDATE path = VALUES(path), controller = VALUES(controller), methods = VALUES(methods), '
                . 'requirements = VALUES(requirements), params = VALUES(params), id_plugins = NULL',
                [$route['name'], self::VERSION, $route['path'], $route['controller'], 'GET'],
            );
            $this->addSql(
                'INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
                . 'SELECT ar.id, p.id FROM api_routes ar JOIN permissions p ON p.name = ? '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [self::PERMISSION, $route['name'], self::VERSION],
            );
        }

        $this->addSql(
            'UPDATE pages SET is_headless = 1 WHERE is_system = 1 AND keyword IN (?)',
            [self::HEADLESS_PAGE_KEYWORDS],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE pages SET is_headless = 0 WHERE is_system = 1 AND keyword IN (?)',
            [self::HEADLESS_PAGE_KEYWORDS],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        foreach (self::ROUTES as $route) {
            $this->addSql(
                'DELETE rarp FROM rel_api_routes_permissions rarp '
                . 'JOIN api_routes ar ON ar.id = rarp.id_api_routes '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$route['name'], self::VERSION],
            );
            $this->addSql(
                'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
                [$route['name'], self::VERSION],
            );
        }

        $this->addSql(
            'DELETE rpr FROM rel_permissions_roles rpr '
            . 'JOIN permissions p ON p.id = rpr.id_permissions WHERE p.name = ?',
            [self::PERMISSION],
        );
        $this->addSql('DELETE FROM permissions WHERE name = ?', [self::PERMISSION]);
    }
}
