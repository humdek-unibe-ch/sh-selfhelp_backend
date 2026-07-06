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
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('integration')]
#[Group('security')]
final class NavigationMenuBulkChildCreationTest extends QaWebTestCase
{
    private const PARENT_KEYWORD = 'qa_nav_bulk_parent';
    private const CHILD_A_KEYWORD = 'qa_nav_bulk_child_a';
    private const CHILD_B_KEYWORD = 'qa_nav_bulk_child_b';
    private const GRANDCHILD_KEYWORD = 'qa_nav_bulk_grandchild';

    protected function tearDown(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        // Deepest first so parent deletes never trip on remaining children.
        foreach ([
            self::GRANDCHILD_KEYWORD,
            self::CHILD_B_KEYWORD,
            self::CHILD_A_KEYWORD,
            self::PARENT_KEYWORD,
        ] as $base) {
            foreach (['_desc4', '_desc', '_dup', '_pub', ''] as $suffix) {
                $page = $pageRepo->findOneBy(['keyword' => $base . $suffix]);
                if ($page instanceof Page) {
                    $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
                }
            }
        }

        parent::tearDown();
    }

    public function testCreateMenuItemWithChildrenCreatesStoredRows(): void
    {
        $admin = $this->loginAsQaAdmin();
        $parentId = $this->createQaPage($admin, self::PARENT_KEYWORD, null);
        $childAId = $this->createQaPage($admin, self::CHILD_A_KEYWORD, $parentId);
        $childBId = $this->createQaPage($admin, self::CHILD_B_KEYWORD, $parentId);

        $create = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'page',
                'page_id' => $parentId,
                'child_page_ids' => [$childAId, $childBId],
            ],
            $admin,
        );
        $data = $this->assertEnvelopeSuccess($create, Response::HTTP_CREATED);
        self::assertIsArray($data['item'] ?? null);
        self::assertIsArray($data['children'] ?? null);
        self::assertCount(2, $data['children']);

        /** @var NavigationMenuItemRepository $itemRepo */
        $itemRepo = self::getContainer()->get(NavigationMenuItemRepository::class);
        /** @var array<string, mixed> $parentItem */
        $parentItem = $data['item'];
        $parentItemId = $parentItem['id'] ?? null;
        self::assertIsInt($parentItemId);

        $storedChildren = $itemRepo->findBy(['parentItem' => $parentItemId, 'isActive' => true]);
        self::assertCount(2, $storedChildren);
    }

    public function testIncludeDescendantsNestsGrandchildrenAndFlattensBeyondDepthCap(): void
    {
        $admin = $this->loginAsQaAdmin();
        $parentId = $this->createQaPage($admin, self::PARENT_KEYWORD . '_desc', null);
        $childId = $this->createQaPage($admin, self::CHILD_A_KEYWORD . '_desc', $parentId);
        $grandchildId = $this->createQaPage($admin, self::GRANDCHILD_KEYWORD . '_desc', $childId);
        // Depth 4 in the page tree — beyond the three-level menu cap.
        $this->createQaPage($admin, self::GRANDCHILD_KEYWORD . '_desc4', $grandchildId);

        $create = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'page',
                'page_id' => $parentId,
                'child_page_ids' => [$childId],
                'include_descendants' => true,
            ],
            $admin,
        );
        $data = $this->assertEnvelopeSuccess($create, Response::HTTP_CREATED);
        /** @var list<array<string, mixed>> $createdChildren */
        $createdChildren = $data['children'] ?? [];
        self::assertCount(3, $createdChildren);

        /** @var NavigationMenuItemRepository $itemRepo */
        $itemRepo = self::getContainer()->get(NavigationMenuItemRepository::class);
        /** @var array<string, mixed> $parentItem */
        $parentItem = $data['item'];
        $parentItemId = $parentItem['id'] ?? null;
        self::assertIsInt($parentItemId);

        // Menus support three levels: the grandchild page nests under the
        // child item, and the great-grandchild (depth 4) is flattened to a
        // direct child of the root item.
        $directChildren = $itemRepo->findBy(['parentItem' => $parentItemId, 'isActive' => true]);
        self::assertCount(2, $directChildren);
        $nestedCounts = [];
        foreach ($directChildren as $directChild) {
            $grandchildren = $itemRepo->findBy(['parentItem' => $directChild, 'isActive' => true]);
            $nestedCounts[] = count($grandchildren);
            foreach ($grandchildren as $grandchildItem) {
                // Depth cap: grandchild items never have children of their own.
                self::assertCount(0, $itemRepo->findBy(['parentItem' => $grandchildItem, 'isActive' => true]));
            }
        }
        sort($nestedCounts);
        // One direct child is the real child page (with the nested grandchild),
        // the other is the flattened depth-4 page (no children).
        self::assertSame([0, 1], $nestedCounts);
    }

    public function testDuplicateChildPageReturnsBadRequestWithoutPartialWrite(): void
    {
        $admin = $this->loginAsQaAdmin();
        $parentId = $this->createQaPage($admin, self::PARENT_KEYWORD . '_dup', null);
        $childId = $this->createQaPage($admin, self::CHILD_A_KEYWORD . '_dup', $parentId);

        $first = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'page',
                'page_id' => $parentId,
                'child_page_ids' => [$childId],
            ],
            $admin,
        );
        $this->assertEnvelopeSuccess($first, Response::HTTP_CREATED);

        $duplicate = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'page',
                'page_id' => $childId,
            ],
            $admin,
        );
        $this->assertEnvelope400($duplicate);

        /** @var NavigationMenuItemRepository $itemRepo */
        $itemRepo = self::getContainer()->get(NavigationMenuItemRepository::class);
        $childItems = $itemRepo->findBy(['page' => $childId, 'isActive' => true]);
        self::assertCount(1, $childItems);
    }

    public function testPublicNavigationContainsNoVirtualItems(): void
    {
        $admin = $this->loginAsQaAdmin();
        $parentId = $this->createQaPage($admin, self::PARENT_KEYWORD . '_pub', null);
        $childId = $this->createQaPage($admin, self::CHILD_A_KEYWORD . '_pub', $parentId);
        $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'page',
                'page_id' => $parentId,
                'child_page_ids' => [$childId],
            ],
            $admin,
        );

        $public = $this->jsonRequest('GET', '/cms-api/v1/navigation', null, $admin);
        $payload = $this->assertEnvelopeSuccess($public, Response::HTTP_OK);
        $json = json_encode($payload);
        self::assertIsString($json);
        self::assertStringNotContainsString('is_virtual', $json);
        self::assertStringNotContainsString('virtual-', $json);
    }

    private function createQaPage(string $adminToken, string $keyword, ?int $parentId): int
    {
        $payload = [
            'keyword' => $keyword,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'openAccess' => true,
            'url' => '/' . $keyword,
        ];
        if ($parentId !== null) {
            $payload['parent'] = $parentId;
        }

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', $payload, $adminToken);
        $data = $this->assertEnvelopeSuccess($envelope, Response::HTTP_CREATED);
        $id = $data['id'] ?? null;
        self::assertIsInt($id);

        return $id;
    }
}
