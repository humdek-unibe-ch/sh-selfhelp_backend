<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\Language;
use App\Entity\NavigationMenu;
use App\Entity\NavigationMenuItem;
use App\Entity\NavigationMenuItemTranslation;
use App\Entity\Page;
use App\Repository\LanguageRepository;
use App\Repository\NavigationMenuItemRepository;
use App\Repository\NavigationMenuRepository;
use App\Repository\PageRepository;
use App\Service\CMS\NavigationMenuService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class NavigationMenuItemPresentationTest extends QaWebTestCase
{
    private const PAGE_KEYWORD = 'qa_nav_page_desc';

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

    public function testPublicPayloadIncludesDescriptionAndAriaLabel(): void
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
        /** @var LanguageRepository $languageRepo */
        $languageRepo = self::getContainer()->get(LanguageRepository::class);

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
            ->setPosition(9910)
            ->setIsActive(true);
        $em->persist($pageItem);

        $externalItem = (new NavigationMenuItem())
            ->setNavigationMenu($menu)
            ->setExternalUrl('https://example.test/support')
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

        $language = $languageRepo->find(1);
        self::assertInstanceOf(Language::class, $language);

        $em->persist((new NavigationMenuItemTranslation())
            ->setNavigationMenuItem($pageItem)
            ->setLanguage($language)
            ->setDescription('Page menu description')
            ->setAriaLabel('Open described page'));
        $em->persist((new NavigationMenuItemTranslation())
            ->setNavigationMenuItem($externalItem)
            ->setLanguage($language)
            ->setLabel('External help')
            ->setDescription('External support portal')
            ->setAriaLabel('Open external help'));
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
        self::assertSame('Page menu description', $pagePayload['description']);
        self::assertSame('Open described page', $pagePayload['aria_label']);

        $externalPayload = $this->findItemById($itemList, $externalItem->getId() ?? 0);
        self::assertNotNull($externalPayload);
        self::assertSame('External help', $externalPayload['label']);
        self::assertSame('External support portal', $externalPayload['description']);
        self::assertSame('Open external help', $externalPayload['aria_label']);
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
}
