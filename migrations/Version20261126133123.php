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
 * Routes of the plugin platform (contracts C12 and C14):
 *
 * - `GET /admin/actions/plugin-job-types` (`admin.action.read`): the job types that enabled plugins offer to actions.
 * - `GET /admin/plugins/{pluginId}/settings` and `PUT` the same path (`admin.plugins.manage`): the settings and
 *   secrets a plugin declares, and their update.
 *
 * Generated empty with `doctrine:migrations:generate` and filled by hand
 * (wave 2 of task `2026-11-25-plugin-resource-hooks`, part 7). No new permission: both permissions exist.
 */
final class Version20261126133123 extends AbstractMigration
{
    private const VERSION = 'v1';

    private const PLUGIN_ID_REQUIREMENT = '{"pluginId":"[a-z][a-z0-9-]*"}';

    /**
     * @return list<array{route: string, path: string, controller: string, method: string, permission: string, requirements: string|null, params: string|null}>
     */
    private function routes(): array
    {
        return [
            [
                'route' => 'admin_actions_plugin_job_types_get',
                'path' => '/admin/actions/plugin-job-types',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminActionController::getPluginJobTypes',
                'method' => 'GET',
                'permission' => 'admin.action.read',
                'requirements' => null,
                'params' => null,
            ],
            [
                'route' => 'admin_plugins_settings_get',
                'path' => '/admin/plugins/{pluginId}/settings',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\Plugin\\AdminPluginSettingsController::getSettings',
                'method' => 'GET',
                'permission' => 'admin.plugins.manage',
                'requirements' => self::PLUGIN_ID_REQUIREMENT,
                'params' => '{"pluginId":{"in":"path","required":true}}',
            ],
            [
                'route' => 'admin_plugins_settings_put',
                'path' => '/admin/plugins/{pluginId}/settings',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\Plugin\\AdminPluginSettingsController::putSettings',
                'method' => 'PUT',
                'permission' => 'admin.plugins.manage',
                'requirements' => self::PLUGIN_ID_REQUIREMENT,
                'params' => '{"pluginId":{"in":"path","required":true},"values":{"in":"body","type":"object","required":true}}',
            ],
        ];
    }

    public function getDescription(): string
    {
        return 'Register the plugin job types route for actions and the plugin settings routes (contracts C12 and C14).';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->routes() as $route) {
            $this->addSql(
                'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, NULL)',
                [$route['route'], self::VERSION, $route['path'], $route['controller'], $route['method'], $route['requirements'], $route['params']]
            );
            $this->addSql(
                'INSERT INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
                . 'SELECT ar.id, p.id FROM api_routes ar INNER JOIN permissions p ON p.name = ? '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$route['permission'], $route['route'], self::VERSION]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_reverse($this->routes()) as $route) {
            $this->addSql(
                'DELETE rarp FROM rel_api_routes_permissions rarp '
                . 'INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$route['route'], self::VERSION]
            );
            $this->addSql(
                'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
                [$route['route'], self::VERSION]
            );
        }
    }
}
