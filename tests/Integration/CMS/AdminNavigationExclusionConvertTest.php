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
use App\Repository\NavigationMenuRepository;
use App\Repository\PageRepository;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('integration')]
final class AdminNavigationExclusionConvertTest extends QaWebTestCase
{
    private const PARENT_KEYWORD = 'qa_nav_excl_parent';
    private const CHILD_KEYWORD = 'qa_nav_excl_child';

    protected function tearDown(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        foreach ([self::CHILD_KEYWORD, self::PARENT_KEYWORD] as $keyword) {
            $page = $pageRepo->findOneBy(['keyword' => $keyword]);
            if ($page instanceof Page) {
                $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
            }
        }

        parent::tearDown();
    }

    public function testConvertAutoChildrenCreatesExplicitMenuItems(): void
    {
        $admin = $this->loginAsQaAdmin();

        $parentEnvelope = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::PARENT_KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'openAccess' => true,
            'url' => '/' . self::PARENT_KEYWORD,
            'navigationAssignments' => [
                [
                    'menuKey' => LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
                    'childSource' => LookupService::NAVIGATION_CHILD_SOURCE_PAGE_CHILDREN,
                ],
            ],
        ], $admin);
        $parentData = $this->assertEnvelopeSuccess($parentEnvelope, Response::HTTP_CREATED);
        $parentId = $parentData['id'] ?? null;
        self::assertIsInt($parentId);

        $childEnvelope = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::CHILD_KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'openAccess' => true,
            'parent' => $parentId,
            'url' => '/' . self::PARENT_KEYWORD . '/' . self::CHILD_KEYWORD,
        ], $admin);
        $childData = $this->assertEnvelopeSuccess($childEnvelope, Response::HTTP_CREATED);
        $childId = $childData['id'] ?? null;
        self::assertIsInt($childId);

        /** @var NavigationMenuItemRepository $itemRepo */
        $itemRepo = self::getContainer()->get(NavigationMenuItemRepository::class);
        $parentItems = $itemRepo->findBy(['page' => $parentId, 'isActive' => true]);
        self::assertNotEmpty($parentItems);
        $parentItem = $parentItems[0];
        self::assertInstanceOf(NavigationMenuItem::class, $parentItem);
        $itemId = (int) $parentItem->getId();

        $exclusion = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/items/' . $itemId . '/exclusions',
            ['page_id' => $childId],
            $admin,
        );
        $this->assertEnvelopeSuccess($exclusion, Response::HTTP_CREATED);

        $convert = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/items/' . $itemId . '/convert-auto-children?language_id=1',
            null,
            $admin,
        );
        $converted = $this->assertEnvelopeSuccess($convert, Response::HTTP_OK);
        self::assertIsArray($converted['created_items'] ?? null);
    }
}
