<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Routing;

use App\Entity\Permission;
use App\Plugin\Event\ApiRouteRegistryEvent;
use App\Repository\ApiRouteRepository;
use App\Service\Cache\Core\CacheService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Custom route loader that loads routes from database.
 *
 * Plugins contribute additional API routes through
 * `App\Plugin\Event\ApiRouteRegistryEvent`. The event fires after the
 * DB-backed routes have been built so plugin routes appear last but
 * before the collection is cached. Plugin routes are version-prefixed
 * under `/cms-api/{version}/plugins/{pluginId}/...` (enforced by the
 * event itself).
 */
class ApiRouteLoader extends Loader
{
    protected bool $isLoaded = false;

    public function __construct(
        private ApiRouteRepository $apiRouteRepository,
        private CacheService $cache,
        protected ?string $env,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private string $cmsVersion = 'dev'
    ) {
        // The parent Loader doesn't need any arguments
        parent::__construct();

    }

    public function load(mixed $resource, string $type = null): RouteCollection
    {
        if ($this->isLoaded) {
            throw new \RuntimeException('Do not add the database routes loader twice');
        }

        // Use cache in production, skip in dev for easier development
        $cacheKey = 'api_routes_collection';
        $useCache = $this->env !== 'dev';

        if ($useCache) {
            $routes = $this->cache
                ->withCategory(CacheService::CATEGORY_API_ROUTES)
                ->getList($cacheKey, fn() => $this->buildRouteCollection());
        } else {
            $routes = $this->buildRouteCollection();
        }

        $this->isLoaded = true;

        return $routes;
    }

    /**
     * Build the route collection from database
     */
    private function buildRouteCollection(): RouteCollection
    {
        $routes = new RouteCollection();
        
        // Use optimized single-query method to get all routes with permissions
        $allRoutesData = $this->apiRouteRepository->findAllRoutesWithPermissionsAsArray();

        // Sort routes so static paths (no `{` placeholder) are added to
        // the collection BEFORE dynamic paths. Symfony's UrlMatcher tries
        // routes in collection order and the first match wins, so without
        // this sort a dynamic route registered earlier (e.g. baseline
        // `/admin/plugins/{pluginId}`) would shadow a later static
        // sibling (e.g. `/admin/plugins/updates` introduced in a
        // follow-up migration). We keep the existing version + id order
        // as the tie-breaker so the relative order within each bucket
        // remains stable and idempotent.
        usort($allRoutesData, static function ($a, $b): int {
            $aArr = is_array($a) ? $a : [];
            $bArr = is_array($b) ? $b : [];
            $aVersion = isset($aArr['version']) && is_string($aArr['version']) ? $aArr['version'] : '';
            $bVersion = isset($bArr['version']) && is_string($bArr['version']) ? $bArr['version'] : '';
            $versionCmp = strcmp($aVersion, $bVersion);
            if ($versionCmp !== 0) {
                return $versionCmp;
            }
            $aPath = isset($aArr['path']) && is_string($aArr['path']) ? $aArr['path'] : '';
            $bPath = isset($bArr['path']) && is_string($bArr['path']) ? $bArr['path'] : '';
            $aDynamic = str_contains($aPath, '{') ? 1 : 0;
            $bDynamic = str_contains($bPath, '{') ? 1 : 0;
            if ($aDynamic !== $bDynamic) {
                return $aDynamic - $bDynamic;
            }
            $aId = isset($aArr['id']) && is_int($aArr['id']) ? $aArr['id'] : 0;
            $bId = isset($bArr['id']) && is_int($bArr['id']) ? $bArr['id'] : 0;
            return $aId - $bId;
        });

        foreach ($allRoutesData as $routeData) {
            $version = $routeData['version'];
            
            // Always prepend version to the path
            $path = '/' . $version . $routeData['path'];
            
            // Map controller to versioned namespace
            $controller = $this->mapControllerToVersionedNamespace($routeData['controller'], $version);
            
            $defaults = [
                '_controller' => $controller,
                '_version' => $version,
            ];
            
            // Parse methods (GET, POST, etc.)
            $methods = explode(',', $routeData['methods']);

            // Requirements and params are already arrays from the optimized query
            $requirements = $routeData['requirements'] ?? [];
            $params = $routeData['params'] ?? [];

            // Attach params as a default for controller access
            $defaults['_params'] = $params;
            
            // Permission names are already parsed from the optimized query
            $permissionNames = $routeData['permission_names'] ?? [];
            
            // Create route options with permissions
            $options = [
                'permissions' => $permissionNames
            ];
            
            // Create the route with permissions in options
            $route = new Route(
                $path,                 // path
                $defaults,             // defaults
                $requirements,         // requirements
                $options,              // options (contains permissions)
                '',                    // host
                [],                    // schemes
                $methods               // methods
            );
            $routes->add($routeData['route_name'] . '_' . $version, $route);
        }

        $this->appendPluginRoutes($routes);

        return $routes;
    }

    /**
     * Dispatch the plugin route-registry event and merge contributed
     * routes into the collection. Routes are namespaced under
     * `/cms-api/{version}/plugins/{pluginId}/...`; the event enforces
     * the path/name conventions.
     */
    private function appendPluginRoutes(RouteCollection $routes): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }

        $event = new ApiRouteRegistryEvent($this->cmsVersion);
        $this->eventDispatcher->dispatch($event);

        foreach ($event->getRoutes() as $pluginRoute) {
            $version = $pluginRoute['version'] ?: 'v1';
            $path = '/' . $version . $pluginRoute['path'];
            $controller = $this->mapControllerToVersionedNamespace($pluginRoute['controller'], $version);

            $defaults = [
                '_controller' => $controller,
                '_version' => $version,
                '_plugin_id' => $pluginRoute['pluginId'],
                '_params' => [],
            ];

            $route = new Route(
                $path,
                $defaults,
                $pluginRoute['requirements'] ?? [],
                ['permissions' => $pluginRoute['permissions'] ?? []],
                '',
                [],
                $pluginRoute['methods']
            );

            $routes->add($pluginRoute['name'] . '_' . $version, $route);
        }
    }
    
    /**
     * Maps a controller from the database to the versioned namespace
     * 
     * @param string $controller The controller string from the database (e.g., App\Controller\AuthController::login)
     * @param string $version The API version (e.g., v1)
     * @return string The mapped controller string (e.g., App\Controller\Api\V1\Auth\AuthController::login)
     */
    private function mapControllerToVersionedNamespace(string $controller, string $version): string
    {
        // Skip if already using the versioned namespace
        if (str_contains($controller, '\\Controller\\Api\\')) {
            return $controller;
        }
        
        // Parse controller string (e.g., "App\Controller\AuthController::login")
        [$controllerClass, $method] = explode('::', $controller);
        
        // Extract controller name and domain
        $parts = explode('\\', $controllerClass);
        $controllerName = end($parts);
        
        // Determine domain from controller name
        $domain = str_replace('Controller', '', $controllerName);
        
        // Map to versioned namespace
        $versionedClass = sprintf('App\\Controller\\Api\\%s\\%s\\%sController', 
            ucfirst(strtolower($version)),
            ucfirst($domain),
            ucfirst($domain)
        );
        
        return $versionedClass . '::' . $method;
    }

    public function supports(mixed $resource, string $type = null): bool
    {
        return $type === 'api_database';
    }
}
