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
use App\Service\CMS\NavigationAssignmentService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('integration')]
final class NavigationAssignmentCreatePageTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_nav_assign_page';

    protected function tearDown(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        foreach ([self::KEYWORD, self::KEYWORD . '_parent'] as $keyword) {
            $page = $pageRepo->findOneBy(['keyword' => $keyword]);
            if ($page instanceof Page) {
                $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
            }
        }

        parent::tearDown();
    }

    public function testCreatePageWithNavigationAssignmentsCreatesMenuItems(): void
    {
        $admin = $this->loginAsQaAdmin();

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::KEYWORD,
            'navigationAssignments' => [
                ['menuKey' => LookupService::NAVIGATION_MENU_KEY_WEB_HEADER],
                ['menuKey' => LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER, 'childSource' => 'manual'],
            ],
        ], $admin);

        $data = $this->assertEnvelopeSuccess($envelope, Response::HTTP_CREATED);
        self::assertSame(self::KEYWORD, $data['keyword'] ?? null);
        $pageId = $data['id'] ?? null;
        self::assertIsInt($pageId);
        self::assertGreaterThan(0, $pageId);

        /** @var NavigationMenuItemRepository $itemRepo */
        $itemRepo = self::getContainer()->get(NavigationMenuItemRepository::class);
        $items = $itemRepo->findBy(['page' => $pageId, 'isActive' => true]);
        self::assertGreaterThanOrEqual(2, count($items));

        $menuKeys = [];
        foreach ($items as $item) {
            self::assertInstanceOf(NavigationMenuItem::class, $item);
            $code = $item->getNavigationMenu()?->getMenuKey()?->getLookupCode();
            if (is_string($code)) {
                $menuKeys[] = $code;
            }
        }

        self::assertContains(LookupService::NAVIGATION_MENU_KEY_WEB_HEADER, $menuKeys);
        self::assertContains(LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER, $menuKeys);
    }

    public function testChildPageGetsAutoMembershipBadgeFromParentAutoInclude(): void
    {
        $admin = $this->loginAsQaAdmin();

        $parent = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD . '_parent',
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::KEYWORD . '_parent',
            'navigationAssignments' => [
                [
                    'menuKey' => LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
                    'childSource' => LookupService::NAVIGATION_CHILD_SOURCE_PAGE_CHILDREN,
                ],
            ],
        ], $admin);
        $parentData = $this->assertEnvelopeSuccess($parent, Response::HTTP_CREATED);
        $parentId = $parentData['id'] ?? null;
        self::assertIsInt($parentId);

        $child = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::KEYWORD . '_parent/' . self::KEYWORD,
            'parent' => $parentId,
        ], $admin);
        $childData = $this->assertEnvelopeSuccess($child, Response::HTTP_CREATED);
        $childId = $childData['id'] ?? null;
        self::assertIsInt($childId);

        /** @var NavigationMenuItemRepository $itemRepo */
        $itemRepo = self::getContainer()->get(NavigationMenuItemRepository::class);
        $autoParents = $itemRepo->findActiveAutoIncludeItemsForPage($parentId);
        self::assertNotEmpty($autoParents);

        /** @var NavigationAssignmentService $assignmentService */
        $assignmentService = self::getContainer()->get(NavigationAssignmentService::class);
        $badges = $assignmentService->getMembershipBadgesForPage($childId);
        $auto = array_values(array_filter(
            $badges,
            static fn (array $badge): bool => ($badge['menu_key'] ?? '') === LookupService::NAVIGATION_MENU_KEY_WEB_HEADER
                && ($badge['explicit'] ?? true) === false,
        ));
        self::assertCount(1, $auto);
    }
}
