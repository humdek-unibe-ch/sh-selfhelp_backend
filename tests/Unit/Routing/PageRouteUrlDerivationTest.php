<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Routing;

use App\Service\CMS\Admin\PageRouteService;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the create-page auto-route derivation
 * ({@see PageRouteService::buildCanonicalRouteFromUrl()}).
 *
 * Behaviour under test (the fix for "new pages have no active route"): the
 * create-page modal generates a URL pattern, and the backend must turn that URL
 * into a canonical, active `page_route` so the page is reachable immediately. The
 * derivation also has to accept the legacy AltoRouter token syntax that older
 * URLs / the old modal produced and convert it to Symfony `{param}` placeholders.
 */
final class PageRouteUrlDerivationTest extends TestCase
{
    public function testStaticUrlBecomesCanonicalActiveRouteWithNoRequirements(): void
    {
        $route = PageRouteService::buildCanonicalRouteFromUrl('/about-us');

        self::assertNotNull($route);
        self::assertSame('/about-us', $route['path_pattern']);
        self::assertNull($route['requirements']);
        self::assertTrue($route['is_canonical']);
        self::assertTrue($route['is_active']);
        self::assertSame(0, $route['priority']);
    }

    public function testEmptyOrWhitespaceUrlYieldsNoRoute(): void
    {
        self::assertNull(PageRouteService::buildCanonicalRouteFromUrl(''));
        self::assertNull(PageRouteService::buildCanonicalRouteFromUrl('   '));
    }

    public function testLegacyIntegerTokenConvertsToSymfonyParamWithNumericRequirement(): void
    {
        // The old "Navigation Page" checkbox produced `[i:nav]`.
        $route = PageRouteService::buildCanonicalRouteFromUrl('/menu/[i:nav]');

        self::assertNotNull($route);
        self::assertSame('/menu/{nav}', $route['path_pattern']);
        self::assertSame(['nav' => '\\d+'], $route['requirements']);
    }

    public function testLegacyAlphaAndHexTokensConvertWithTheirRequirements(): void
    {
        $route = PageRouteService::buildCanonicalRouteFromUrl('/p/[a:slug]/[h:hash]');

        self::assertNotNull($route);
        self::assertSame('/p/{slug}/{hash}', $route['path_pattern']);
        self::assertSame(['slug' => '[0-9A-Za-z]+', 'hash' => '[0-9A-Fa-f]+'], $route['requirements']);
    }

    public function testWildcardTokenConvertsToDotPlusRequirement(): void
    {
        $route = PageRouteService::buildCanonicalRouteFromUrl('/docs/[*:path]');

        self::assertNotNull($route);
        self::assertSame('/docs/{path}', $route['path_pattern']);
        self::assertSame(['path' => '.+'], $route['requirements']);
    }

    public function testSymfonyStyleIdParamGetsNumericRequirementByConvention(): void
    {
        // Already-Symfony URL (e.g. wizard / hand-edited): a `*_id` param is
        // numeric by convention so it does not shadow a sibling static route.
        $route = PageRouteService::buildCanonicalRouteFromUrl('/team/{record_id}');

        self::assertNotNull($route);
        self::assertSame('/team/{record_id}', $route['path_pattern']);
        self::assertSame(['record_id' => '\\d+'], $route['requirements']);
    }

    public function testNonIdSymfonyParamKeepsDefaultMatcher(): void
    {
        // A non-id named param keeps Symfony's default `[^/]+` matcher (no
        // explicit requirement emitted).
        $route = PageRouteService::buildCanonicalRouteFromUrl('/blog/{slug}');

        self::assertNotNull($route);
        self::assertSame('/blog/{slug}', $route['path_pattern']);
        self::assertNull($route['requirements']);
    }
}
