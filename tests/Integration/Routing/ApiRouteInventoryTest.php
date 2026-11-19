<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Routing;

use App\Repository\ApiRouteRepository;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\RouterInterface;

/**
 * Guardrail inventory for the database-backed `/cms-api` route system.
 *
 * Every row in `api_routes` is either declared public (`is_public`) with no
 * permission rows, or not public with at least one permission (F09). The old
 * PHP allowlist was moved into the seed migration; this test asserts the
 * invariant on live data.
 */
#[Group('security')]
final class ApiRouteInventoryTest extends QaKernelTestCase
{
    /** @var list<string> */
    private const VALID_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    public function testEveryDatabaseRouteResolvesToARealControllerMethod(): void
    {
        $router = $this->service(RouterInterface::class);
        $collection = $router->getRouteCollection();
        $rows = $this->service(ApiRouteRepository::class)->findAllRoutesWithPermissionsAsArray();

        self::assertGreaterThan(
            50,
            count($rows),
            'Expected the seeded api_routes table to contain the full core route set.'
        );

        $unregistered = [];
        $badController = [];
        $badMethods = [];

        foreach ($rows as $row) {
            $name = $this->routeName($row);
            $route = $collection->get($name);
            if ($route === null) {
                $unregistered[] = $name;
                continue;
            }

            $controller = $route->getDefault('_controller');
            if (!is_string($controller) || !$this->controllerResolves($controller)) {
                $badController[] = $name . ' => ' . var_export($controller, true);
            }

            $methods = $route->getMethods();
            if ($methods === [] || array_diff($methods, self::VALID_METHODS) !== []) {
                $badMethods[] = $name . ' => [' . implode(',', $methods) . ']';
            }
        }

        self::assertSame(
            [],
            $unregistered,
            "DB routes missing from the router collection:\n" . implode("\n", $unregistered)
        );
        self::assertSame(
            [],
            $badController,
            "Routes whose _controller does not resolve to a real Class::method:\n" . implode("\n", $badController)
        );
        self::assertSame(
            [],
            $badMethods,
            "Routes with missing/invalid HTTP methods:\n" . implode("\n", $badMethods)
        );
    }

    public function testEveryRouteIsPublicXorPermissionGuarded(): void
    {
        $rows = $this->service(ApiRouteRepository::class)->findAllRoutesWithPermissionsAsArray();

        $violations = [];
        foreach ($rows as $row) {
            $permissions = is_array($row['permission_names'] ?? null) ? $row['permission_names'] : [];
            $isPublic = (bool) ($row['is_public'] ?? false);
            $name = $this->routeName($row);

            if ($isPublic && $permissions !== []) {
                $violations[] = $name . ' is public and has permissions';
            }
            if (!$isPublic && $permissions === []) {
                $violations[] = $name . ' is neither public nor permission-guarded';
            }
        }

        self::assertSame(
            [],
            $violations,
            "api_routes must be public with no permissions, or not public with at least one permission:\n"
            . implode("\n", $violations)
        );
    }

    public function testStoredRouteNamesDoNotDuplicateTheVersionSuffix(): void
    {
        $rows = $this->service(ApiRouteRepository::class)->findAllRoutesWithPermissionsAsArray();

        $bad = [];
        foreach ($rows as $row) {
            $routeName = is_scalar($row['route_name'] ?? null) ? (string) $row['route_name'] : '';
            $version = is_scalar($row['version'] ?? null) ? (string) $row['version'] : '';
            if ($version !== '' && str_ends_with($routeName, '_' . $version)) {
                $bad[] = $routeName . ' / ' . $version;
            }
        }

        self::assertSame(
            [],
            $bad,
            "api_routes.route_name must not include the API version suffix; version lives in api_routes.version:\n" . implode("\n", $bad)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function routeName(array $row): string
    {
        $routeName = is_scalar($row['route_name'] ?? null) ? (string) $row['route_name'] : '';
        $version = is_scalar($row['version'] ?? null) ? (string) $row['version'] : '';

        $suffix = '_' . $version;
        if ($version !== '' && str_ends_with($routeName, $suffix)) {
            $routeName = substr($routeName, 0, -strlen($suffix));
        }

        return $routeName . $suffix;
    }

    private function controllerResolves(string $controller): bool
    {
        if (!str_contains($controller, '::')) {
            return class_exists($controller);
        }

        [$class, $method] = explode('::', $controller, 2);

        return class_exists($class) && method_exists($class, $method);
    }
}
