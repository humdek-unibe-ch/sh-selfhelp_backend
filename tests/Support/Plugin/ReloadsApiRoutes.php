<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Plugin;

use App\Service\Cache\Core\CacheService;

/**
 * For a test of a route that a migration added to `api_routes`.
 *
 * The route table and the route permissions are read from the database once and then kept in the
 * cache pools. Those pools are Redis, and Redis is not per `TEST_TOKEN` (F92): a run on another test
 * database may have cached its own table, which does not have the new route yet, and the request
 * answers 404. Call {@see reloadApiRoutes()} in `setUp()` (after `parent::setUp()`) so the next
 * request builds the table from this test's database. A run on a fresh machine (CI) has nothing
 * cached, and the call is then a no-op.
 *
 * Only for classes that extend {@see \App\Tests\Support\QaWebTestCase} or
 * {@see \App\Tests\Support\QaKernelTestCase}.
 */
trait ReloadsApiRoutes
{
    protected function reloadApiRoutes(): void
    {
        $cache = self::getContainer()->get(CacheService::class);
        self::assertInstanceOf(CacheService::class, $cache);

        $cache->withCategory(CacheService::CATEGORY_PERMISSIONS)->invalidateCategory();
        $cache->withCategory(CacheService::CATEGORY_API_ROUTES)->invalidateAllListsInCategory();
    }
}
