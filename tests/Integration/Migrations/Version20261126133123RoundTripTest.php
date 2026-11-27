<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Tests\Support\MigrationRoundTripTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Round-trip for Version20261126133123 (contracts C12 and C14): `up()` registers three core routes with the
 * permission each needs, `down()` removes the routes and their permission rows and nothing else.
 *
 *  - `GET /admin/actions/plugin-job-types`        `admin.action.read`
 *  - `GET /admin/plugins/{pluginId}/settings`     `admin.plugins.manage`
 *  - `PUT /admin/plugins/{pluginId}/settings`     `admin.plugins.manage`
 *
 * No new permission is created: both exist already. The routes are core routes (`id_plugins` is null).
 */
#[Group('migration')]
final class Version20261126133123RoundTripTest extends MigrationRoundTripTestCase
{
    private const VERSION = 'DoctrineMigrations\Version20261126133123';

    /** route name => [path, method, controller method, permission] */
    private const ROUTES = [
        'admin_actions_plugin_job_types_get' => ['/admin/actions/plugin-job-types', 'GET', 'AdminActionController::getPluginJobTypes', 'admin.action.read'],
        'admin_plugins_settings_get' => ['/admin/plugins/{pluginId}/settings', 'GET', 'AdminPluginSettingsController::getSettings', 'admin.plugins.manage'],
        'admin_plugins_settings_put' => ['/admin/plugins/{pluginId}/settings', 'PUT', 'AdminPluginSettingsController::putSettings', 'admin.plugins.manage'],
    ];

    public function testTheThreeRoutesAreRegisteredWithTheirPermissionsAndRemovedAgain(): void
    {
        $this->migrate(self::VERSION);
        $routes = $this->queryInt('SELECT COUNT(*) FROM api_routes');
        $grants = $this->queryInt('SELECT COUNT(*) FROM rel_api_routes_permissions');
        $permissions = $this->queryInt('SELECT COUNT(*) FROM permissions');
        $this->assertRegistered('up()');

        $this->migrate('prev'); // down()
        self::assertSame($routes - 3, $this->queryInt('SELECT COUNT(*) FROM api_routes'), 'down(): exactly the three routes are removed.');
        self::assertSame($grants - 3, $this->queryInt('SELECT COUNT(*) FROM rel_api_routes_permissions'), 'down(): and their three permission rows.');
        self::assertSame($permissions, $this->queryInt('SELECT COUNT(*) FROM permissions'), 'down(): no permission is deleted; the permissions are not this migration\'s.');
        foreach (array_keys(self::ROUTES) as $name) {
            self::assertSame(0, $this->queryInt("SELECT COUNT(*) FROM api_routes WHERE route_name = '{$name}'"), "down(): {$name} is gone.");
        }

        $this->migrate(self::VERSION); // up() again
        $this->assertRegistered('up() again');
        self::assertSame($routes, $this->queryInt('SELECT COUNT(*) FROM api_routes'), 'up() again: the same number of routes as the first time.');
        self::assertSame($grants, $this->queryInt('SELECT COUNT(*) FROM rel_api_routes_permissions'), 'up() again: the same number of permission rows.');
        self::assertSame($permissions, $this->queryInt('SELECT COUNT(*) FROM permissions'), 'up() adds no permission.');

        $this->migrate('latest');
        $this->assertSchemaInSync('after round-trip of ' . self::VERSION);
    }

    private function assertRegistered(string $step): void
    {
        foreach (self::ROUTES as $name => [$path, $method, $controller, $permission]) {
            self::assertSame(
                1,
                $this->queryInt("SELECT COUNT(*) FROM api_routes WHERE route_name = '{$name}' AND version = 'v1' AND path = '{$path}' AND methods = '{$method}' AND controller LIKE '%{$controller}' AND id_plugins IS NULL"),
                "{$step}: {$name} is one core route with the right path, method and controller."
            );
            self::assertSame(
                1,
                $this->queryInt(
                    'SELECT COUNT(*) FROM api_routes ar'
                    . ' INNER JOIN rel_api_routes_permissions rarp ON rarp.id_api_routes = ar.id'
                    . ' INNER JOIN permissions p ON p.id = rarp.id_permissions'
                    . " WHERE ar.route_name = '{$name}' AND ar.version = 'v1' AND p.name = '{$permission}'"
                ),
                "{$step}: {$name} needs {$permission}."
            );
            self::assertSame(
                1,
                $this->queryInt("SELECT COUNT(*) FROM rel_api_routes_permissions rarp INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes WHERE ar.route_name = '{$name}' AND ar.version = 'v1'"),
                "{$step}: and only that permission."
            );
        }
    }
}
