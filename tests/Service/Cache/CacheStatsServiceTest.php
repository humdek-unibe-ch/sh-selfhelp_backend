<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Cache;

use App\Service\Cache\Core\CacheService;
use App\Service\Cache\Core\CacheStatsService;
use App\Tests\Support\QaKernelTestCase;

/**
 * Behavioural coverage for {@see CacheStatsService} (plan Phase 8: cache stats
 * reset/health). Cache stats live in the (shared) Redis adapter, so the reset
 * assertion reads the freshly-reset category immediately to stay deterministic.
 */
final class CacheStatsServiceTest extends QaKernelTestCase
{
    private CacheStatsService $stats;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stats = $this->service(CacheStatsService::class);
    }

    public function testCategoryStatisticsExposeTheExpectedShape(): void
    {
        $stats = $this->stats->getCategoryStatistics(CacheService::CATEGORY_PAGES);

        foreach (['category', 'hits', 'misses', 'sets', 'hit_rate', 'total_operations'] as $key) {
            self::assertArrayHasKey($key, $stats, "Category stats must expose '{$key}'.");
        }
        self::assertSame(CacheService::CATEGORY_PAGES, $stats['category']);
    }

    public function testInvalidCategoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->stats->getCategoryStatistics('qa_not_a_real_category');
    }

    public function testResetStatsZeroesCounters(): void
    {
        $this->stats->resetStats();

        $after = $this->stats->getCategoryStatistics(CacheService::CATEGORY_PAGES);
        self::assertSame(0, $after['total_operations'], 'A category must report zero operations right after a reset.');
    }

    public function testCacheHealthReportsAStatus(): void
    {
        $health = $this->stats->getCacheHealth();

        self::assertArrayHasKey('status', $health);
        self::assertContains($health['status'], ['excellent', 'good', 'fair', 'poor']);
        self::assertArrayHasKey('hit_rate', $health);
        self::assertArrayHasKey('recommendations', $health);
        self::assertIsArray($health['recommendations']);
    }
}
