<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\Group;
use App\Entity\Page;
use App\Entity\PageAclGroup;
use App\Repository\PageRepository;
use App\Service\CMS\NavigationMenuService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;

#[TestGroup('integration')]
final class NavigationMenuAclVisibilityTest extends QaWebTestCase
{
    private const PAGE_KEYWORD = 'qa_nav_acl_menu';

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

    public function testMenuPageItemHiddenWithoutAclAndVisibleAfterGrant(): void
    {
        $admin = $this->loginAsQaAdmin();
        $create = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::PAGE_KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => false,
            'url' => '/' . self::PAGE_KEYWORD,
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($create, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        $this->jsonRequest('POST', '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items', [
            'item_type' => 'page',
            'page_id' => $pageId,
            'icon' => 'tabler-lock',
            'mobile_icon' => 'Lock',
        ], $admin);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $subjectGroup = $em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subjectGroup);

        $userToken = $this->loginAsQaUser();
        self::assertTrue($this->menuContainsPageKeyword(self::PAGE_KEYWORD, $userToken));

        $aclRow = $em->getRepository(PageAclGroup::class)->findOneBy([
            'page' => $pageId,
            'group' => $subjectGroup,
        ]);
        self::assertInstanceOf(PageAclGroup::class, $aclRow);
        $em->remove($aclRow);
        $em->flush();
        $this->invalidateNavigationAndPermissionCaches();

        self::assertFalse($this->menuContainsPageKeyword(self::PAGE_KEYWORD, $userToken));

        $page = $em->getRepository(Page::class)->find($pageId);
        self::assertInstanceOf(Page::class, $page);
        $subjectGroup = $em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subjectGroup);
        $aclService = self::getContainer()->get(\App\Service\ACL\ACLService::class);
        self::assertInstanceOf(\App\Service\ACL\ACLService::class, $aclService);
        $aclService->addGroupAcl($page, $subjectGroup, true, false, false, false, $em);
        $em->flush();
        $this->invalidateNavigationAndPermissionCaches();

        self::assertTrue($this->menuContainsPageKeyword(self::PAGE_KEYWORD, $userToken));
    }

    private function menuContainsPageKeyword(string $keyword, string $token): bool
    {
        $envelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/navigation?platform=' . LookupService::PAGE_ACCESS_TYPES_WEB,
            null,
            $token,
        );
        $payload = $this->assertEnvelopeSuccess($envelope);
        if (!isset($payload['menus']) || !is_array($payload['menus'])) {
            return false;
        }
        /** @var array<string, mixed> $menus */
        $menus = $payload['menus'];
        /** @var array<string, mixed> $webHeader */
        $webHeader = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER] ?? [];
        $items = $webHeader['items'] ?? null;
        if (!is_array($items)) {
            return false;
        }

        return $this->treeContainsPageKeyword($items, $keyword);
    }

    /**
     * @param array<mixed> $items
     */
    private function treeContainsPageKeyword(array $items, string $keyword): bool
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $page = $item['page'] ?? null;
            if (is_array($page) && ($page['keyword'] ?? null) === $keyword) {
                return true;
            }
            $children = $item['children'] ?? null;
            if (is_array($children) && $this->treeContainsPageKeyword($children, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function invalidateNavigationAndPermissionCaches(): void
    {
        /** @var CacheService $cache */
        $cache = self::getContainer()->get(CacheService::class);
        $cache->withCategory(CacheService::CATEGORY_PERMISSIONS)->invalidateCategory();
        $cache->withCategory(CacheService::CATEGORY_PAGES)->invalidateCategory();

        /** @var NavigationMenuService $navigationMenuService */
        $navigationMenuService = self::getContainer()->get(NavigationMenuService::class);
        $navigationMenuService->invalidateNavigationCaches();
    }
}
