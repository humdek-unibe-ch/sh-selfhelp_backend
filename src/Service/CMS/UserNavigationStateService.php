<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS;

use App\Entity\Page;
use App\Entity\User;
use App\Entity\UserNavigationState;
use App\Repository\PageRepository;
use App\Repository\UserNavigationStateRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Frontend\PageService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists per-user last-visited page snapshots for start-page resume behaviour.
 */
class UserNavigationStateService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserNavigationStateRepository $userNavigationStateRepository,
        private readonly PageRepository $pageRepository,
        private readonly LookupService $lookupService,
        private readonly PageService $pageService,
        private readonly CacheService $cache,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function recordLastVisited(User $user, string $platformCode, array $payload): array
    {
        $pageId = $payload['page_id'] ?? $payload['pageId'] ?? null;
        if (!is_int($pageId) && !is_numeric($pageId)) {
            $this->throwBadRequest('page_id is required');
        }
        $page = $this->pageRepository->find((int) $pageId);
        if (!$page instanceof Page) {
            $this->throwNotFound('Page not found');
        }
        if ($page->isHeadless()) {
            $this->throwBadRequest('Headless pages cannot be stored as last visited');
        }

        $platform = $this->lookupService->findByTypeAndCode(LookupService::NAVIGATION_PLATFORMS, $platformCode);
        if ($platform === null) {
            $this->throwBadRequest('Invalid platform');
        }

        $mode = $platformCode === 'mobile'
            ? LookupService::PAGE_ACCESS_TYPES_MOBILE
            : LookupService::PAGE_ACCESS_TYPES_WEB;
        $accessible = $this->pageService->getAllAccessiblePagesForUser($mode, false);
        $accessibleIds = $this->collectIds($accessible);
        if (!isset($accessibleIds[(int) $page->getId()])) {
            $this->throwForbidden('Page is not accessible');
        }

        $userId = (int) $user->getId();
        $managedUser = $this->entityManager->find(User::class, $userId);
        if (!$managedUser instanceof User) {
            $this->throwNotFound('User not found');
        }
        $state = $this->userNavigationStateRepository->findForUserAndPlatform($managedUser, (int) $platform->getId())
            ?? (new UserNavigationState())->setUser($managedUser)->setPlatform($platform);

        $state->setPage($page);
        $url = $payload['url'] ?? $page->getUrl();
        $state->setUrlSnapshot(is_string($url) ? $url : null);
        $keyword = $payload['keyword'] ?? $page->getKeyword();
        $state->setKeywordSnapshot(is_string($keyword) ? $keyword : null);
        $state->touch();

        $em = $this->entityManager;
        $em->persist($state);
        $em->flush();

        $this->cache
            ->withCategory(CacheService::CATEGORY_NAVIGATION)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);

        return [
            'page_id' => (int) $page->getId(),
            'keyword' => $state->getKeywordSnapshot(),
            'url' => $state->getUrlSnapshot(),
            'platform' => $platformCode,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveLastVisitedForUser(User $user, string $platformCode, int $languageId): ?array
    {
        $platform = $this->lookupService->findByTypeAndCode(LookupService::NAVIGATION_PLATFORMS, $platformCode);
        if ($platform === null) {
            return null;
        }
        $state = $this->userNavigationStateRepository->findForUserAndPlatform($user, (int) $platform->getId());
        if (!$state instanceof UserNavigationState) {
            return null;
        }

        $mode = $platformCode === 'mobile'
            ? LookupService::PAGE_ACCESS_TYPES_MOBILE
            : LookupService::PAGE_ACCESS_TYPES_WEB;
        $accessible = $this->pageService->getAllAccessiblePagesForUser($mode, false, $languageId);
        $accessibleIds = $this->collectIds($accessible);
        $pageId = (int) ($state->getPage()?->getId() ?? 0);
        if ($pageId <= 0 || !isset($accessibleIds[$pageId])) {
            return null;
        }

        return [
            'page_id' => $pageId,
            'keyword' => $state->getKeywordSnapshot(),
            'url' => $state->getUrlSnapshot(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $tree
     *
     * @return array<int, true>
     */
    private function collectIds(array $tree): array
    {
        $ids = [];
        $walk = function (array $nodes) use (&$ids, &$walk): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $id = $node['id_pages'] ?? $node['id'] ?? null;
                if (is_numeric($id)) {
                    $ids[(int) $id] = true;
                }
                $children = $node['children'] ?? [];
                if (is_array($children)) {
                    $walk($children);
                }
            }
        };
        $walk($tree);

        return $ids;
    }
}
