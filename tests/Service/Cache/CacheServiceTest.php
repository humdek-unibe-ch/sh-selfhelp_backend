<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Cache;

use App\Service\Cache\Core\CacheService;
use App\Tests\Support\QaKernelTestCase;

/**
 * Behavioural coverage for {@see CacheService} (plan P1 "Cache and core
 * services"): compute-or-get for lists and items, list/item invalidation,
 * generation-based category invalidation, and entity-scope invalidation
 * (the mechanism every write path relies on to surgically drop dependent
 * cache).
 *
 * The cache pool is Redis-backed and escapes the DAMA transaction, so each
 * test uses a unique `qa_`-prefixed probe key (operational isolation, like
 * the existing CreateAdminUserCommandTest cache probe). The "compute"
 * callbacks return DIFFERENT values on each call, so a cache HIT is proven by
 * the stale value coming back and a MISS by the fresh value being recomputed.
 */
final class CacheServiceTest extends QaKernelTestCase
{
    private function cache(): CacheService
    {
        return $this->service(CacheService::class);
    }

    private function probe(string $suffix): string
    {
        return 'qa_cache_' . $suffix . '_' . bin2hex(random_bytes(8));
    }

    public function testGetListCachesThenRecomputesAfterListInvalidation(): void
    {
        $cache = $this->cache();
        $key = $this->probe('list');
        $list = static fn (string $value): mixed => $cache
            ->withCategory(CacheService::CATEGORY_DEFAULT)
            ->getList($key, static fn (): string => $value);

        self::assertSame('first', $list('first'), 'First call computes and stores.');
        self::assertSame('first', $list('second'), 'Second call is a HIT (stale value returned).');

        $cache->withCategory(CacheService::CATEGORY_DEFAULT)->invalidateAllListsInCategory();

        self::assertSame('third', $list('third'), 'After list invalidation the value is recomputed.');
    }

    public function testGetItemCachesThenRecomputesAfterItemInvalidation(): void
    {
        $cache = $this->cache();
        $key = $this->probe('item');
        $item = static fn (string $value): mixed => $cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->getItem($key, static fn (): string => $value);

        self::assertSame('one', $item('one'));
        self::assertSame('one', $item('two'), 'Item is cached (HIT).');

        $cache->withCategory(CacheService::CATEGORY_USERS)->invalidateItem($key);

        self::assertSame('three', $item('three'), 'After invalidateItem the item recomputes.');
    }

    public function testInvalidateCategoryBumpsGenerationAndDropsLists(): void
    {
        $cache = $this->cache();
        $key = $this->probe('cat');
        $list = static fn (string $value): mixed => $cache
            ->withCategory(CacheService::CATEGORY_ACTIONS)
            ->getList($key, static fn (): string => $value);

        self::assertSame('a', $list('a'));
        self::assertSame('a', $list('b'));

        $cache->withCategory(CacheService::CATEGORY_ACTIONS)->invalidateCategory();

        self::assertSame('c', $list('c'), 'Generation bump invalidates the whole category.');
    }

    public function testSetItemStoresValueDirectly(): void
    {
        $cache = $this->cache();
        $key = $this->probe('set');

        $cache->withCategory(CacheService::CATEGORY_DEFAULT)->setItem($key, 'stored');

        $got = $cache->withCategory(CacheService::CATEGORY_DEFAULT)
            ->getItem($key, static fn (): string => 'compute-should-not-run');

        self::assertSame('stored', $got);
    }

    public function testEntityScopeInvalidationOnlyAffectsTheMatchingEntity(): void
    {
        $cache = $this->cache();
        $key = $this->probe('scope');
        $entityId = 987654321; // fixed, far above any seeded user id
        $scoped = static fn (string $value): mixed => $cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $entityId)
            ->getItem($key, static fn (): string => $value);

        self::assertSame('x', $scoped('x'));
        self::assertSame('x', $scoped('y'), 'Scoped item is cached.');

        // Invalidating a DIFFERENT entity must not drop this scope.
        $cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $entityId + 1);
        self::assertSame('x', $scoped('z'), 'Other entity invalidation must not affect this scope.');

        // Invalidating the matching entity drops the scoped cache.
        $cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $entityId);
        self::assertSame('fresh', $scoped('fresh'), 'Matching entity invalidation recomputes.');
    }

    public function testWithEntityScopeRejectsUnsupportedType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cache()->withEntityScope('not_a_real_scope', 1);
    }

    public function testWithEntityScopeRejectsNonPositiveId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cache()->withEntityScope(CacheService::ENTITY_SCOPE_USER, 0);
    }
}
