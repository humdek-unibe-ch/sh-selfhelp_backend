<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\NavigationMenu;
use App\Entity\NavigationMenuItem;
use App\Entity\Page;
use App\Repository\NavigationMenuRepository;
use App\Repository\PageRepository;
use App\Service\CMS\NavigationMenuService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('integration')]
final class NavigationMenuItemPresentationTest extends QaWebTestCase
{
    private const PAGE_KEYWORD = 'qa_nav_page_icons';

    protected function tearDown(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        $page = $pageRepo->findOneBy(['keyword' => self::PAGE_KEYWORD]);
        if ($page instanceof Page) {
            $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
        }
        parent::tearDown();
    }

    public function testPublicPayloadUsesPageTitleAndMenuItemIcons(): void
    {
        $admin = $this->loginAsQaAdmin();
        $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::PAGE_KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::PAGE_KEYWORD,
        ], $admin);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $lookup = self::getContainer()->get(LookupService::class);
        self::assertInstanceOf(LookupService::class, $lookup);

        /** @var NavigationMenuRepository $menuRepo */
        $menuRepo = self::getContainer()->get(NavigationMenuRepository::class);
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);

        $menu = $menuRepo->findByMenuKeyLookupId(
            (int) $lookup->getLookupIdByCode(
                LookupService::NAVIGATION_MENU_KEYS,
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ),
        );
        self::assertInstanceOf(NavigationMenu::class, $menu);

        $page = $pageRepo->findOneBy(['keyword' => self::PAGE_KEYWORD]);
        self::assertInstanceOf(Page::class, $page);

        $pageItem = (new NavigationMenuItem())
            ->setNavigationMenu($menu)
            ->setPage($page)
            ->setItemType($em->getReference(\App\Entity\Lookup::class, (int) $lookup->getLookupIdByCode(
                LookupService::NAVIGATION_MENU_ITEM_TYPES,
                LookupService::NAVIGATION_ITEM_TYPE_PAGE,
            )))
            ->setChildSource($em->getReference(\App\Entity\Lookup::class, (int) $lookup->getLookupIdByCode(
                LookupService::NAVIGATION_CHILD_SOURCES,
                LookupService::NAVIGATION_CHILD_SOURCE_MANUAL,
            )))
            ->setIcon('tabler-home')
            ->setMobileIcon('Home')
            ->setPosition(9910)
            ->setIsActive(true);
        $em->persist($pageItem);

        $externalItem = (new NavigationMenuItem())
            ->setNavigationMenu($menu)
            ->setExternalUrl('https://example.test/support')
            ->setLabel('External help')
            ->setItemType($em->getReference(\App\Entity\Lookup::class, (int) $lookup->getLookupIdByCode(
                LookupService::NAVIGATION_MENU_ITEM_TYPES,
                LookupService::NAVIGATION_ITEM_TYPE_EXTERNAL_URL,
            )))
            ->setChildSource($em->getReference(\App\Entity\Lookup::class, (int) $lookup->getLookupIdByCode(
                LookupService::NAVIGATION_CHILD_SOURCES,
                LookupService::NAVIGATION_CHILD_SOURCE_MANUAL,
            )))
            ->setPosition(9920)
            ->setIsActive(true);
        $em->persist($externalItem);
        $em->flush();

        /** @var NavigationMenuService $navigationMenuService */
        $navigationMenuService = self::getContainer()->get(NavigationMenuService::class);
        $navigationMenuService->invalidateNavigationCaches();

        $payload = $navigationMenuService->getPublicNavigationPayload(LookupService::PAGE_ACCESS_TYPES_WEB, 1);
        /** @var array<string, mixed> $menus */
        $menus = $payload['menus'];
        /** @var array<string, mixed> $webHeader */
        $webHeader = $menus['web_header'];
        $rawItems = $webHeader['items'];
        self::assertIsArray($rawItems);
        /** @var list<array<string, mixed>> $itemList */
        $itemList = array_values(array_filter($rawItems, 'is_array'));

        $pagePayload = $this->findItemById($itemList, $pageItem->getId() ?? 0);
        self::assertNotNull($pagePayload);
        self::assertSame('tabler-home', $pagePayload['icon']);
        self::assertSame('Home', $pagePayload['mobile_icon']);
        self::assertArrayNotHasKey('description', $pagePayload);
        self::assertArrayNotHasKey('aria_label', $pagePayload);
        $pageRef = $pagePayload['page'] ?? null;
        self::assertIsArray($pageRef);
        self::assertArrayNotHasKey('icon', $pageRef);
        self::assertArrayNotHasKey('mobile_icon', $pageRef);

        $externalPayload = $this->findItemById($itemList, $externalItem->getId() ?? 0);
        self::assertNotNull($externalPayload);
        self::assertSame('External help', $externalPayload['label']);
    }

    public function testPublicPayloadUsesTranslatedGroupLabelForLanguage(): void
    {
        $admin = $this->loginAsQaAdmin();

        $createGroup = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_FOOTER . '/items',
            [
                'item_type' => 'group',
                'translations' => [
                    ['language_id' => 1, 'label' => 'QA Legal EN'],
                    ['language_id' => 2, 'label' => 'QA Rechtliches'],
                ],
            ],
            $admin,
        );
        $groupData = $this->assertEnvelopeSuccess($createGroup, Response::HTTP_CREATED);
        /** @var array<string, mixed> $itemPayload */
        $itemPayload = $groupData['item'];
        $groupItemId = $itemPayload['id'] ?? null;
        self::assertIsInt($groupItemId);

        $createPage = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => 'qa_nav_group_child',
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/qa_nav_group_child',
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($createPage, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        $createChild = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_FOOTER . '/items',
            [
                'item_type' => 'page',
                'page_id' => $pageId,
                'parent_item_id' => $groupItemId,
            ],
            $admin,
        );
        $this->assertEnvelopeSuccess($createChild, Response::HTTP_CREATED);

        /** @var NavigationMenuService $navigationMenuService */
        $navigationMenuService = self::getContainer()->get(NavigationMenuService::class);
        $navigationMenuService->invalidateNavigationCaches();

        $payloadEn = $navigationMenuService->getPublicNavigationPayload(LookupService::PAGE_ACCESS_TYPES_WEB, 1);
        $groupEn = $this->findItemById($this->footerItems($payloadEn), $groupItemId);
        self::assertNotNull($groupEn);
        self::assertSame('QA Legal EN', $groupEn['label']);

        $payloadDe = $navigationMenuService->getPublicNavigationPayload(LookupService::PAGE_ACCESS_TYPES_WEB, 2);
        $groupDe = $this->findItemById($this->footerItems($payloadDe), $groupItemId);
        self::assertNotNull($groupDe);
        self::assertSame('QA Rechtliches', $groupDe['label']);

        $this->jsonRequest('DELETE', '/cms-api/v1/admin/navigation/items/' . $groupItemId, null, $admin);
        $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $pageId, null, $admin);
    }

    public function testPublicPayloadFallsBackToKeywordWhenPageHasNoTitle(): void
    {
        $admin = $this->loginAsQaAdmin();
        $keyword = 'qa_nav_no_title_page';
        $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => $keyword,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . $keyword,
        ], $admin);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $lookup = self::getContainer()->get(LookupService::class);
        self::assertInstanceOf(LookupService::class, $lookup);

        /** @var NavigationMenuRepository $menuRepo */
        $menuRepo = self::getContainer()->get(NavigationMenuRepository::class);
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);

        $menu = $menuRepo->findByMenuKeyLookupId(
            (int) $lookup->getLookupIdByCode(
                LookupService::NAVIGATION_MENU_KEYS,
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ),
        );
        self::assertInstanceOf(NavigationMenu::class, $menu);

        $page = $pageRepo->findOneBy(['keyword' => $keyword]);
        self::assertInstanceOf(Page::class, $page);

        $pageItem = (new NavigationMenuItem())
            ->setNavigationMenu($menu)
            ->setPage($page)
            ->setItemType($em->getReference(\App\Entity\Lookup::class, (int) $lookup->getLookupIdByCode(
                LookupService::NAVIGATION_MENU_ITEM_TYPES,
                LookupService::NAVIGATION_ITEM_TYPE_PAGE,
            )))
            ->setChildSource($em->getReference(\App\Entity\Lookup::class, (int) $lookup->getLookupIdByCode(
                LookupService::NAVIGATION_CHILD_SOURCES,
                LookupService::NAVIGATION_CHILD_SOURCE_MANUAL,
            )))
            ->setPosition(9930)
            ->setIsActive(true);
        $em->persist($pageItem);
        $em->flush();

        /** @var NavigationMenuService $navigationMenuService */
        $navigationMenuService = self::getContainer()->get(NavigationMenuService::class);
        $navigationMenuService->invalidateNavigationCaches();

        $payload = $navigationMenuService->getPublicNavigationPayload(LookupService::PAGE_ACCESS_TYPES_WEB, 1);
        /** @var array<string, mixed> $menus */
        $menus = $payload['menus'];
        /** @var array<string, mixed> $webHeader */
        $webHeader = $menus['web_header'];
        $rawItems = $webHeader['items'];
        self::assertIsArray($rawItems);
        /** @var list<array<string, mixed>> $itemList */
        $itemList = array_values(array_filter($rawItems, 'is_array'));

        $pagePayload = $this->findItemById($itemList, $pageItem->getId() ?? 0);
        self::assertNotNull($pagePayload);
        self::assertSame($keyword, $pagePayload['label']);

        $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>|null
     */
    private function findItemById(array $items, int $itemId): ?array
    {
        foreach ($items as $item) {
            if (isset($item['id']) && is_numeric($item['id']) && (int) $item['id'] === $itemId) {
                return $item;
            }
            $children = $item['children'] ?? [];
            if (is_array($children)) {
                /** @var list<array<string, mixed>> $childList */
                $childList = array_values(array_filter($children, 'is_array'));
                $found = $this->findItemById($childList, $itemId);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function footerItems(array $payload): array
    {
        /** @var array<string, mixed> $menus */
        $menus = $payload['menus'];
        /** @var array<string, mixed> $footer */
        $footer = $menus['web_footer'];
        $rawItems = $footer['items'];
        self::assertIsArray($rawItems);
        /** @var list<array<string, mixed>> $items */
        $items = array_values(array_filter($rawItems, 'is_array'));

        return $items;
    }
}
