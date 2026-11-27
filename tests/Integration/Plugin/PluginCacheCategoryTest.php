<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\Entity\Plugin\Plugin;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Service\Cache\Core\CacheService;
use App\Service\Cache\Core\CacheStatsService;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\QaKernelTestCase;

/**
 * C4: a plugin declares cache categories in its manifest (`cacheCategories`).
 * Enabled plugins' categories are named `<plugin id>.<name>`, appear in the
 * cache statistics next to the core ones, and are cleared when the plugin is
 * uninstalled or purged.
 */
final class PluginCacheCategoryTest extends QaKernelTestCase
{
    private CacheService $cache;
    private CacheStatsService $stats;
    private PluginCacheInvalidator $invalidator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = $this->service(CacheService::class);
        $this->stats = $this->service(CacheStatsService::class);
        $this->invalidator = $this->service(PluginCacheInvalidator::class);
    }

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback.
        QaItemPluginFixture::forgetCaches($this->cache, $this->invalidator);
        parent::tearDown();
    }

    public function testTheCategoryOfAnEnabledPluginIsInTheStatistics(): void
    {
        self::assertNotContains(QaItemPluginFixture::CACHE_CATEGORY, $this->stats->allCategories(), 'nothing before the plugin is installed');

        $this->installQaPlugin();

        self::assertContains(QaItemPluginFixture::CACHE_CATEGORY, $this->stats->allCategories());
        self::assertContains(CacheService::CATEGORY_PAGES, $this->stats->allCategories(), 'the core categories stay');

        $all = $this->stats->getStats();
        self::assertContains(QaItemPluginFixture::CACHE_CATEGORY, $this->asList($all['cache_categories'] ?? null));
        $categoryStats = $this->asArray($this->asArray($all['cache_stats'] ?? null)['category_stats'] ?? null);
        self::assertArrayHasKey(QaItemPluginFixture::CACHE_CATEGORY, $categoryStats);
        self::assertSame(QaItemPluginFixture::CACHE_CATEGORY, $this->stats->getCategoryStatistics(QaItemPluginFixture::CACHE_CATEGORY)['category']);
    }

    public function testTheCategoryHasTheLifetimeTheManifestGives(): void
    {
        $this->installQaPlugin();

        self::assertSame(QaItemPluginFixture::CACHE_LIFETIME_SECONDS, $this->cache->getCategoryTTL(QaItemPluginFixture::CACHE_CATEGORY));
    }

    public function testTheCategoryOfADisabledPluginIsNotListed(): void
    {
        $plugin = $this->installQaPlugin();
        $plugin->setEnabled(false);
        $this->em->flush();
        $this->invalidator->invalidatePluginSurfaceCaches();

        self::assertNotContains(QaItemPluginFixture::CACHE_CATEGORY, $this->stats->allCategories());
    }

    public function testUninstallAndPurgeClearTheCategoriesOfThePlugin(): void
    {
        $this->installQaPlugin();
        $loads = 0;
        $read = function () use (&$loads): void {
            $this->cache->withCategory(QaItemPluginFixture::CACHE_CATEGORY)->getItem(
                'qa_probe_clear',
                static function () use (&$loads): string {
                    $loads++;

                    return 'definition';
                },
            );
        };

        $read();
        $read();
        self::assertSame(1, $loads, 'the second read is a hit');

        // What the uninstaller and the purger call, with the manifest they still hold.
        $cleared = $this->invalidator->clearPluginCategories(QaItemPluginFixture::PLUGIN_ID, QaItemPluginFixture::manifestData());

        self::assertSame(1, $cleared);
        $read();
        self::assertSame(2, $loads, 'the entry was dropped');
    }

    public function testClearingEverythingAlsoClearsThePluginCategories(): void
    {
        $this->installQaPlugin();
        $loads = 0;
        $read = function () use (&$loads): void {
            $this->cache->withCategory(QaItemPluginFixture::CACHE_CATEGORY)->getItem(
                'qa_probe_all',
                static function () use (&$loads): string {
                    $loads++;

                    return 'definition';
                },
            );
        };

        $read();
        $this->invalidator->invalidateAllCaches();
        $read();

        self::assertSame(2, $loads);
    }

    private function installQaPlugin(): Plugin
    {
        return QaItemPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->invalidator,
        );
    }
}
