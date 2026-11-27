<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Cache;

use App\Plugin\Cache\PluginCacheCategories;
use App\Plugin\Resource\RegisteredResourceTypes;
use App\Service\Cache\Core\CacheService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * C4: plugin items and plugin cache categories in the cache service.
 *
 *   - `withResourceScope($type, $id)` ties an entry to one item of a plugin
 *     resource type; `invalidateResourceScope()` drops the entries of that item
 *     and no others.
 *   - Only types an enabled plugin registers are accepted.
 *   - A category a plugin declares (`<plugin id>.<name>`) is valid for
 *     `withCategory()` and lives as long as the manifest says.
 *
 * DB-free: an in-memory pool, a mock clock and stubs of the two registries.
 */
final class CacheResourceScopeTest extends TestCase
{
    private const CATEGORY = 'qa-items-plugin.items';
    private const LIFETIME = 120;

    private MockClock $clock;
    private CacheService $cache;

    protected function setUp(): void
    {
        $types = $this->createStub(RegisteredResourceTypes::class);
        $types->method('isRegistered')->willReturnCallback(
            static fn (string $code): bool => in_array($code, ['qa_item', 'qa_other'], true)
        );

        $categories = $this->createStub(PluginCacheCategories::class);
        $categories->method('names')->willReturn([self::CATEGORY]);
        $categories->method('lifetimeOf')->willReturnCallback(
            static fn (string $category): ?int => $category === self::CATEGORY ? self::LIFETIME : null
        );

        // Starts at the real now: the cache item computes its end from the real clock when it is stored.
        $this->clock = new MockClock();
        $this->cache = new CacheService(
            new TagAwareAdapter(new ArrayAdapter(0, true, 0, 0, $this->clock)),
            null,
            $types,
            $categories,
        );
    }

    public function testAnEntryOfAnItemIsRebuiltAfterItsScopeIsInvalidatedAndTheNeighbourStaysWarm(): void
    {
        $loads = [5 => 0, 6 => 0];
        $read = function (int $id) use (&$loads): string {
            return $this->cache
                ->withCategory(self::CATEGORY)
                ->withResourceScope('qa_item', $id)
                ->getItem('definition', static function () use (&$loads, $id): string {
                    $loads[$id]++;

                    return sprintf('definition-%d-v%d', $id, $loads[$id]);
                });
        };

        self::assertSame('definition-5-v1', $read(5));
        self::assertSame('definition-6-v1', $read(6));
        self::assertSame('definition-5-v1', $read(5), 'a second read is a hit');

        $this->cache->invalidateResourceScope('qa_item', 5);

        self::assertSame('definition-5-v2', $read(5), 'the item that changed is rebuilt');
        self::assertSame('definition-6-v1', $read(6), 'the neighbour item stays warm');
        self::assertSame([5 => 2, 6 => 1], $loads);
    }

    public function testTwoTypesWithTheSameIdDoNotShareAScope(): void
    {
        $loads = ['qa_item' => 0, 'qa_other' => 0];
        $read = function (string $type) use (&$loads): void {
            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->withResourceScope($type, 9)
                ->getItem('definition', static function () use (&$loads, $type): string {
                    $loads[$type]++;

                    return $type;
                });
        };

        $read('qa_item');
        $read('qa_other');
        $this->cache->invalidateResourceScope('qa_item', 9);
        $read('qa_item');
        $read('qa_other');

        self::assertSame(['qa_item' => 2, 'qa_other' => 1], $loads);
    }

    public function testAnUnregisteredTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"qa_unknown" is not a resource type of an enabled plugin');

        $this->cache->withResourceScope('qa_unknown', 5);
    }

    public function testInvalidatingAnUnregisteredTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->cache->invalidateResourceScope('qa_unknown', 5);
    }

    public function testAnIdThatIsNotPositiveIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Resource id must be positive');

        $this->cache->invalidateResourceScope('qa_item', 0);
    }

    public function testAPluginCategoryLivesAsLongAsItsManifestSaysAndNoLonger(): void
    {
        $loads = 0;
        $read = function () use (&$loads): void {
            $this->cache->withCategory(self::CATEGORY)->getItem('definition', static function () use (&$loads): string {
                $loads++;

                return 'definition';
            });
        };

        $read();
        $this->clock->sleep(self::LIFETIME - 1);
        $read();
        self::assertSame(1, $loads, 'still cached just before the lifetime ends');

        $this->clock->sleep(2);
        $read();
        self::assertSame(2, $loads, 'rebuilt once the lifetime has passed');
    }

    public function testAPluginCategoryHasItsLifetimeAndACoreCategoryKeepsItsDefault(): void
    {
        self::assertSame(self::LIFETIME, $this->cache->getCategoryTTL(self::CATEGORY));
        self::assertNotSame(self::LIFETIME, $this->cache->getCategoryTTL(CacheService::CATEGORY_PAGES));
        self::assertSame(1800, $this->cache->getCategoryTTL(CacheService::CATEGORY_ROLES));
    }

    public function testPluginCategoriesAreListedAfterTheCoreOnes(): void
    {
        self::assertSame([self::CATEGORY], $this->cache->pluginCategories());

        $all = $this->cache->allCategories();
        self::assertSame([self::CATEGORY], array_slice($all, -1), 'a plugin category comes after the core ones');
        self::assertContains(CacheService::CATEGORY_PAGES, $all);
    }
}
