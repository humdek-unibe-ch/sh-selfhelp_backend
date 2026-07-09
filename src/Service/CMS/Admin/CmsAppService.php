<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Entity\CmsApp;
use App\Entity\Page;
use App\Exception\ServiceException;
use App\Repository\CmsAppRepository;
use App\Repository\PageRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\BaseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * CMS app product unit — empty shell CRUD + page assignment.
 *
 * Hub FKs are never written here directly; after every membership change this
 * service calls {@see CmsAppHubSyncService::sync()}.
 */
final class CmsAppService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CmsAppRepository $cmsAppRepository,
        private readonly PageRepository $pageRepository,
        private readonly CmsAppHubSyncService $hubSyncService,
        private readonly CacheService $cache,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listApps(): array
    {
        /** @var list<CmsApp> $apps */
        $apps = $this->cmsAppRepository->findBy([], ['name' => 'ASC']);
        $result = [];
        foreach ($apps as $app) {
            $result[] = $this->summarize($app);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getApp(int $id): array
    {
        return $this->detail($this->requireApp($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function getAppBySlug(string $slug): array
    {
        $app = $this->cmsAppRepository->findOneBySlug($slug);
        if (!$app instanceof CmsApp) {
            $this->throwNotFound(sprintf('CMS app with slug "%s" not found.', $slug));
        }

        return $this->detail($app);
    }

    /**
     * @return array<string, mixed>
     */
    public function createApp(string $name, string $slug, ?string $description = null): array
    {
        $slug = strtolower(trim($slug));
        $this->assertValidSlug($slug);
        if ($this->cmsAppRepository->findOneBySlug($slug) !== null) {
            $this->throwConflict(sprintf('A CMS app with slug "%s" already exists.', $slug));
        }

        $name = trim($name);
        if ($name === '') {
            $this->throwBadRequest('name is required.');
        }

        $app = new CmsApp();
        $app->setName($name);
        $app->setSlug($slug);
        $app->setDescription($description !== null && trim($description) !== '' ? trim($description) : null);

        $this->entityManager->persist($app);
        $this->entityManager->flush();

        return $this->detail($app);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateApp(int $id, array $input): array
    {
        $app = $this->requireApp($id);

        if (array_key_exists('name', $input)) {
            $name = trim($this->asString($input['name']));
            if ($name === '') {
                $this->throwBadRequest('name cannot be empty.');
            }
            $app->setName($name);
        }

        if (array_key_exists('slug', $input)) {
            $slug = strtolower(trim($this->asString($input['slug'])));
            $this->assertValidSlug($slug);
            $existing = $this->cmsAppRepository->findOneBySlug($slug);
            if ($existing !== null && $existing->getId() !== $app->getId()) {
                $this->throwConflict(sprintf('A CMS app with slug "%s" already exists.', $slug));
            }
            $app->setSlug($slug);
        }

        if (array_key_exists('description', $input)) {
            $description = $input['description'];
            if ($description === null) {
                $app->setDescription(null);
            } else {
                $trimmed = trim($this->asString($description));
                $app->setDescription($trimmed !== '' ? $trimmed : null);
            }
        }

        // Never trust client hub FKs — ignored if present.
        $app->touchUpdatedAt();
        $this->entityManager->flush();

        return $this->detail($app);
    }

    /**
     * Delete the app shell only: unassign pages, clear hubs, remove the row.
     * Pages, form sections, data tables and content records are retained.
     */
    public function deleteApp(int $id): void
    {
        $app = $this->requireApp($id);

        /** @var list<Page> $pages */
        $pages = $this->pageRepository->findBy(['cmsApp' => $app]);
        $affectedPageIds = [];
        foreach ($pages as $page) {
            if ($page->getId() !== null) {
                $affectedPageIds[] = (int) $page->getId();
            }
            $page->setCmsApp(null);
            $page->setCmsAppRole(null);
        }
        $this->entityManager->flush();

        $this->hubSyncService->clearHubs($app);
        $this->entityManager->remove($app);
        $this->entityManager->flush();
        $this->invalidatePageListCachesForMembershipChange($affectedPageIds);
    }

    /**
     * @return array<string, mixed>
     */
    public function assignPage(int $appId, int $pageId, string $role): array
    {
        $app = $this->requireApp($appId);
        $this->assertValidRole($role);
        $page = $this->requirePage($pageId);

        if ($page->getCmsApp() !== null && $page->getCmsApp()->getId() !== $app->getId()) {
            $this->throwConflict(sprintf(
                'Page "%s" is already assigned to CMS app "%s".',
                $page->getKeyword(),
                $page->getCmsApp()->getSlug()
            ));
        }

        $this->assertPrimaryRoleAvailable($app, $role, $page->getId());

        $page->setCmsApp($app);
        $page->setCmsAppRole($role);
        $this->entityManager->flush();
        $this->hubSyncService->sync($app);
        $this->invalidatePageListCachesForMembershipChange([(int) $page->getId()]);

        return $this->detail($app);
    }

    /**
     * @return array<string, mixed>
     */
    public function changePageRole(int $appId, int $pageId, string $role): array
    {
        $app = $this->requireApp($appId);
        $this->assertValidRole($role);
        $page = $this->requirePage($pageId);

        if ($page->getCmsApp()?->getId() !== $app->getId()) {
            $this->throwBadRequest(sprintf('Page %d is not assigned to CMS app %d.', $pageId, $appId));
        }

        $this->assertPrimaryRoleAvailable($app, $role, $page->getId());

        $page->setCmsAppRole($role);
        $this->entityManager->flush();
        $this->hubSyncService->sync($app);
        $this->invalidatePageListCachesForMembershipChange([(int) $page->getId()]);

        return $this->detail($app);
    }

    /**
     * @return array<string, mixed>
     */
    public function unassignPage(int $appId, int $pageId): array
    {
        $app = $this->requireApp($appId);
        $page = $this->requirePage($pageId);

        if ($page->getCmsApp()?->getId() !== $app->getId()) {
            $this->throwBadRequest(sprintf('Page %d is not assigned to CMS app %d.', $pageId, $appId));
        }

        $page->setCmsApp(null);
        $page->setCmsAppRole(null);
        $this->entityManager->flush();
        $this->hubSyncService->sync($app);
        $this->invalidatePageListCachesForMembershipChange([(int) $page->getId()]);

        return $this->detail($app);
    }

    /**
     * Called when a page is hard-deleted; refreshes hubs for its former app.
     */
    public function onPageDeleted(?CmsApp $formerApp): void
    {
        if ($formerApp instanceof CmsApp && $formerApp->getId() !== null) {
            // Re-fetch in case the entity was detached.
            $app = $this->cmsAppRepository->find($formerApp->getId());
            if ($app instanceof CmsApp) {
                $this->hubSyncService->sync($app);
            }
        }
    }

    public function requireApp(int $id): CmsApp
    {
        $app = $this->cmsAppRepository->find($id);
        if (!$app instanceof CmsApp) {
            $this->throwNotFound(sprintf('CMS app %d not found.', $id));
        }

        return $app;
    }

    private function requirePage(int $id): Page
    {
        $page = $this->pageRepository->find($id);
        if (!$page instanceof Page) {
            $this->throwNotFound(sprintf('Page %d not found.', $id));
        }

        return $page;
    }

    private function assertValidSlug(string $slug): void
    {
        if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            $this->throwBadRequest('slug is required and may only contain lowercase letters, numbers and hyphens.');
        }
    }

    private function assertValidRole(string $role): void
    {
        if (!CmsAppRole::isValid($role)) {
            $this->throwBadRequest(sprintf(
                'cms_app_role "%s" is invalid. Allowed: %s.',
                $role,
                implode(', ', CmsAppRole::ALL)
            ));
        }
    }

    private function assertPrimaryRoleAvailable(CmsApp $app, string $role, ?int $exceptPageId): void
    {
        if (!CmsAppRole::isPrimary($role)) {
            return;
        }

        /** @var list<Page> $pages */
        $pages = $this->pageRepository->findBy(['cmsApp' => $app, 'cmsAppRole' => $role]);
        foreach ($pages as $existing) {
            if ($exceptPageId !== null && $existing->getId() === $exceptPageId) {
                continue;
            }
            throw new ServiceException(
                sprintf('CMS app "%s" already has a page with role "%s".', $app->getSlug(), $role),
                Response::HTTP_CONFLICT
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(CmsApp $app): array
    {
        $pageCount = (int) $this->pageRepository->count(['cmsApp' => $app]);

        return [
            'id' => (int) $app->getId(),
            'name' => $app->getName(),
            'slug' => $app->getSlug(),
            'description' => $app->getDescription(),
            'page_count' => $pageCount,
            'id_form_section' => $app->getFormSection()?->getId(),
            'id_cms_list_page' => $app->getCmsListPage()?->getId(),
            'cms_list_keyword' => $app->getCmsListPage()?->getKeyword(),
            'cms_list_url' => $app->getCmsListPage()?->getUrl(),
            'id_cms_detail_page' => $app->getCmsDetailPage()?->getId(),
            'id_public_list_page' => $app->getPublicListPage()?->getId(),
            'public_list_keyword' => $app->getPublicListPage()?->getKeyword(),
            'public_list_url' => $app->getPublicListPage()?->getUrl(),
            'id_public_detail_page' => $app->getPublicDetailPage()?->getId(),
            'created_at' => $app->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $app->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(CmsApp $app): array
    {
        /** @var list<Page> $pages */
        $pages = $this->pageRepository->findBy(['cmsApp' => $app], ['keyword' => 'ASC']);
        $assigned = [];
        foreach ($pages as $page) {
            $assigned[] = [
                'page_id' => (int) $page->getId(),
                'keyword' => $page->getKeyword(),
                'url' => $page->getUrl(),
                'page_surface' => $page->getPageSurfaceCode(),
                'cms_app_role' => $page->getCmsAppRole(),
            ];
        }

        return array_merge($this->summarize($app), ['pages' => $assigned]);
    }

    /**
     * Admin page lists cache {@see Page::$cmsApp} / role columns; membership
     * writes must invalidate the same categories as {@see AdminPageService}.
     *
     * @param list<int> $pageIds
     */
    private function invalidatePageListCachesForMembershipChange(array $pageIds): void
    {
        foreach ($pageIds as $pageId) {
            if ($pageId <= 0) {
                continue;
            }
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId);
        }

        $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->invalidateAllListsInCategory();
        $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->invalidateAllListsInCategory();
    }
}
