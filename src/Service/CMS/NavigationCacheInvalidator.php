<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS;

use App\Service\Cache\Core\CacheService;

/**
 * Invalidates navigation/search caches and rebuilds the search projection for pages.
 */
class NavigationCacheInvalidator
{
    public function __construct(
        private readonly CacheService $cache,
        private readonly NavigationSearchIndexService $navigationSearchIndexService,
    ) {
    }

    public function invalidateAll(): void
    {
        $this->cache
            ->withCategory(CacheService::CATEGORY_NAVIGATION)
            ->invalidateCategory();
        $this->cache
            ->withCategory(CacheService::CATEGORY_SEARCH)
            ->invalidateCategory();
    }

    public function invalidateForPage(int $pageId): void
    {
        $this->invalidateAll();
        $this->navigationSearchIndexService->rebuildForPage($pageId);
    }

    public function invalidateForPageDeletion(int $pageId): void
    {
        $this->invalidateAll();
        $this->navigationSearchIndexService->deleteForPage($pageId);
    }

    /**
     * @param list<int> $pageIds
     */
    public function invalidateForPageIds(array $pageIds): void
    {
        $this->invalidateAll();
        foreach (array_values(array_unique(array_filter($pageIds, static fn (int $id): bool => $id > 0))) as $pageId) {
            $this->navigationSearchIndexService->rebuildForPage($pageId);
        }
    }
}
