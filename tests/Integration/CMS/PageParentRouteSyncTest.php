<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\Page;
use App\Repository\NavigationMenuItemRepository;
use App\Repository\PageRepository;
use App\Repository\PageRouteRepository;
use App\Service\CMS\Admin\AdminNavigationService;
use App\Service\CMS\Admin\PageParentRouteSyncService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('integration')]
final class PageParentRouteSyncTest extends QaWebTestCase
{
    private const PARENT_KEYWORD = 'qa_nav_sync_parent';
    private const CHILD_KEYWORD = 'qa_nav_sync_child';

    protected function tearDown(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        foreach ([self::CHILD_KEYWORD, self::PARENT_KEYWORD, self::CHILD_KEYWORD . '_rm', self::PARENT_KEYWORD . '_rm', self::CHILD_KEYWORD . '_mv', self::PARENT_KEYWORD . '_mv'] as $keyword) {
            $page = $pageRepo->findOneBy(['keyword' => $keyword]);
            if ($page instanceof Page) {
                $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
            }
        }
        parent::tearDown();
    }

    public function testSyncUrlWithParentRewritesChildCanonicalRoute(): void
    {
        $admin = $this->loginAsQaAdmin();

        $parent = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::PARENT_KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::PARENT_KEYWORD,
        ], $admin);
        $parentData = $this->assertEnvelopeSuccess($parent, Response::HTTP_CREATED);
        $parentId = $parentData['id'] ?? null;
        self::assertIsInt($parentId);

        $child = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::CHILD_KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/legacy-child',
            'parent' => $parentId,
            'syncUrlWithParent' => true,
            'oldRoutePolicy' => 'keep_alias',
        ], $admin);
        $childData = $this->assertEnvelopeSuccess($child, Response::HTTP_CREATED);
        $childId = $childData['id'] ?? null;
        self::assertIsInt($childId);

        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        $childPage = $pageRepo->find($childId);
        self::assertInstanceOf(Page::class, $childPage);
        self::assertSame('/' . self::PARENT_KEYWORD . '/' . self::CHILD_KEYWORD, $childPage->getUrl());

        /** @var PageRouteRepository $routeRepo */
        $routeRepo = self::getContainer()->get(PageRouteRepository::class);
        $routes = $routeRepo->findByPageId($childId);
        $patterns = array_map(static fn ($route) => $route->getPathPattern(), $routes);
        self::assertContains('/' . self::PARENT_KEYWORD . '/' . self::CHILD_KEYWORD, $patterns);
        self::assertContains('/legacy-child', $patterns);
    }

    public function testMenuReorderDoesNotChangePageUrl(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);

        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::PARENT_KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::PARENT_KEYWORD,
            'navigationAssignments' => [
                ['menuKey' => LookupService::NAVIGATION_MENU_KEY_WEB_HEADER],
            ],
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        /** @var NavigationMenuItemRepository $itemRepo */
        $itemRepo = self::getContainer()->get(NavigationMenuItemRepository::class);
        $menuItems = $itemRepo->findBy(['page' => $pageId]);
        self::assertNotEmpty($menuItems);
        $itemId = $menuItems[0]->getId();
        self::assertIsInt($itemId);

        /** @var AdminNavigationService $navigationAdmin */
        $navigationAdmin = self::getContainer()->get(AdminNavigationService::class);
        $navigationAdmin->reorderMenuItems(LookupService::NAVIGATION_MENU_KEY_WEB_HEADER, [
            ['item_id' => $itemId, 'position' => 99, 'parent_item_id' => null],
        ]);

        $page = $pageRepo->find($pageId);
        self::assertInstanceOf(Page::class, $page);
        self::assertSame('/' . self::PARENT_KEYWORD, $page->getUrl());
    }

    public function testMenuItemParentMoveDoesNotChangePageUrl(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);

        $parentPage = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::PARENT_KEYWORD . '_mv',
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::PARENT_KEYWORD . '_mv',
            'navigationAssignments' => [
                ['menuKey' => LookupService::NAVIGATION_MENU_KEY_WEB_HEADER],
            ],
        ], $admin);
        $parentData = $this->assertEnvelopeSuccess($parentPage, Response::HTTP_CREATED);
        $parentPageId = $parentData['id'] ?? null;
        self::assertIsInt($parentPageId);

        $childPage = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::CHILD_KEYWORD . '_mv',
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::CHILD_KEYWORD . '_mv',
            'navigationAssignments' => [
                ['menuKey' => LookupService::NAVIGATION_MENU_KEY_WEB_HEADER],
            ],
        ], $admin);
        $childData = $this->assertEnvelopeSuccess($childPage, Response::HTTP_CREATED);
        $childPageId = $childData['id'] ?? null;
        self::assertIsInt($childPageId);

        /** @var NavigationMenuItemRepository $itemRepo */
        $itemRepo = self::getContainer()->get(NavigationMenuItemRepository::class);
        $parentItems = $itemRepo->findBy(['page' => $parentPageId]);
        $childItems = $itemRepo->findBy(['page' => $childPageId]);
        self::assertNotEmpty($parentItems);
        self::assertNotEmpty($childItems);
        $parentItemId = $parentItems[0]->getId();
        $childItemId = $childItems[0]->getId();
        self::assertIsInt($parentItemId);
        self::assertIsInt($childItemId);

        /** @var AdminNavigationService $navigationAdmin */
        $navigationAdmin = self::getContainer()->get(AdminNavigationService::class);
        $navigationAdmin->reorderMenuItems(LookupService::NAVIGATION_MENU_KEY_WEB_HEADER, [
            ['item_id' => $childItemId, 'position' => 10, 'parent_item_id' => $parentItemId],
            ['item_id' => $parentItemId, 'position' => 0, 'parent_item_id' => null],
        ]);

        $child = $pageRepo->find($childPageId);
        self::assertInstanceOf(Page::class, $child);
        self::assertSame('/' . self::CHILD_KEYWORD . '_mv', $child->getUrl());
    }

    public function testPageParentRouteSyncServiceSuggestChildUrl(): void
    {
        /** @var PageParentRouteSyncService $service */
        $service = self::getContainer()->get(PageParentRouteSyncService::class);
        $parent = (new Page())->setKeyword('parent')->setUrl('/parent');
        self::assertSame('/parent/child', $service->suggestChildUrl($parent, 'child'));
    }

    public function testSyncUrlWithParentRemoveOldRouteDropsLegacyAlias(): void
    {
        $admin = $this->loginAsQaAdmin();

        $parent = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::PARENT_KEYWORD . '_rm',
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::PARENT_KEYWORD . '_rm',
        ], $admin);
        $parentData = $this->assertEnvelopeSuccess($parent, Response::HTTP_CREATED);
        $parentId = $parentData['id'] ?? null;
        self::assertIsInt($parentId);

        $child = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::CHILD_KEYWORD . '_rm',
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/legacy-child-rm',
            'parent' => $parentId,
            'syncUrlWithParent' => true,
            'oldRoutePolicy' => 'remove_old_route',
        ], $admin);
        $childData = $this->assertEnvelopeSuccess($child, Response::HTTP_CREATED);
        $childId = $childData['id'] ?? null;
        self::assertIsInt($childId);

        /** @var PageRouteRepository $routeRepo */
        $routeRepo = self::getContainer()->get(PageRouteRepository::class);
        $routes = $routeRepo->findByPageId($childId);
        $patterns = array_map(static fn ($route) => $route->getPathPattern(), $routes);
        self::assertContains(
            '/' . self::PARENT_KEYWORD . '_rm/' . self::CHILD_KEYWORD . '_rm',
            $patterns,
        );
        self::assertNotContains('/legacy-child-rm', $patterns);
    }
}
