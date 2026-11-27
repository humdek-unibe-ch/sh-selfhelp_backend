<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Plugin\Plugin;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Service\Cache\Core\CacheService;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * C4 on the admin cache screen: the cache categories an enabled plugin declares are listed with
 * the core ones, can be cleared by name and are emptied by "clear all". The categories of a
 * plugin that is not enabled are neither listed nor accepted.
 */
final class AdminCachePluginCategoryTest extends QaWebTestCase
{
    private const STATS_URI = '/cms-api/v1/admin/cache/stats';
    private const CLEAR_CATEGORY_URI = '/cms-api/v1/admin/cache/clear/category';
    private const CLEAR_ALL_URI = '/cms-api/v1/admin/cache/clear/all';
    private const PROBE_KEY = 'qa_probe_admin_cache';

    private Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = QaItemPluginFixture::install(
            $this->service(EntityManagerInterface::class),
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
        );
    }

    protected function tearDown(): void
    {
        // The cache pool (Redis) outlives the database rollback.
        QaItemPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));

        parent::tearDown();
    }

    public function testThePluginCategoryIsListedWithTheCoreOnes(): void
    {
        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::STATS_URI, null, $this->loginAsQaAdmin()));

        $categories = $data['cache_categories'] ?? null;
        self::assertIsArray($categories);
        self::assertContains(QaItemPluginFixture::CACHE_CATEGORY, $categories);
        self::assertContains(CacheService::CATEGORY_PAGES, $categories, 'the core categories stay');

        $stats = $data['cache_stats'] ?? null;
        self::assertIsArray($stats);
        $perCategory = $stats['category_stats'] ?? null;
        self::assertIsArray($perCategory);
        self::assertArrayHasKey(QaItemPluginFixture::CACHE_CATEGORY, $perCategory);
    }

    public function testThePluginCategoryCanBeClearedByName(): void
    {
        $data = $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            self::CLEAR_CATEGORY_URI,
            ['category' => QaItemPluginFixture::CACHE_CATEGORY],
            $this->loginAsQaAdmin(),
        ));

        self::assertSame(QaItemPluginFixture::CACHE_CATEGORY, $data['category'] ?? null);
        self::assertTrue($data['cleared'] ?? null);
    }

    public function testClearAllEmptiesThePluginCategory(): void
    {
        $loads = 0;
        $this->readProbe($loads);
        $this->readProbe($loads);
        self::assertSame(1, $loads, 'the second read is served from the cache');

        $this->assertEnvelopeSuccess($this->jsonRequest('POST', self::CLEAR_ALL_URI, [], $this->loginAsQaAdmin()));

        $this->readProbe($loads);
        self::assertSame(2, $loads, 'clear all dropped what the plugin cached');
    }

    public function testTheCategoryOfADisabledPluginIsNotListedAndNotAccepted(): void
    {
        $this->plugin->setEnabled(false);
        $this->service(EntityManagerInterface::class)->flush();
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::STATS_URI, null, $this->loginAsQaAdmin()));
        self::assertNotContains(QaItemPluginFixture::CACHE_CATEGORY, is_array($data['cache_categories'] ?? null) ? $data['cache_categories'] : []);

        $this->assertEnvelope400($this->jsonRequest(
            'POST',
            self::CLEAR_CATEGORY_URI,
            ['category' => QaItemPluginFixture::CACHE_CATEGORY],
            $this->loginAsQaAdmin(),
        ));
    }

    private function readProbe(int &$loads): void
    {
        $this->service(CacheService::class)->withCategory(QaItemPluginFixture::CACHE_CATEGORY)->getItem(
            self::PROBE_KEY,
            static function () use (&$loads): string {
                $loads++;

                return 'definition';
            },
        );
    }
}
