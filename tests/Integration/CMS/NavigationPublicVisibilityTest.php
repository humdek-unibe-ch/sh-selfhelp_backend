<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\Page;
use App\Entity\PageType;
use App\Repository\PageRepository;
use App\Repository\PageTypeRepository;
use App\Service\CMS\NavigationMenuService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;

/**
 * Covers the public-navigable page-type resolution (by name, not id) and the
 * `public_visibility` verdict the admin navigation payload exposes so the
 * builder can explain why an item never shows up in the public menu.
 */
#[TestGroup('integration')]
final class NavigationPublicVisibilityTest extends QaWebTestCase
{
    private const PAGE_NORMAL = 'qa_nav_vis_core';
    private const PAGE_HEADLESS = 'qa_nav_vis_headless';
    private const PAGE_SYSTEM_TYPE = 'qa_nav_vis_system';

    private const QA_PAGE_TYPE_NAME = 'qa_nav_vis_type';

    protected function tearDown(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        foreach ([self::PAGE_NORMAL, self::PAGE_HEADLESS, self::PAGE_SYSTEM_TYPE] as $keyword) {
            $page = $pageRepo->findOneBy(['keyword' => $keyword]);
            if ($page instanceof Page) {
                $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
            }
        }

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $qaType = $em->getRepository(PageType::class)->findOneBy(['name' => self::QA_PAGE_TYPE_NAME]);
        if ($qaType instanceof PageType) {
            $em->remove($qaType);
            $em->flush();
        }

        parent::tearDown();
    }

    /**
     * Task 1: the filter keeps content page types and drops system ones.
     */
    public function testPublicNavigableTypesCoverContentPagesOnly(): void
    {
        /** @var PageTypeRepository $pageTypeRepo */
        $pageTypeRepo = self::getContainer()->get(PageTypeRepository::class);
        $ids = $pageTypeRepo->findPublicNavigableIds();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        foreach (PageType::PUBLIC_NAVIGABLE_PAGE_TYPES as $name) {
            $type = $em->getRepository(PageType::class)->findOneBy(['name' => $name]);
            self::assertInstanceOf(PageType::class, $type, sprintf('Page type "%s" must exist', $name));
            self::assertContains($type->getId(), $ids, sprintf('"%s" must be public-navigable', $name));
        }

        $intern = $em->getRepository(PageType::class)->findOneBy(['name' => 'intern']);
        self::assertInstanceOf(PageType::class, $intern);
        self::assertNotContains($intern->getId(), $ids, 'System type "intern" must not be public-navigable');
    }

    /**
     * Task 1 regression: the resolution must follow the NAME, not the legacy
     * ids [2, 3]. A page type seeded outside that id range but named `core`
     * would previously have been dropped.
     */
    public function testPublicNavigableResolutionIsNameBasedNotIdBased(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // A brand new page type gets an auto-increment id far outside [2, 3].
        $qaType = new PageType();
        $qaType->setName(self::QA_PAGE_TYPE_NAME);
        $em->persist($qaType);
        $em->flush();

        $newId = $qaType->getId();
        self::assertIsInt($newId);
        self::assertNotContains($newId, [2, 3], 'Fixture assumes an id outside the legacy range');

        /** @var PageTypeRepository $pageTypeRepo */
        $pageTypeRepo = self::getContainer()->get(PageTypeRepository::class);

        // Not named in PUBLIC_NAVIGABLE_PAGE_TYPES -> excluded, purely by name.
        self::assertNotContains($newId, $pageTypeRepo->findPublicNavigableIds());

        // The decisive assertion: the resolved set is exactly the set of ids
        // whose NAME is public-navigable — never the literal [2, 3]. Seeded
        // `core`/`experiment` happen to be 2 and 3 today, so compare against
        // names resolved independently rather than against those constants.
        $expected = [];
        foreach (PageType::PUBLIC_NAVIGABLE_PAGE_TYPES as $name) {
            $type = $em->getRepository(PageType::class)->findOneBy(['name' => $name]);
            self::assertInstanceOf(PageType::class, $type);
            $expected[] = $type->getId();
        }

        sort($expected);
        $actual = $pageTypeRepo->findPublicNavigableIds();
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'Resolution must follow page type names, not the hardcoded ids [2, 3]',
        );
    }

    /**
     * Task 2: each reason code is reported on the admin payload.
     */
    public function testAdminPayloadReportsPublicVisibilityReasons(): void
    {
        $admin = $this->loginAsQaAdmin();

        $normalId = $this->createPage(self::PAGE_NORMAL, false, $admin);
        $headlessId = $this->createPage(self::PAGE_HEADLESS, true, $admin);

        $this->addMenuItem($normalId, $admin);
        $this->addMenuItem($headlessId, $admin);

        $visibility = $this->adminVisibilityByPageId($admin);

        self::assertSame(
            ['rendered' => true, 'reason' => null],
            $visibility[$normalId] ?? null,
            'A normal core page renders in the public menu',
        );
        self::assertSame(
            ['rendered' => false, 'reason' => NavigationMenuService::PUBLIC_VISIBILITY_REASON_HEADLESS],
            $visibility[$headlessId] ?? null,
            'A headless page is dropped with reason "headless"',
        );
    }

    /**
     * Task 2: a page whose type is not public-navigable reports
     * `page_type_excluded`.
     */
    public function testSystemTypePageReportsPageTypeExcluded(): void
    {
        $admin = $this->loginAsQaAdmin();
        $pageId = $this->createPage(self::PAGE_SYSTEM_TYPE, false, $admin);
        $this->addMenuItem($pageId, $admin);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = $em->getRepository(Page::class)->find($pageId);
        self::assertInstanceOf(Page::class, $page);
        $intern = $em->getRepository(PageType::class)->findOneBy(['name' => 'intern']);
        self::assertInstanceOf(PageType::class, $intern);
        $page->setPageType($intern);
        $em->flush();
        $this->invalidateNavigationAndPageCaches();

        $visibility = $this->adminVisibilityByPageId($admin);

        self::assertSame(
            ['rendered' => false, 'reason' => NavigationMenuService::PUBLIC_VISIBILITY_REASON_PAGE_TYPE_EXCLUDED],
            $visibility[$pageId] ?? null,
        );
    }

    /**
     * The anti-drift test: whatever the admin payload claims about an item must
     * match whether the public /navigation tree actually contains it.
     */
    public function testAdminVerdictMatchesActualPublicRendering(): void
    {
        $admin = $this->loginAsQaAdmin();

        $normalId = $this->createPage(self::PAGE_NORMAL, false, $admin);
        $headlessId = $this->createPage(self::PAGE_HEADLESS, true, $admin);
        $this->addMenuItem($normalId, $admin);
        $this->addMenuItem($headlessId, $admin);
        $this->invalidateNavigationAndPageCaches();

        $visibility = $this->adminVisibilityByPageId($admin);
        $publicKeywords = $this->publicMenuPageKeywords($admin);

        foreach ([self::PAGE_NORMAL => $normalId, self::PAGE_HEADLESS => $headlessId] as $keyword => $pageId) {
            $verdict = $visibility[$pageId] ?? null;
            self::assertIsArray($verdict, sprintf('Missing public_visibility for %s', $keyword));

            self::assertSame(
                $verdict['rendered'],
                in_array($keyword, $publicKeywords, true),
                sprintf(
                    'Admin verdict (rendered=%s) disagrees with the public menu for "%s"',
                    $verdict['rendered'] ? 'true' : 'false',
                    $keyword,
                ),
            );
        }
    }

    /**
     * `menu_eligible` answers "may this page EVER be in a public menu?" — page
     * type only. A headless CORE page is still eligible: headless is a checkbox
     * an admin can untick, so filtering it out of the picker would hide a page
     * they can legitimately fix. Conflating the two is the one way this goes
     * wrong, so it is asserted directly.
     */
    public function testMenuEligibilityCoversPageTypeOnlyNotHeadless(): void
    {
        $admin = $this->loginAsQaAdmin();

        $corePageId = $this->createPage(self::PAGE_NORMAL, false, $admin);
        $headlessCoreId = $this->createPage(self::PAGE_HEADLESS, true, $admin);

        $eligibility = $this->adminPagesMenuEligibility($admin);

        self::assertTrue($eligibility[$corePageId] ?? null, 'A core page is menu-eligible');
        self::assertTrue(
            $eligibility[$headlessCoreId] ?? null,
            'A headless core page stays menu-eligible — headless is fixable, so it is tagged, not filtered',
        );

        // A non-public-navigable page type IS permanently excluded.
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = $em->getRepository(Page::class)->find($corePageId);
        self::assertInstanceOf(Page::class, $page);
        $intern = $em->getRepository(PageType::class)->findOneBy(['name' => 'intern']);
        self::assertInstanceOf(PageType::class, $intern);
        $page->setPageType($intern);
        $em->flush();
        $this->invalidateNavigationAndPageCaches();

        self::assertFalse(
            $this->adminPagesMenuEligibility($admin)[$corePageId] ?? null,
            'A system-type page is never menu-eligible',
        );
    }

    /**
     * @return array<int, bool>
     */
    private function adminPagesMenuEligibility(string $token): array
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $token);
        $pages = $this->assertEnvelopeSuccess($envelope);

        $out = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $pageId = $page['id_pages'] ?? null;
            $eligible = $page['menu_eligible'] ?? null;
            if (is_int($pageId) && is_bool($eligible)) {
                $out[$pageId] = $eligible;
            }
        }

        return $out;
    }

    private function createPage(string $keyword, bool $headless, string $token): int
    {
        $create = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => $keyword,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => $headless,
            'openAccess' => true,
            'url' => '/' . $keyword,
        ], $token);
        $pageData = $this->assertEnvelopeSuccess($create, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        return $pageId;
    }

    private function addMenuItem(int $pageId, string $token): void
    {
        $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            ['item_type' => 'page', 'page_id' => $pageId],
            $token,
        );
    }

    /**
     * @return array<int, array{rendered: bool, reason: string|null}>
     */
    private function adminVisibilityByPageId(string $token): array
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/navigation', null, $token);
        $payload = $this->assertEnvelopeSuccess($envelope);

        $menus = $payload['menus'] ?? [];
        self::assertIsArray($menus);
        $webHeader = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER] ?? [];
        self::assertIsArray($webHeader);
        $items = $webHeader['items'] ?? [];
        self::assertIsArray($items);

        $out = [];
        $this->collectVisibility($items, $out);

        return $out;
    }

    /**
     * @param array<mixed> $items
     * @param array<int, array{rendered: bool, reason: string|null}> $out
     */
    private function collectVisibility(array $items, array &$out): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pageId = $item['page_id'] ?? null;
            $visibility = $item['public_visibility'] ?? null;
            if (is_int($pageId) && is_array($visibility)) {
                /** @var array{rendered: bool, reason: string|null} $visibility */
                $out[$pageId] = $visibility;
            }
            $children = $item['children'] ?? null;
            if (is_array($children)) {
                $this->collectVisibility($children, $out);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function publicMenuPageKeywords(string $token): array
    {
        $envelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/navigation?platform=' . LookupService::PAGE_ACCESS_TYPES_WEB,
            null,
            $token,
        );
        $payload = $this->assertEnvelopeSuccess($envelope);

        $menus = $payload['menus'] ?? [];
        self::assertIsArray($menus);
        $webHeader = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER] ?? [];
        self::assertIsArray($webHeader);
        $items = $webHeader['items'] ?? [];
        self::assertIsArray($items);

        $out = [];
        $this->collectKeywords($items, $out);

        return $out;
    }

    /**
     * @param array<mixed> $items
     * @param list<string> $out
     */
    private function collectKeywords(array $items, array &$out): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $page = $item['page'] ?? null;
            if (is_array($page) && is_string($page['keyword'] ?? null)) {
                $out[] = $page['keyword'];
            }
            $children = $item['children'] ?? null;
            if (is_array($children)) {
                $this->collectKeywords($children, $out);
            }
        }
    }

    private function invalidateNavigationAndPageCaches(): void
    {
        /** @var CacheService $cache */
        $cache = self::getContainer()->get(CacheService::class);
        $cache->withCategory(CacheService::CATEGORY_PAGES)->invalidateCategory();

        /** @var NavigationMenuService $navigationMenuService */
        $navigationMenuService = self::getContainer()->get(NavigationMenuService::class);
        $navigationMenuService->invalidateNavigationCaches();
    }
}
