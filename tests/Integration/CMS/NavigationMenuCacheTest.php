<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\NavigationMenuItem;
use App\Entity\Page;
use App\Repository\NavigationMenuItemRepository;
use App\Repository\PageRepository;
use App\Service\CMS\Admin\AdminNavigationService;
use App\Service\CMS\NavigationMenuService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('integration')]
final class NavigationMenuCacheTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_nav_cache_bust';

    protected function tearDown(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        $page = $pageRepo->findOneBy(['keyword' => self::KEYWORD]);
        if ($page instanceof Page) {
            $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
        }

        parent::tearDown();
    }

    public function testMenuReorderUpdatesPublicNavigationPayload(): void
    {
        $admin = $this->loginAsQaAdmin();

        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::KEYWORD,
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
        $item = $menuItems[0];
        self::assertInstanceOf(NavigationMenuItem::class, $item);
        $itemId = $item->getId();
        self::assertIsInt($itemId);
        $originalPosition = $item->getPosition();

        /** @var NavigationMenuService $navigationMenuService */
        $navigationMenuService = self::getContainer()->get(NavigationMenuService::class);
        $before = $navigationMenuService->getPublicNavigationPayload(LookupService::PAGE_ACCESS_TYPES_WEB, 1);
        $beforePosition = $this->findItemPositionInHeader($before, $itemId);

        /** @var AdminNavigationService $adminNavigation */
        $adminNavigation = self::getContainer()->get(AdminNavigationService::class);
        $newPosition = $originalPosition + 50;
        $adminNavigation->reorderMenuItems(LookupService::NAVIGATION_MENU_KEY_WEB_HEADER, [
            ['item_id' => $itemId, 'position' => $newPosition, 'parent_item_id' => null],
        ]);

        $after = $navigationMenuService->getPublicNavigationPayload(LookupService::PAGE_ACCESS_TYPES_WEB, 1);
        $afterPosition = $this->findItemPositionInHeader($after, $itemId);

        self::assertSame($originalPosition, $beforePosition);
        self::assertSame($newPosition, $afterPosition);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function findItemPositionInHeader(array $payload, int $itemId): ?int
    {
        $menus = $payload['menus'] ?? null;
        if (!is_array($menus)) {
            return null;
        }
        $header = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER] ?? null;
        if (!is_array($header)) {
            return null;
        }
        $items = $header['items'] ?? null;
        if (!is_array($items)) {
            return null;
        }

        return $this->findPositionInTree($items, $itemId);
    }

    /**
     * @param array<mixed> $items
     */
    private function findPositionInTree(array $items, int $itemId): ?int
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['id'] ?? null) === $itemId) {
                $position = $item['position'] ?? null;

                return is_int($position) ? $position : (is_numeric($position) ? (int) $position : null);
            }
            $children = $item['children'] ?? null;
            if (is_array($children) && $children !== []) {
                $nested = $this->findPositionInTree($children, $itemId);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }
}
