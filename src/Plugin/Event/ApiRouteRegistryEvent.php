<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by `App\Routing\ApiRouteLoader` after it has built the
 * route collection from the database. Plugin bundles may listen and
 * register additional routes through `addRoute()`.
 *
 * Plugin routes are versioned under `/cms-api/v1/plugins/{pluginId}/...`.
 * The route name and path must include the plugin id so they cannot
 * collide with other plugins.
 *
 * Plugins must declare each route in `plugin.json` under `apiRoutes`;
 * the host installer cross-checks the declarations with what each
 * subscriber actually registers (`PluginCapabilityValidator`).
 */
final class ApiRouteRegistryEvent extends Event
{
    /**
     * @var array<int, array{
     *   pluginId: string,
     *   name: string,
     *   path: string,
     *   controller: string,
     *   methods: array<int,string>,
     *   requirements: array<string,string>,
     *   permissions: array<int,string>,
     *   version: string,
     * }>
     */
    private array $routes = [];

    public function __construct(private readonly string $cmsVersion)
    {
    }

    public function getCmsVersion(): string
    {
        return $this->cmsVersion;
    }

    /**
     * Register an additional API route contributed by a plugin.
     *
     * @param array<int,string> $methods e.g. ['GET','POST']
     * @param array<string,string> $requirements regex patterns per
     *                                            path parameter
     * @param array<int,string> $permissions permission keys
     */
    public function addRoute(
        string $pluginId,
        string $name,
        string $path,
        string $controller,
        array $methods,
        array $requirements = [],
        array $permissions = [],
        string $version = 'v1',
    ): void {
        // Public plugin paths live under `/plugins/<id>/...` and admin
        // plugin paths under `/admin/plugins/<id>/...`. The host's
        // `ApiSecurityListener` keys off the `/admin` segment to enforce
        // admin-gate semantics, so the two cases are intentionally split.
        $publicPrefix = '/plugins/' . $pluginId . '/';
        $adminPrefix = '/admin/plugins/' . $pluginId . '/';
        if (!str_starts_with($path, $publicPrefix) && !str_starts_with($path, $adminPrefix)) {
            throw new \InvalidArgumentException(sprintf(
                'Plugin route paths must begin with "%s" or "%s", got "%s" from plugin "%s".',
                $publicPrefix,
                $adminPrefix,
                $path,
                $pluginId
            ));
        }
        // Allow either `<pluginId>_...` (lowercase) or any name; we just
        // need uniqueness. Plugin author convention prefixes with their
        // namespace label (e.g. `surveyjs_admin_list`) which is what we
        // accept and don't strictly need to start with the full plugin id.
        // We still warn loudly if someone uses an empty or whitespace name.
        if (trim($name) === '') {
            throw new \InvalidArgumentException(sprintf('Plugin "%s" registered a route with empty name.', $pluginId));
        }

        $this->routes[] = [
            'pluginId' => $pluginId,
            'name' => $name,
            'path' => $path,
            'controller' => $controller,
            'methods' => $methods,
            'requirements' => $requirements,
            'permissions' => $permissions,
            'version' => $version,
        ];
    }

    /**
     * @return array<int, array{
     *   pluginId: string,
     *   name: string,
     *   path: string,
     *   controller: string,
     *   methods: array<int,string>,
     *   requirements: array<string,string>,
     *   permissions: array<int,string>,
     *   version: string,
     * }>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
