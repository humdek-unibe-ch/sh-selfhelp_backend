<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Routing;

use App\Repository\PageRouteRepository;
use App\Routing\PageRouteResolverService;
use App\Service\Cache\Core\CacheService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * Unit coverage for the DB-driven public-route resolver (issue #30).
 *
 * Asserts the observable resolve contract over a fixed active-route set:
 * static-before-dynamic ordering (a static `/reset` is never shadowed by
 * `/reset/{user_id}/{token}` or a catch-all `/{slug}`), full-path-only matches
 * (extra segments fall through), per-route `requirements` enforcement, the
 * extracted `route_params` + `canonical_url`, the cache hit/invalidate cycle,
 * and the defensive failure when two active rows ever share one pattern.
 */
final class PageRouteResolverServiceTest extends TestCase
{
    public function testStaticRouteIsNotShadowedByParameterizedSibling(): void
    {
        $resolver = $this->devResolver($this->seedRoutes());

        $resolved = $resolver->resolve('/reset');

        self::assertNotNull($resolved);
        self::assertSame('reset', $resolved['keyword']);
        self::assertSame(10, $resolved['page_id']);
        self::assertSame([], $resolved['route_params']);
        self::assertSame('/reset', $resolved['matched_pattern']);
        self::assertTrue($resolved['is_canonical']);
        self::assertSame('/reset', $resolved['canonical_url']);
    }

    public function testParameterizedRouteExtractsSnakeCaseParams(): void
    {
        $resolver = $this->devResolver($this->seedRoutes());

        // The token charset allows `. - ~ _`; the default `[^/]+` segment match
        // accepts them, and the params surface under their snake_case names.
        $resolved = $resolver->resolve('/reset/42/abc.def-~_');

        self::assertNotNull($resolved);
        self::assertSame('reset', $resolved['keyword']);
        self::assertSame(['user_id' => '42', 'token' => 'abc.def-~_'], $resolved['route_params']);
        self::assertSame('/reset/{user_id}/{token}', $resolved['matched_pattern']);
        self::assertFalse($resolved['is_canonical']);
        // The canonical URL still points at the page's canonical `/reset` route.
        self::assertSame('/reset', $resolved['canonical_url']);
    }

    public function testRequirementRegexIsEnforced(): void
    {
        $resolver = $this->devResolver($this->seedRoutes());

        $ok = $resolver->resolve('/team/5');
        self::assertNotNull($ok);
        self::assertSame('team-detail', $ok['keyword']);
        self::assertSame(['record_id' => '5'], $ok['route_params']);
        self::assertSame(['record_id' => '\\d+'], $ok['route_requirements']);

        // `record_id` requires `\d+`, so a non-numeric segment does not match.
        self::assertNull($resolver->resolve('/team/abc'));
    }

    public function testStaticWinsOverCatchAllAtSameDepth(): void
    {
        $resolver = $this->devResolver($this->seedRoutes());

        $about = $resolver->resolve('/about');
        self::assertNotNull($about);
        self::assertSame('about', $about['keyword']);

        $slug = $resolver->resolve('/something-else');
        self::assertNotNull($slug);
        self::assertSame('page', $slug['keyword']);
        self::assertSame(['slug' => 'something-else'], $slug['route_params']);
    }

    public function testRootPathResolvesToHome(): void
    {
        $resolver = $this->devResolver($this->seedRoutes());

        $home = $resolver->resolve('/');
        self::assertNotNull($home);
        self::assertSame('home', $home['keyword']);
    }

    public function testExtraSegmentsAndUnknownPathsReturnNull(): void
    {
        $resolver = $this->devResolver($this->seedRoutes());

        self::assertNull($resolver->resolve('/reset/1/tok/extra'));
        self::assertNull($resolver->resolve('/validate/onlyone'));
    }

    public function testGetCanonicalUrlForPage(): void
    {
        $resolver = $this->devResolver($this->seedRoutes());

        self::assertSame('/reset', $resolver->getCanonicalUrlForPage(10));
        self::assertNull($resolver->getCanonicalUrlForPage(999));
    }

    public function testRowsAreCachedAndInvalidationReloads(): void
    {
        $calls = 0;
        $repo = $this->createStub(PageRouteRepository::class);
        $repo->method('findActiveRoutesForResolver')->willReturnCallback(function () use (&$calls): array {
            $calls++;

            return $this->seedRoutes();
        });

        // Prod env (env !== 'dev') uses the cache.
        $resolver = new PageRouteResolverService($repo, $this->cache(), 'prod');

        $resolver->resolve('/reset');
        $resolver->resolve('/team/5');
        self::assertSame(1, $calls, 'Active routes are read once and served from cache.');

        $resolver->invalidate();
        $resolver->resolve('/reset');
        self::assertSame(2, $calls, 'Invalidation forces a reload on the next resolve.');
    }

    public function testDuplicateActivePatternFailsLoudly(): void
    {
        $rows = [
            $this->route(1, 20, 'dup-a', '/dup', [], true, 0),
            $this->route(2, 21, 'dup-b', '/dup', [], true, 0),
        ];
        $resolver = $this->devResolver($rows);

        $this->expectException(\RuntimeException::class);
        $resolver->resolve('/dup');
    }

    public function testAmbiguousSameShapeActivePatternsFailLoudly(): void
    {
        // Two active routes with the same path SHAPE (`/team/{*}`) but different
        // placeholder names could both match `/team/5`; the resolver must refuse
        // to build a non-deterministic collection rather than silently pick one.
        $rows = [
            $this->route(1, 20, 'team-by-id', '/team/{record_id}', [], true, 0),
            $this->route(2, 21, 'team-by-slug', '/team/{slug}', [], true, 0),
        ];
        $resolver = $this->devResolver($rows);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/shape/i');
        $resolver->resolve('/team/5');
    }

    /**
     * Build a resolver in dev mode, which bypasses the cache and reads the
     * mocked repository on every resolve (simplest for behavior assertions).
     *
     * @param list<array{id:int, page_id:int, keyword:string, path_pattern:string, requirements:array<string,string>, is_canonical:bool, priority:int}> $rows
     */
    private function devResolver(array $rows): PageRouteResolverService
    {
        $repo = $this->createStub(PageRouteRepository::class);
        $repo->method('findActiveRoutesForResolver')->willReturn($rows);

        return new PageRouteResolverService($repo, $this->cache(), 'dev');
    }

    private function cache(): CacheService
    {
        return new CacheService(new TagAwareAdapter(new ArrayAdapter()));
    }

    /**
     * @return list<array{id:int, page_id:int, keyword:string, path_pattern:string, requirements:array<string,string>, is_canonical:bool, priority:int}>
     */
    private function seedRoutes(): array
    {
        return [
            $this->route(1, 10, 'reset', '/reset', [], true, 0),
            $this->route(2, 10, 'reset', '/reset/{user_id}/{token}', [], false, 0),
            $this->route(3, 11, 'validate', '/validate/{user_id}/{token}', [], true, 0),
            $this->route(4, 12, 'team-detail', '/team/{record_id}', ['record_id' => '\\d+'], true, 0),
            $this->route(5, 1, 'home', '/', [], true, 0),
            $this->route(6, 13, 'about', '/about', [], true, 0),
            $this->route(7, 14, 'page', '/{slug}', [], true, 0),
        ];
    }

    /**
     * @param array<string,string> $requirements
     * @return array{id:int, page_id:int, keyword:string, path_pattern:string, requirements:array<string,string>, is_canonical:bool, priority:int}
     */
    private function route(int $id, int $pageId, string $keyword, string $pattern, array $requirements, bool $canonical, int $priority): array
    {
        return [
            'id' => $id,
            'page_id' => $pageId,
            'keyword' => $keyword,
            'path_pattern' => $pattern,
            'requirements' => $requirements,
            'is_canonical' => $canonical,
            'priority' => $priority,
        ];
    }
}
