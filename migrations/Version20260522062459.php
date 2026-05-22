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
 * Plugin manager API surface: seeds the admin plugin permissions and
 * the new `/cms-api/v1/admin/plugins/...` + `/cms-api/v1/plugins/manifest`
 * routes.
 *
 * Permissions added:
 *   - admin.plugins.manage  - list/inspect plugins, sources, operations, feature flags.
 *   - admin.plugins.execute - request install/update/uninstall/purge/repair/rollback.
 *   - admin.plugins.purge   - additional gate for the destructive purge operation.
 *
 * Route convention: every plugin admin route is registered under
 * `route_name` with the `plugins_` prefix so the existing route loader
 * picks them up unchanged.
 *
 * Manifest endpoint (`plugins_manifest`) is intentionally public:
 * frontend/mobile `plugins:sync` scripts and the admin UI consume it.
 * Sensitive data (operation logs, secrets, signatures) lives under the
 * admin routes that require `admin.plugins.manage`.
 */
final class Version20260522062459 extends AbstractMigration
{
    private const PERMISSIONS = [
        ['name' => 'admin.plugins.manage', 'description' => 'List and inspect plugins, sources, operations, and feature flags.'],
        ['name' => 'admin.plugins.execute', 'description' => 'Request plugin install / update / uninstall / repair / rollback.'],
        ['name' => 'admin.plugins.purge', 'description' => 'Execute the destructive plugin purge operation.'],
    ];

    /**
     * Route declarations: [route_name, methods, path, controller, requirements, params, permissions[]]
     *
     * @return list<array{
     *   route_name: string,
     *   methods: string,
     *   path: string,
     *   controller: string,
     *   requirements: ?string,
     *   params: ?string,
     *   permissions: list<string>,
     * }>
     */
    private function routeDefinitions(): array
    {
        $r = static fn(string $name, string $method, string $path, string $controller, ?string $requirements = null, ?string $params = null, array $perms = ['admin.plugins.manage']) => [
            'route_name' => $name,
            'methods' => $method,
            'path' => $path,
            'controller' => $controller,
            'requirements' => $requirements,
            'params' => $params,
            'permissions' => $perms,
        ];

        return [
            // Plugin list + detail
            $r('admin_plugins_list', 'GET', '/admin/plugins', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::listPlugins'),
            $r('admin_plugins_get', 'GET', '/admin/plugins/{pluginId}', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::getPlugin', "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')"),
            // Install / update lifecycle
            $r('admin_plugins_request_install', 'POST', '/admin/plugins', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::requestInstall', null, null, ['admin.plugins.execute']),
            $r('admin_plugins_finalize_install', 'POST', '/admin/plugins/{pluginId}/finalize-install', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::finalizeInstall', "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')", null, ['admin.plugins.execute']),
            $r('admin_plugins_request_update', 'POST', '/admin/plugins/{pluginId}/request-update', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::requestUpdate', "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')", null, ['admin.plugins.execute']),
            $r('admin_plugins_finalize_update', 'POST', '/admin/plugins/{pluginId}/finalize-update', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::finalizeUpdate', "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')", null, ['admin.plugins.execute']),
            $r('admin_plugins_enable', 'POST', '/admin/plugins/{pluginId}/enable', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::enable', "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')", null, ['admin.plugins.execute']),
            $r('admin_plugins_disable', 'POST', '/admin/plugins/{pluginId}/disable', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::disable', "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')", null, ['admin.plugins.execute']),
            $r('admin_plugins_uninstall', 'POST', '/admin/plugins/{pluginId}/uninstall', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::uninstall', "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')", null, ['admin.plugins.execute']),
            $r('admin_plugins_purge', 'POST', '/admin/plugins/{pluginId}/purge', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::purge', "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')", null, ['admin.plugins.purge']),
            $r('admin_plugins_repair_one', 'POST', '/admin/plugins/{pluginId}/repair', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::repairOne', "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')", null, ['admin.plugins.execute']),
            $r('admin_plugins_repair_all', 'POST', '/admin/plugins/repair', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::repairAll', null, null, ['admin.plugins.execute']),
            // Sources
            $r('admin_plugins_sources_list', 'GET', '/admin/plugins/sources', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginSourceController::listSources'),
            $r('admin_plugins_sources_create', 'POST', '/admin/plugins/sources', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginSourceController::createSource', null, null, ['admin.plugins.execute']),
            $r('admin_plugins_sources_update', 'PUT', '/admin/plugins/sources/{sourceId}', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginSourceController::updateSource', "JSON_OBJECT('sourceId', '[0-9]+')", null, ['admin.plugins.execute']),
            $r('admin_plugins_sources_delete', 'DELETE', '/admin/plugins/sources/{sourceId}', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginSourceController::deleteSource', "JSON_OBJECT('sourceId', '[0-9]+')", null, ['admin.plugins.execute']),
            // Operations
            $r('admin_plugins_operations_list', 'GET', '/admin/plugins/operations', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginOperationController::listOperations'),
            $r('admin_plugins_operations_get', 'GET', '/admin/plugins/operations/{operationId}', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginOperationController::getOperation', "JSON_OBJECT('operationId', '[0-9]+')"),
            $r('admin_plugins_operations_rollback', 'POST', '/admin/plugins/operations/{operationId}/rollback', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginOperationController::rollback', "JSON_OBJECT('operationId', '[0-9]+')", null, ['admin.plugins.execute']),
            // Feature flags
            $r('admin_plugins_flags_list', 'GET', '/admin/plugins/{pluginId}/feature-flags', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginFeatureFlagController::listFlags', "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')"),
            $r('admin_plugins_flags_set', 'POST', '/admin/plugins/{pluginId}/feature-flags', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginFeatureFlagController::setFlag', "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')", null, ['admin.plugins.execute']),
            // Health + safe mode
            $r('admin_plugins_health_one', 'GET', '/admin/plugins/{pluginId}/health', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginHealthController::pluginHealth', "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')"),
            $r('admin_plugins_doctor', 'GET', '/admin/plugins/doctor', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginHealthController::doctor'),
            $r('admin_plugins_safe_mode_enable', 'POST', '/admin/plugins/safe-mode/enable', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginHealthController::enableSafeMode', null, null, ['admin.plugins.execute']),
            $r('admin_plugins_safe_mode_disable', 'POST', '/admin/plugins/safe-mode/disable', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginHealthController::disableSafeMode', null, null, ['admin.plugins.execute']),
        ];
    }

    public function getDescription(): string
    {
        return 'Plugin manager: seed admin.plugins.* permissions and plugin admin/public API routes.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::PERMISSIONS as $perm) {
            $name = $perm['name'];
            $desc = $perm['description'];
            $this->addSql(sprintf(
                "INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES ('%s', '%s')",
                addslashes($name),
                addslashes($desc),
            ));
        }

        foreach ($this->routeDefinitions() as $route) {
            $requirements = $route['requirements'] !== null ? $route['requirements'] : "'[]'";
            $params = $route['params'] !== null ? $route['params'] : "'[]'";
            $this->addSql(sprintf(
                "INSERT IGNORE INTO `api_routes` (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`) VALUES ('%s', 'v1', '%s', '%s', '%s', %s, %s)",
                addslashes($route['route_name']),
                addslashes($route['methods']),
                addslashes($route['path']),
                $route['controller'],
                $requirements,
                $params,
            ));

            foreach ($route['permissions'] as $permName) {
                $this->addSql(sprintf(
                    "INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = '%s' WHERE ar.route_name = '%s' AND ar.version = 'v1'",
                    addslashes($permName),
                    addslashes($route['route_name']),
                ));
            }
        }

        // Public manifest route (no permission link - controller is public read-only).
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                'plugins_manifest',
                'v1',
                'GET',
                '/plugins/manifest',
                'App\\\\Controller\\\\Api\\\\V1\\\\Plugin\\\\PluginManifestController::manifest',
                NULL,
                NULL
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $routeNames = array_map(static fn(array $r) => "'" . addslashes($r['route_name']) . "'", $this->routeDefinitions());
        $routeNames[] = "'plugins_manifest'";
        $list = implode(',', $routeNames);

        $this->addSql("DELETE rap FROM `rel_api_routes_permissions` rap JOIN `api_routes` ar ON ar.id = rap.id_api_routes WHERE ar.route_name IN ({$list})");
        $this->addSql("DELETE FROM `api_routes` WHERE `route_name` IN ({$list})");

        foreach (self::PERMISSIONS as $perm) {
            $this->addSql("DELETE FROM `permissions` WHERE `name` = '" . addslashes($perm['name']) . "'");
        }
    }
}
