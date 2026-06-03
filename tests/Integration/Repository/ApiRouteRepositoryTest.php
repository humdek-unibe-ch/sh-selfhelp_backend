<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\ApiRoute;
use App\Entity\Permission;
use App\Repository\ApiRouteRepository;
use App\Tests\Support\QaKernelTestCase;

/**
 * Integration coverage for {@see ApiRouteRepository} — the DB-backed route
 * catalogue {@see \App\Routing\ApiRouteLoader} consumes (plan Phase 9:
 * repository integration tests). Asserts version filtering, the array shape the
 * loader relies on, and route->permission resolution against the seeded routes.
 */
final class ApiRouteRepositoryTest extends QaKernelTestCase
{
    private ApiRouteRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->service(ApiRouteRepository::class);
    }

    public function testFindAllVersionsIncludesV1(): void
    {
        self::assertContains('v1', $this->repository->findAllVersions());
    }

    public function testRoutesByVersionAreAllThatVersion(): void
    {
        $routes = $this->repository->findAllRoutesByVersion('v1');

        self::assertNotEmpty($routes, 'The baseline must seed v1 routes.');
        foreach ($routes as $route) {
            self::assertInstanceOf(ApiRoute::class, $route);
            self::assertSame('v1', $route->getVersion());
        }
    }

    public function testRoutesWithPermissionsArrayHasLoaderShape(): void
    {
        $rows = $this->repository->findAllRoutesWithPermissionsAsArray();

        self::assertNotEmpty($rows);
        $first = $rows[0];
        foreach (['id', 'route_name', 'path', 'version', 'permission_names'] as $key) {
            self::assertArrayHasKey($key, $first, "Route row must expose '{$key}' for the loader.");
        }
        self::assertIsArray($first['permission_names'], 'permission_names must be parsed into a list.');

        // The permission-less health probe is seeded; it must surface here.
        $routeNames = array_column($rows, 'route_name');
        self::assertContains('health', $routeNames, 'The seeded health route must be present.');
    }

    public function testFindPermissionsForRouteResolvesAndIsEmptyForUnknown(): void
    {
        $routeWithPermission = null;
        foreach ($this->repository->findAllRoutesByVersion('v1') as $route) {
            if (!$route->getPermissions()->isEmpty()) {
                $routeWithPermission = $route;
                break;
            }
        }
        self::assertInstanceOf(ApiRoute::class, $routeWithPermission, 'At least one v1 route must require a permission.');

        $permissions = $this->repository->findPermissionsForRoute((int) $routeWithPermission->getId());
        self::assertNotEmpty($permissions);

        self::assertSame([], $this->repository->findPermissionsForRoute(999999999), 'Unknown route id must yield no permissions.');
    }
}
