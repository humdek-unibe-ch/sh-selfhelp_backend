<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\ApiRoute;
use App\Entity\Permission;
use App\Entity\Plugin\Plugin;
use App\Plugin\Manifest\PluginManifest;
use App\Repository\ApiRouteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reconciles the `api_routes` table with the plugin's
 * `plugin.json#apiRoutes` declaration.
 *
 * Plugin HTTP routes are persisted as normal `api_routes` rows tagged
 * with `id_plugins`. `ApiRouteLoader` then loads them through the same
 * DB-backed pipeline used for core routes (see
 * {@see \App\Routing\ApiRouteLoader}). Disabled plugins are filtered
 * out at load time so disable stays instant and reversible without
 * destroying metadata.
 *
 * Lifecycle:
 *   - install / update → {@see sync()} upserts each declared route
 *     and removes plugin-owned rows the new manifest no longer
 *     declares.
 *   - uninstall → {@see removeAllForPlugin()} deletes every
 *     plugin-owned route up front (the matching controller classes
 *     are about to be removed by `composer remove`, so the rows must
 *     not survive as orphaned `id_plugins = NULL` entries that the
 *     loader would still try to dispatch).
 *   - purge → already covered by {@see PluginPurger::deletePluginTaggedRows()}
 *     so we don't duplicate the cleanup here.
 *
 * The synchronizer must run AFTER the plugin's Doctrine migrations
 * because plugin-declared permissions land in `permissions` through
 * those migrations and we look them up by name when wiring
 * `rel_api_routes_permissions`.
 */
final class PluginApiRouteSynchronizer
{
    /**
     * Allowed HTTP verbs on plugin-declared routes. Mirrors the enum
     * in `docs/plugins/plugin-manifest.schema.json` and the values
     * `ApiSecurityListener` knows how to enforce.
     *
     * @var list<string>
     */
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApiRouteRepository $apiRoutes,
    ) {
    }

    /**
     * Reconcile `api_routes` rows for the plugin with the manifest's
     * `apiRoutes` declaration. Existing rows tagged with the plugin
     * are updated in place; rows the new manifest no longer declares
     * are deleted.
     */
    public function sync(Plugin $plugin, PluginManifest $manifest): void
    {
        $declared = $this->normalize($plugin, $manifest);

        /** @var list<ApiRoute> $existing */
        $existing = $this->apiRoutes->findBy(['plugin' => $plugin]);
        $existingByKey = [];
        foreach ($existing as $route) {
            $existingByKey[$this->routeKey($route->getRouteName() ?? '', $route->getVersion() ?? 'v1')] = $route;
        }

        $declaredKeys = [];
        foreach ($declared as $decl) {
            $key = $this->routeKey($decl['name'], $decl['version']);
            $declaredKeys[$key] = true;

            $route = $existingByKey[$key] ?? new ApiRoute();
            $route->setPlugin($plugin);
            $route->setRouteName($decl['name']);
            $route->setPath($decl['path']);
            $route->setController($decl['controller']);
            $route->setMethods($decl['methods']);
            $route->setRequirements($decl['requirements']);
            $route->setParams($decl['params']);
            $route->setVersion($decl['version']);

            $route->getPermissions()->clear();
            foreach ($decl['permissions'] as $permission) {
                $route->addPermission($permission);
            }

            $this->em->persist($route);
        }

        foreach ($existingByKey as $key => $route) {
            if (!isset($declaredKeys[$key])) {
                $this->em->remove($route);
            }
        }

        $this->em->flush();
    }

    /**
     * Delete every `api_routes` row tagged with this plugin. Called
     * from `PluginUninstaller::finalize()` BEFORE the plugin row is
     * removed so the FK still resolves and the existing
     * `rel_api_routes_permissions` rows cascade away cleanly.
     */
    public function removeAllForPlugin(Plugin $plugin): int
    {
        /** @var list<ApiRoute> $existing */
        $existing = $this->apiRoutes->findBy(['plugin' => $plugin]);
        if ($existing === []) {
            return 0;
        }
        foreach ($existing as $route) {
            $this->em->remove($route);
        }
        $this->em->flush();
        return count($existing);
    }

    /**
     * Normalise every manifest entry into the shape the synchronizer
     * persists. All validation that would produce a useless DB row
     * (missing controller method, unknown permission, wrong path
     * prefix, ...) happens here so install / update fails before
     * touching the table.
     *
     * @return list<array{
     *     name: string,
     *     path: string,
     *     controller: string,
     *     methods: string,
     *     requirements: array<string,string>|null,
     *     params: array<int|string,mixed>|null,
     *     version: string,
     *     permissions: list<Permission>,
     * }>
     */
    private function normalize(Plugin $plugin, PluginManifest $manifest): array
    {
        $pluginId = $plugin->getPluginId();
        $publicPrefix = '/plugins/' . $pluginId . '/';
        $adminPrefix = '/admin/plugins/' . $pluginId . '/';

        $seen = [];
        $out = [];

        foreach ($manifest->getApiRoutes() as $index => $raw) {
            $name = isset($raw['name']) && is_string($raw['name']) ? trim($raw['name']) : '';
            $path = isset($raw['path']) && is_string($raw['path']) ? $raw['path'] : '';
            $controller = isset($raw['controller']) && is_string($raw['controller']) ? trim($raw['controller']) : '';
            $version = isset($raw['version']) && is_string($raw['version']) && $raw['version'] !== '' ? $raw['version'] : 'v1';

            if ($name === '') {
                throw new \InvalidArgumentException(sprintf('Plugin "%s" apiRoutes[%d] is missing a non-empty "name".', $pluginId, $index));
            }
            if ($controller === '' || !str_contains($controller, '::')) {
                throw new \InvalidArgumentException(sprintf('Plugin "%s" apiRoutes[%d] ("%s") must declare a "controller" in the form "Fully\\Qualified\\Class::method".', $pluginId, $index, $name));
            }
            if (!str_starts_with($path, $publicPrefix) && !str_starts_with($path, $adminPrefix)) {
                throw new \InvalidArgumentException(sprintf(
                    'Plugin "%s" apiRoutes[%d] ("%s") path must begin with "%s" or "%s", got "%s".',
                    $pluginId,
                    $index,
                    $name,
                    $publicPrefix,
                    $adminPrefix,
                    $path
                ));
            }

            $methods = $this->normaliseMethods($pluginId, $name, $raw['methods'] ?? []);

            [$controllerClass, $controllerMethod] = explode('::', $controller, 2);
            if (!class_exists($controllerClass)) {
                throw new \RuntimeException(sprintf(
                    'Plugin "%s" apiRoutes[%d] ("%s") references controller class "%s" that is not autoloadable.',
                    $pluginId,
                    $index,
                    $name,
                    $controllerClass
                ));
            }
            if (!method_exists($controllerClass, $controllerMethod)) {
                throw new \RuntimeException(sprintf(
                    'Plugin "%s" apiRoutes[%d] ("%s") references controller method "%s::%s" that does not exist.',
                    $pluginId,
                    $index,
                    $name,
                    $controllerClass,
                    $controllerMethod
                ));
            }

            $requirements = $this->normaliseAssocStringArray($raw['requirements'] ?? null);
            $params = isset($raw['params']) && is_array($raw['params']) ? $raw['params'] : null;
            $permissions = $this->resolvePermissions($pluginId, $name, $raw);

            $key = $this->routeKey($name, $version);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(sprintf(
                    'Plugin "%s" apiRoutes declares duplicate route name/version "%s/%s".',
                    $pluginId,
                    $name,
                    $version
                ));
            }
            $seen[$key] = true;

            $out[] = [
                'name' => $name,
                'path' => $path,
                'controller' => $controller,
                'methods' => implode(',', $methods),
                'requirements' => $requirements,
                'params' => $params,
                'version' => $version,
                'permissions' => $permissions,
            ];
        }

        return $out;
    }

    /**
     * @param mixed $methods
     * @return list<string>
     */
    private function normaliseMethods(string $pluginId, string $routeName, mixed $methods): array
    {
        if (!is_array($methods) || $methods === []) {
            throw new \InvalidArgumentException(sprintf(
                'Plugin "%s" apiRoutes "%s" must declare at least one HTTP method.',
                $pluginId,
                $routeName
            ));
        }
        $out = [];
        foreach ($methods as $method) {
            if (!is_string($method)) {
                continue;
            }
            $upper = strtoupper(trim($method));
            if (!in_array($upper, self::ALLOWED_METHODS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Plugin "%s" apiRoutes "%s" declares unsupported HTTP method "%s". Allowed: %s.',
                    $pluginId,
                    $routeName,
                    $method,
                    implode(', ', self::ALLOWED_METHODS)
                ));
            }
            if (!in_array($upper, $out, true)) {
                $out[] = $upper;
            }
        }
        if ($out === []) {
            throw new \InvalidArgumentException(sprintf(
                'Plugin "%s" apiRoutes "%s" must declare at least one HTTP method.',
                $pluginId,
                $routeName
            ));
        }
        return $out;
    }

    /**
     * Accept either a single `permission` string (manifest shorthand
     * for the common one-permission case) or a `permissions` array.
     * Both shapes resolve to `Permission` entities looked up by name;
     * unknown names abort the operation up-front so we never persist
     * an `api_routes` row whose `ApiSecurityListener` gate would be
     * effectively missing.
     *
     * @param array<string,mixed> $raw
     * @return list<Permission>
     */
    private function resolvePermissions(string $pluginId, string $routeName, array $raw): array
    {
        $names = [];
        if (isset($raw['permissions']) && is_array($raw['permissions'])) {
            foreach ($raw['permissions'] as $name) {
                if (is_string($name) && trim($name) !== '') {
                    $names[] = trim($name);
                }
            }
        }
        if (isset($raw['permission']) && is_string($raw['permission']) && trim($raw['permission']) !== '') {
            $names[] = trim($raw['permission']);
        }
        $names = array_values(array_unique($names));

        $out = [];
        $repo = $this->em->getRepository(Permission::class);
        foreach ($names as $name) {
            $permission = $repo->findOneBy(['name' => $name]);
            if (!$permission instanceof Permission) {
                throw new \RuntimeException(sprintf(
                    'Plugin "%s" apiRoutes "%s" references permission "%s" that is not registered. Declare it under plugin.json#permissions and ensure the plugin migration that inserts it has run.',
                    $pluginId,
                    $routeName,
                    $name
                ));
            }
            $out[] = $permission;
        }
        return $out;
    }

    /**
     * @return array<string,string>|null
     */
    private function normaliseAssocStringArray(mixed $value): ?array
    {
        if (!is_array($value) || $value === []) {
            return null;
        }
        $out = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (!is_string($entry)) {
                continue;
            }
            $out[$key] = $entry;
        }
        return $out === [] ? null : $out;
    }

    private function routeKey(string $name, string $version): string
    {
        return $name . '|' . $version;
    }
}
