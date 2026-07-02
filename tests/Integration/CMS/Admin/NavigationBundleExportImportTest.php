<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS\Admin;

use App\Entity\PageAclGroup;
use App\Exception\ServiceException;
use App\Repository\NavigationMenuItemRepository;
use App\Repository\NavigationMenuRepository;
use App\Repository\PageRepository;
use App\Service\CMS\Admin\NavigationExportImportService;
use App\Service\CMS\Admin\PageExportImportService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('integration')]
final class NavigationBundleExportImportTest extends QaWebTestCase
{
    private const PARENT_KEYWORD = 'qa_nav_bundle_parent';
    private const CHILD_KEYWORD = 'qa_nav_bundle_child';

    protected function tearDown(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        foreach ([self::CHILD_KEYWORD, self::PARENT_KEYWORD, self::CHILD_KEYWORD . '_imp', self::PARENT_KEYWORD . '_imp'] as $keyword) {
            $page = $pageRepo->findOneBy(['keyword' => $keyword]);
            if ($page !== null) {
                $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
            }
        }
        parent::tearDown();
    }

    public function testExportImportRoundTripWithEmbeddedPagesAndReplacePolicy(): void
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
            'url' => '/' . self::PARENT_KEYWORD . '/' . self::CHILD_KEYWORD,
            'parent' => $parentId,
        ], $admin);
        $childData = $this->assertEnvelopeSuccess($child, Response::HTTP_CREATED);
        $childId = $childData['id'] ?? null;
        self::assertIsInt($childId);

        $this->jsonRequest('POST', '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items', [
            'item_type' => 'page',
            'page_id' => $parentId,
            'icon' => 'IconFolder',
            'mobile_icon' => 'Folder',
        ], $admin);

        $this->jsonRequest('POST', '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items', [
            'item_type' => 'page',
            'page_id' => $childId,
            'parent_item_id' => $this->findMenuItemIdForPage($parentId),
        ], $admin);

        /** @var NavigationExportImportService $navExportImport */
        $navExportImport = self::getContainer()->get(NavigationExportImportService::class);
        $bundle = $navExportImport->exportBundle([
            'mode' => NavigationExportImportService::EXPORT_MODE_BRANCH,
            'page_keywords' => [self::PARENT_KEYWORD, self::CHILD_KEYWORD],
            'include_pages' => true,
            'menu_keys' => [LookupService::NAVIGATION_MENU_KEY_WEB_HEADER],
        ]);

        self::assertSame(NavigationExportImportService::BUNDLE_FORMAT, $bundle['format']);
        self::assertArrayHasKey('pages', $bundle);
        self::assertArrayHasKey('menus', $bundle);

        $pages = $bundle['pages'] ?? null;
        self::assertIsArray($pages);
        foreach ($pages as &$page) {
            self::assertIsArray($page);
            $keyword = is_string($page['keyword'] ?? null) ? $page['keyword'] : '';
            $rawKeyword = str_ends_with($keyword, '_imp') ? substr($keyword, 0, -4) : $keyword;
            $newKeyword = str_ends_with($keyword, '_imp') ? $keyword : $keyword . '_imp';
            $page['keyword'] = $newKeyword;
            if (is_string($page['parent_keyword'] ?? null) && $page['parent_keyword'] !== '') {
                $page['parent_keyword'] = $page['parent_keyword'] . '_imp';
            }
            if (is_string($page['url'] ?? null) && $page['url'] !== '') {
                $page['url'] = str_replace($rawKeyword, $newKeyword, $page['url']);
            }
            if (is_array($page['routes'] ?? null)) {
                foreach ($page['routes'] as &$route) {
                    if (!is_array($route)) {
                        continue;
                    }
                    $pattern = is_string($route['path_pattern'] ?? null) ? $route['path_pattern'] : '';
                    if ($pattern !== '') {
                        $route['path_pattern'] = str_replace($rawKeyword, $newKeyword, $pattern);
                    }
                }
                unset($route);
            }
        }
        unset($page);
        $bundle['pages'] = $pages;

        $menus = $bundle['menus'];
        self::assertIsArray($menus);
        $header = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER] ?? null;
        self::assertIsArray($header);
        $items = $header['items'] ?? null;
        self::assertIsArray($items);
        foreach ($items as &$item) {
            self::assertIsArray($item);
            if (is_string($item['page_keyword'] ?? null) && $item['page_keyword'] !== '') {
                $item['page_keyword'] = $item['page_keyword'] . '_imp';
            }
        }
        unset($item);
        /** @var array<string, mixed> $bundleMenus */
        $bundleMenus = $bundle['menus'];
        $headerMenu = $bundleMenus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER] ?? null;
        self::assertIsArray($headerMenu);
        $headerMenu['items'] = $items;
        $bundleMenus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER] = $headerMenu;
        $bundle['menus'] = $bundleMenus;

        $validation = $navExportImport->validateImport($bundle, [
            'menu_policies' => [
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER => NavigationExportImportService::POLICY_REPLACE,
            ],
        ]);
        self::assertTrue($validation['valid'], json_encode($validation['issues'], JSON_THROW_ON_ERROR));

        $navExportImport->importBundle($bundle, [
            'menu_policies' => [
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER => NavigationExportImportService::POLICY_REPLACE,
            ],
        ]);

        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        $importedParent = $pageRepo->findOneBy(['keyword' => self::PARENT_KEYWORD . '_imp']);
        self::assertNotNull($importedParent);
        $importedChild = $pageRepo->findOneBy(['keyword' => self::CHILD_KEYWORD . '_imp']);
        self::assertNotNull($importedChild);

        /** @var NavigationMenuItemRepository $itemRepo */
        $itemRepo = self::getContainer()->get(NavigationMenuItemRepository::class);
        self::assertNotEmpty($itemRepo->findBy(['page' => $importedParent, 'isActive' => true]));
        self::assertNotEmpty($itemRepo->findBy(['page' => $importedChild, 'isActive' => true]));
    }

    public function testImportEmbeddedPagesWithCanonicalSections(): void
    {
        $admin = $this->loginAsQaAdmin();
        $keyword = 'qa_nav_embedded_page';

        /** @var NavigationExportImportService $navExportImport */
        $navExportImport = self::getContainer()->get(NavigationExportImportService::class);

        $bundle = [
            'format' => NavigationExportImportService::BUNDLE_FORMAT,
            'version' => NavigationExportImportService::BUNDLE_VERSION,
            'menus' => [
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER => [
                    'items' => [
                        [
                            'ref' => 'embedded-home',
                            'parent_ref' => null,
                            'item_type' => 'page',
                            'position' => 10,
                            'is_active' => true,
                            'page_keyword' => $keyword,
                        ],
                    ],
                ],
            ],
            'pages' => [
                [
                    'keyword' => $keyword,
                    'surface' => 'public',
                    'page_access_type' => LookupService::PAGE_ACCESS_TYPES_WEB,
                    'headless' => false,
                    'open_access' => true,
                    'url' => '/' . $keyword,
                    'sections' => [
                        [
                            'style_name' => 'title',
                            'fields' => [
                                'content' => ['en-GB' => ['content' => 'Embedded page']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $validation = $navExportImport->validateImport($bundle, [
            'menu_policies' => [
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER => NavigationExportImportService::POLICY_APPEND,
            ],
        ]);
        self::assertTrue($validation['valid'], json_encode($validation['issues'], JSON_THROW_ON_ERROR));

        $response = $this->jsonRequest('POST', '/cms-api/v1/admin/navigation/import', [
            'bundle' => $bundle,
            'options' => [
                'menu_policies' => [
                    LookupService::NAVIGATION_MENU_KEY_WEB_HEADER => NavigationExportImportService::POLICY_APPEND,
                ],
            ],
        ], $admin);
        $this->assertEnvelopeSuccess($response, Response::HTTP_OK);

        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        $page = $pageRepo->findOneBy(['keyword' => $keyword]);
        self::assertNotNull($page);
        $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
    }

    public function testImportPassesAccessGroupsToEmbeddedPageImport(): void
    {
        $admin = $this->loginAsQaAdmin();
        $keyword = 'qa_nav_access_group_page';
        $groupName = 'qa_nav_import_viewers';

        $groupResponse = $this->jsonRequest('POST', '/cms-api/v1/admin/groups', [
            'name' => $groupName,
            'description' => 'QA navigation import viewer group',
        ], $admin);
        $groupData = $this->assertEnvelopeSuccess($groupResponse, Response::HTTP_CREATED);
        $groupId = $groupData['id'] ?? null;
        self::assertIsInt($groupId);

        /** @var NavigationExportImportService $navExportImport */
        $navExportImport = self::getContainer()->get(NavigationExportImportService::class);

        $bundle = [
            'format' => NavigationExportImportService::BUNDLE_FORMAT,
            'version' => NavigationExportImportService::BUNDLE_VERSION,
            'menus' => [
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER => [
                    'items' => [
                        [
                            'ref' => 'access-group-home',
                            'parent_ref' => null,
                            'item_type' => 'page',
                            'position' => 10,
                            'is_active' => true,
                            'page_keyword' => $keyword,
                        ],
                    ],
                ],
            ],
            'pages' => [
                [
                    'keyword' => $keyword,
                    'surface' => 'public',
                    'page_access_type' => LookupService::PAGE_ACCESS_TYPES_WEB,
                    'headless' => false,
                    'open_access' => false,
                    'url' => '/' . $keyword,
                    'routes' => [
                        [
                            'path_pattern' => '/' . $keyword,
                            'is_canonical' => true,
                            'is_active' => true,
                            'priority' => 0,
                        ],
                    ],
                    'sections' => [
                        [
                            'style_name' => 'title',
                            'fields' => [
                                'content' => ['en-GB' => ['content' => 'Access group test']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $navExportImport->importBundle($bundle, [
            'access_groups' => [$groupId],
            'menu_policies' => [
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER => NavigationExportImportService::POLICY_APPEND,
            ],
        ]);

        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        $page = $pageRepo->findOneBy(['keyword' => $keyword]);
        self::assertNotNull($page);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $viewerAcl = $em->getRepository(PageAclGroup::class)->findOneBy([
            'page' => $page,
            'group' => $groupId,
        ]);
        self::assertInstanceOf(PageAclGroup::class, $viewerAcl);
        self::assertTrue($viewerAcl->isAclSelect());

        $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
        $this->jsonRequest('DELETE', '/cms-api/v1/admin/groups/' . $groupId, null, $admin);
    }

    public function testMenuDemoBundleValidatesWithImportHintsRoutePrefix(): void
    {
        $bundlePath = \App\Tests\Support\ExampleBundleTestPaths::menuDemoBundle();
        $raw = file_get_contents($bundlePath);
        self::assertIsString($raw);
        /** @var array<string, mixed> $bundle */
        $bundle = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        /** @var NavigationExportImportService $navExportImport */
        $navExportImport = self::getContainer()->get(NavigationExportImportService::class);
        $validation = $navExportImport->validateImport($bundle, [
            'menu_policies' => [
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER => NavigationExportImportService::POLICY_REPLACE,
            ],
        ]);

        $routeConflicts = array_values(array_filter(
            $validation['issues'],
            static fn (array $issue): bool => $issue['code'] === 'route_conflict',
        ));
        self::assertTrue(
            $validation['valid'],
            'Menu demo bundle should validate when import_hints supply /demo route prefix. Issues: '
            . json_encode($validation['issues'], JSON_THROW_ON_ERROR),
        );
        self::assertSame([], $routeConflicts);
    }

    public function testMenuDemoBundleImportsAtomicallyWithUniquePrefix(): void
    {
        ini_set('memory_limit', '512M');

        $admin = $this->loginAsQaAdmin();
        $keywordPrefix = 'qa-nav-atomic-import-';
        $routePrefix = '/demo-atomic-import';

        $raw = file_get_contents(\App\Tests\Support\ExampleBundleTestPaths::menuDemoBundle());
        self::assertIsString($raw);
        /** @var array<string, mixed> $bundle */
        $bundle = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $response = $this->jsonRequest('POST', '/cms-api/v1/admin/navigation/import', [
            'bundle' => $bundle,
            'options' => [
                'keyword_prefix' => $keywordPrefix,
                'route_prefix' => $routePrefix,
                'menu_policies' => [
                    LookupService::NAVIGATION_MENU_KEY_WEB_HEADER => NavigationExportImportService::POLICY_REPLACE,
                    LookupService::NAVIGATION_MENU_KEY_WEB_FOOTER => NavigationExportImportService::POLICY_REPLACE,
                    LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER => NavigationExportImportService::POLICY_REPLACE,
                    LookupService::NAVIGATION_MENU_KEY_MOBILE_BOTTOM_TABS => NavigationExportImportService::POLICY_REPLACE,
                ],
            ],
        ], $admin);
        $data = $this->assertEnvelopeSuccess($response, Response::HTTP_OK);
        self::assertIsArray($data['imported_pages'] ?? null);
        self::assertCount(20, $data['imported_pages']);

        /** @var list<array{keyword: string, page_id: int}> $importedPages */
        $importedPages = [];
        foreach ($data['imported_pages'] as $importedPage) {
            self::assertIsArray($importedPage);
            $pageId = $importedPage['page_id'] ?? null;
            $keyword = $importedPage['keyword'] ?? null;
            self::assertIsInt($pageId);
            self::assertIsString($keyword);
            $importedPages[] = ['keyword' => $keyword, 'page_id' => $pageId];
        }

        usort(
            $importedPages,
            static fn (array $a, array $b): int => strlen($b['keyword']) <=> strlen($a['keyword']),
        );

        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        foreach ($importedPages as $importedPage) {
            $deleteResponse = $this->jsonRequest(
                'DELETE',
                '/cms-api/v1/admin/pages/' . $importedPage['page_id'],
                null,
                $admin,
            );
            $this->assertEnvelopeSuccess($deleteResponse, Response::HTTP_OK);
            self::assertNull($pageRepo->find($importedPage['page_id']));
        }
    }

    public function testImportRollsBackEmbeddedPagesWhenLaterMenuImportFails(): void
    {
        $keywordPrefix = 'qa-nav-rollback-';
        $pageKeyword = $keywordPrefix . 'rollback-page';

        /** @var NavigationExportImportService $navExportImport */
        $navExportImport = self::getContainer()->get(NavigationExportImportService::class);

        $bundle = [
            'format' => NavigationExportImportService::BUNDLE_FORMAT,
            'version' => NavigationExportImportService::BUNDLE_VERSION,
            'menus' => [
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER => [
                    'items' => [
                        [
                            'ref' => 'rollback-home',
                            'parent_ref' => null,
                            'item_type' => 'page',
                            'position' => 10,
                            'is_active' => true,
                            'page_keyword' => 'rollback-page',
                        ],
                    ],
                ],
                'qa-nonexistent-navigation-menu-key' => [
                    'items' => [],
                ],
            ],
            'pages' => [
                [
                    'keyword' => 'rollback-page',
                    'surface' => 'public',
                    'page_access_type' => LookupService::PAGE_ACCESS_TYPES_WEB,
                    'headless' => false,
                    'open_access' => false,
                    'url' => '/rollback-page',
                    'routes' => [
                        [
                            'path_pattern' => '/rollback-page',
                            'is_canonical' => true,
                            'is_active' => true,
                            'priority' => 0,
                        ],
                    ],
                    'sections' => [
                        [
                            'style_name' => 'title',
                            'fields' => [
                                'content' => ['en-GB' => ['content' => 'Rollback test']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        self::assertNull($pageRepo->findOneBy(['keyword' => $pageKeyword]));

        try {
            $navExportImport->importBundle($bundle, [
                'keyword_prefix' => $keywordPrefix,
                'menu_policies' => [
                    LookupService::NAVIGATION_MENU_KEY_WEB_HEADER => NavigationExportImportService::POLICY_REPLACE,
                    'qa-nonexistent-navigation-menu-key' => NavigationExportImportService::POLICY_REPLACE,
                ],
            ]);
            self::fail('Expected navigation import to fail when a menu key does not exist.');
        } catch (ServiceException) {
            // Expected: requireMenuByKey fails after embedded pages were created.
        }

        self::assertNull($pageRepo->findOneBy(['keyword' => $pageKeyword]));
    }

    public function testPageBundleStillOmitsNavigation(): void
    {
        $admin = $this->loginAsQaAdmin();
        $page = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => 'qa_nav_page_only_export',
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/qa_nav_page_only_export',
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($page, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        /** @var PageExportImportService $pageExport */
        $pageExport = self::getContainer()->get(PageExportImportService::class);
        $bundle = $pageExport->exportBundle([$pageId]);
        self::assertArrayNotHasKey('navigation', $bundle);
        self::assertArrayNotHasKey('menus', $bundle);

        $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $pageId, null, $admin);
    }

    private function findMenuItemIdForPage(int $pageId): int
    {
        /** @var NavigationMenuItemRepository $itemRepo */
        $itemRepo = self::getContainer()->get(NavigationMenuItemRepository::class);
        $lookup = self::getContainer()->get(LookupService::class);
        self::assertInstanceOf(LookupService::class, $lookup);
        /** @var NavigationMenuRepository $menuRepo */
        $menuRepo = self::getContainer()->get(NavigationMenuRepository::class);
        $menu = $menuRepo->findByMenuKeyLookupId((int) $lookup->getLookupIdByCode(
                LookupService::NAVIGATION_MENU_KEYS,
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ));
        self::assertInstanceOf(\App\Entity\NavigationMenu::class, $menu);
        $item = $itemRepo->findActiveByMenuAndPageId($menu, $pageId);
        self::assertNotNull($item);
        $id = $item->getId();
        self::assertIsInt($id);

        return $id;
    }
}
