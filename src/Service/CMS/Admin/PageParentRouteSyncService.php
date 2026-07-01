<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS\Admin;

use App\Entity\Page;
use App\Repository\NavigationSettingsRepository;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;

/**
 * Derives child URLs from the page tree and applies explicit parent-sync saves.
 */
class PageParentRouteSyncService extends BaseService
{
    public function __construct(
        private readonly PageRouteService $pageRouteService,
        private readonly NavigationSettingsRepository $navigationSettingsRepository,
    ) {
    }

    public function suggestChildUrl(Page $parent, string $childKeyword): string
    {
        $parentUrl = trim((string) ($parent->getUrl() ?? ''));
        $slug = trim($childKeyword, '/');
        if ($parentUrl === '' || $parentUrl === '/') {
            return '/' . $slug;
        }

        return rtrim($parentUrl, '/') . '/' . $slug;
    }

    /**
     * When the admin opts in, rewrite the page URL + canonical route to follow
     * the selected page parent and apply the configured old-route policy.
     *
     * @return list<array<string, mixed>> The synced route set for API consumers.
     */
    public function syncPageUrlWithParent(Page $page, ?string $oldRoutePolicyCode = null): array
    {
        $parent = $page->getParentPage();
        if (!$parent instanceof Page) {
            $this->throwBadRequest('syncUrlWithParent requires a parent page');
        }

        $newUrl = $this->suggestChildUrl($parent, (string) $page->getKeyword());
        $oldUrl = $page->getUrl();
        $page->setUrl($newUrl);

        $policy = $oldRoutePolicyCode
            ?? $this->navigationSettingsRepository->getSingleton()?->getRouteSyncOldRoutePolicy()?->getLookupCode()
            ?? LookupService::NAVIGATION_ROUTE_SYNC_POLICY_ASK;

        $desired = [];
        $derived = PageRouteService::buildCanonicalRouteFromUrl($newUrl);
        if ($derived !== null) {
            $desired[] = $derived;
        }

        if (
            is_string($oldUrl)
            && $oldUrl !== ''
            && $oldUrl !== $newUrl
            && $policy === LookupService::NAVIGATION_ROUTE_SYNC_POLICY_KEEP_ALIAS
        ) {
            $alias = PageRouteService::buildCanonicalRouteFromUrl($oldUrl);
            if ($alias !== null) {
                $alias['is_canonical'] = false;
                $alias['priority'] = 10;
                $desired[] = $alias;
            }
        }

        $this->pageRouteService->syncRoutes((int) $page->getId(), $desired);

        return $this->pageRouteService->getRoutesForPage((int) $page->getId());
    }
}
