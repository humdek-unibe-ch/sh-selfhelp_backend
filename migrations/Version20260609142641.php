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
 * Plugin version pinning (audit finding #52).
 *
 * Adds the `plugins.pinned` flag and the admin pin/unpin API routes. A pinned
 * plugin is never auto-updated by the unified resolver and is treated as a hard
 * block (with a clear "unpin first" reason) by the core update preflight, so an
 * operator who deliberately froze a plugin version keeps it across core updates.
 *
 * Routes (both gated by the existing `admin.plugins.execute` permission):
 *   - POST /cms-api/v1/admin/plugins/{pluginId}/pin
 *   - POST /cms-api/v1/admin/plugins/{pluginId}/unpin
 */
final class Version20260609142641 extends AbstractMigration
{
    /**
     * @return list<array{route_name:string, path:string, controller:string}>
     */
    private function routeDefinitions(): array
    {
        return [
            [
                'route_name' => 'admin_plugins_pin',
                'path' => '/admin/plugins/{pluginId}/pin',
                'controller' => 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::pin',
            ],
            [
                'route_name' => 'admin_plugins_unpin',
                'path' => '/admin/plugins/{pluginId}/unpin',
                'controller' => 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::unpin',
            ],
        ];
    }

    public function getDescription(): string
    {
        return 'Plugin version pinning: add plugins.pinned column and admin pin/unpin API routes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `plugins` ADD `pinned` TINYINT(1) DEFAULT 0 NOT NULL COMMENT 'When true the resolver never auto-updates this plugin; the installed version is preserved until explicitly unpinned'");

        foreach ($this->routeDefinitions() as $route) {
            // `requirements` is a JSON column: pass JSON_OBJECT(...) as a bare
            // MySQL function call (unquoted %s) so it evaluates to a JSON object,
            // exactly like the sibling plugin routes in Version20260523141331.
            // Wrapping it in quotes makes MySQL parse the literal text as JSON and
            // fail with "Invalid JSON text".
            $this->addSql(sprintf(
                "INSERT IGNORE INTO `api_routes` (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`) VALUES ('%s', 'v1', 'POST', '%s', '%s', %s, '[]')",
                addslashes($route['route_name']),
                addslashes($route['path']),
                $route['controller'],
                "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')",
            ));
            $this->addSql(sprintf(
                "INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = 'admin.plugins.execute' WHERE ar.route_name = '%s' AND ar.version = 'v1'",
                addslashes($route['route_name']),
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $routeNames = array_map(static fn (array $r): string => "'" . addslashes($r['route_name']) . "'", $this->routeDefinitions());
        $list = implode(',', $routeNames);

        $this->addSql("DELETE rap FROM `rel_api_routes_permissions` rap JOIN `api_routes` ar ON ar.id = rap.id_api_routes WHERE ar.route_name IN ({$list})");
        $this->addSql("DELETE FROM `api_routes` WHERE `route_name` IN ({$list})");
        $this->addSql('ALTER TABLE `plugins` DROP `pinned`');
    }
}
