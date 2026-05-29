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
 * One-shot industry-grade plugin pipeline migration.
 *
 * Schema changes:
 *   - plugins: drop legacy `frontend_package` + `frontend_package_version`
 *     (legacy npm-host model).
 *   - plugins: add `frontend_runtime_url`, `frontend_runtime_stylesheet_url`,
 *     `frontend_runtime_integrity`, `frontend_runtime_format` (runtime ESM
 *     model, served from `public/plugin-artifacts/<id>-<ver>/`).
 *   - new `messenger_messages` table for the Doctrine Messenger transport
 *     that the `plugin_ops` worker uses.
 *
 * API-route changes:
 *   - register the single-step routes
 *     `admin_plugins_install`, `admin_plugins_inspect_archive`,
 *     `admin_plugins_update`,
 *     `admin_plugins_operations_cancel`.
 *
 * `admin_plugins_available` is registered by the dedicated earlier
 * migration `Version20260522102136.php`; it is intentionally not
 * re-inserted here. There is no separate `/admin/plugins/updates`
 * endpoint — the admin "Installed" tab embeds `availableUpdate`
 * inline through `PluginAdminService::listPlugins()`.
 *
 * The previous legacy two-step routes (`admin_plugins_request_install`,
 * `admin_plugins_finalize_install`, `admin_plugins_request_update`,
 * `admin_plugins_finalize_update`) were dropped from
 * `Version20260522062459.php` pre-release, so the no-op cleanup
 * step that lived here has also been removed.
 *
 * The new install/update routes accept either JSON (registry|url|paste)
 * or multipart/form-data (.shplugin upload) and dispatch a single
 * Messenger message. Finalize lives internal-only on
 * `selfhelp:plugin:run-operation` for managed mode.
 *
 * Permissions:
 *   - read-only routes (`inspect-archive`) require `admin.plugins.manage`,
 *   - state-changing routes (`install`, `update`, `cancel`) require
 *     `admin.plugins.execute`. `inspect-archive` does NOT request
 *     `.execute` because it never persists state — it only validates
 *     the uploaded archive and returns a preview to the UI.
 */
final class Version20260523141331 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plugin runtime ESM + Messenger transport + unified install/update routes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE plugins ADD frontend_runtime_url VARCHAR(1024) DEFAULT NULL COMMENT \'ESM entrypoint URL or host-relative path served from public/plugin-artifacts\', ADD frontend_runtime_stylesheet_url VARCHAR(1024) DEFAULT NULL, ADD frontend_runtime_integrity VARCHAR(255) DEFAULT NULL COMMENT \'Subresource integrity hash for the runtime entrypoint\', ADD frontend_runtime_format VARCHAR(16) DEFAULT \'esm\' NOT NULL, DROP frontend_package, DROP frontend_package_version');

        $newRoutes = [
            [
                'route_name' => 'admin_plugins_install',
                'methods' => 'POST',
                'path' => '/admin/plugins/install',
                'controller' => 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::install',
                'requirements' => null,
                'permission' => 'admin.plugins.execute',
            ],
            [
                'route_name' => 'admin_plugins_inspect_archive',
                'methods' => 'POST',
                'path' => '/admin/plugins/inspect-archive',
                'controller' => 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::inspectArchive',
                'requirements' => null,
                // Read-only operation (extract + validate + return
                // preview, NO state change), so it shares the same
                // permission as the other admin read routes.
                'permission' => 'admin.plugins.manage',
            ],
            [
                'route_name' => 'admin_plugins_update',
                'methods' => 'POST',
                'path' => '/admin/plugins/{pluginId}/update',
                'controller' => 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::update',
                'requirements' => "JSON_OBJECT('pluginId', '[a-z][a-z0-9-]*')",
                'permission' => 'admin.plugins.execute',
            ],
            [
                'route_name' => 'admin_plugins_operations_cancel',
                'methods' => 'POST',
                'path' => '/admin/plugins/operations/{operationId}/cancel',
                'controller' => 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginOperationController::cancel',
                'requirements' => "JSON_OBJECT('operationId', '[0-9]+')",
                'permission' => 'admin.plugins.execute',
            ],
        ];

        foreach ($newRoutes as $route) {
            $requirements = $route['requirements'] !== null ? $route['requirements'] : "'[]'";
            $this->addSql(sprintf(
                "INSERT IGNORE INTO `api_routes` (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`) VALUES ('%s', 'v1', '%s', '%s', '%s', %s, '[]')",
                $route['route_name'],
                $route['methods'],
                $route['path'],
                $route['controller'],
                $requirements,
            ));
            $this->addSql(sprintf(
                "INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = '%s' WHERE ar.route_name = '%s' AND ar.version = 'v1'",
                $route['permission'],
                $route['route_name'],
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('ALTER TABLE plugins ADD frontend_package VARCHAR(255) DEFAULT NULL, ADD frontend_package_version VARCHAR(50) DEFAULT NULL, DROP frontend_runtime_url, DROP frontend_runtime_stylesheet_url, DROP frontend_runtime_integrity, DROP frontend_runtime_format');

        $newRoutes = [
            'admin_plugins_install',
            'admin_plugins_inspect_archive',
            'admin_plugins_update',
            'admin_plugins_operations_cancel',
        ];
        $newList = implode(',', array_map(static fn(string $n): string => "'" . $n . "'", $newRoutes));
        $this->addSql("DELETE rap FROM `rel_api_routes_permissions` rap JOIN `api_routes` ar ON ar.id = rap.id_api_routes WHERE ar.route_name IN ({$newList})");
        $this->addSql("DELETE FROM `api_routes` WHERE `route_name` IN ({$newList})");
    }
}
