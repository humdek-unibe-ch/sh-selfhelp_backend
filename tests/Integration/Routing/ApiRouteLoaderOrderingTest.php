<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Routing;

use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Coverage for {@see \App\Routing\ApiRouteLoader}'s static-before-dynamic
 * ordering guarantee (plan Phase 7: static-before-dynamic route ordering).
 *
 * The loader sorts DB routes so static paths are registered before dynamic
 * siblings; Symfony's matcher returns the first match in collection order, so
 * without the sort `/admin/plugins/{pluginId}` would shadow the static
 * `/admin/plugins/available`. We assert the real router resolves the static
 * sibling and that its collection index precedes the dynamic one.
 */
final class ApiRouteLoaderOrderingTest extends QaKernelTestCase
{
    private const STATIC_ROUTE = 'admin_plugins_available_v1';
    private const DYNAMIC_ROUTE = 'admin_plugins_get_v1';

    public function testStaticPluginPathResolvesAheadOfTheDynamicSibling(): void
    {
        $router = $this->service(RouterInterface::class);

        // Both `/admin/plugins/available` (static) and `/admin/plugins/{pluginId}`
        // (dynamic) match this path; the matcher returns the first registered,
        // which must be the static route.
        $match = $router->match('/cms-api/v1/admin/plugins/available');

        self::assertSame(
            self::STATIC_ROUTE,
            $match['_route'] ?? null,
            'The static /admin/plugins/available route must win over the dynamic {pluginId} route.',
        );
    }

    public function testStaticRouteIsRegisteredBeforeDynamicSibling(): void
    {
        $router = $this->service(RouterInterface::class);
        $names = array_keys($router->getRouteCollection()->all());

        $staticIndex = array_search(self::STATIC_ROUTE, $names, true);
        $dynamicIndex = array_search(self::DYNAMIC_ROUTE, $names, true);

        self::assertIsInt($staticIndex, 'Static plugin route must be present in the collection.');
        self::assertIsInt($dynamicIndex, 'Dynamic plugin route must be present in the collection.');
        self::assertLessThan(
            $dynamicIndex,
            $staticIndex,
            'Static route must be registered before its dynamic sibling.',
        );
    }
}
