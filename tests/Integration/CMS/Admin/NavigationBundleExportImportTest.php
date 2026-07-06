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
        self::assertIsArray($data['imported_menus'] ?? null);
        self::assertEqualsCanonicalizing(
            [
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
                LookupService::NAVIGATION_MENU_KEY_WEB_FOOTER,
                LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER,
                LookupService::NAVIGATION_MENU_KEY_MOBILE_BOTTOM_TABS,
            ],
            $data['imported_menus'],
        );

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

        // Regression (issue: "imported pages have no ACL access"): every embedded
        // page must come out of the import with full admin-group ACL by default.
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $adminGroup = $em->getRepository(\App\Entity\Group::class)->findOneBy(['name' => 'admin']);
        self::assertNotNull($adminGroup);
        foreach ($importedPages as $importedPage) {
            $acl = $em->getRepository(PageAclGroup::class)->findOneBy([
                'page' => $importedPage['page_id'],
                'group' => $adminGroup,
            ]);
            self::assertInstanceOf(PageAclGroup::class, $acl, 'Missing admin ACL for ' . $importedPage['keyword']);
            self::assertTrue($acl->isAclSelect(), 'Admin should read ' . $importedPage['keyword']);
            self::assertTrue($acl->isAclInsert(), 'Admin should insert ' . $importedPage['keyword']);
            self::assertTrue($acl->isAclUpdate(), 'Admin should update ' . $importedPage['keyword']);
            self::assertTrue($acl->isAclDelete(), 'Admin should delete ' . $importedPage['keyword']);
        }

        // Regression (issue: "pages were not wrapped in the navigation"): the
        // public navigation payload — the exact payload web + mobile consume —
        // must contain the imported pages inside the imported menu structure.
        $navEnvelope = $this->jsonRequest('GET', '/cms-api/v1/navigation', null, $admin);
        $navData = $this->assertEnvelopeSuccess($navEnvelope, Response::HTTP_OK);
        $menus = $navData['menus'] ?? null;
        self::assertIsArray($menus);

        $headerMenu = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER] ?? null;
        self::assertIsArray($headerMenu);
        self::assertIsArray($headerMenu['items'] ?? null);
        $headerKeywords = $this->collectPageKeywordsFromPayloadItems($headerMenu['items']);
        self::assertContains($keywordPrefix . 'demo-home', $headerKeywords);
        // The "Resources" main-row group carries its pages as dropdown children.
        self::assertContains($keywordPrefix . 'demo-resources', $headerKeywords);
        self::assertContains($keywordPrefix . 'demo-blog', $headerKeywords);

        $footerMenu = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_FOOTER] ?? null;
        self::assertIsArray($footerMenu);
        self::assertIsArray($footerMenu['items'] ?? null);
        $footerExternalUrls = $this->collectExternalUrlsFromPayloadItems($footerMenu['items']);
        self::assertContains('https://status.example.org', $footerExternalUrls);

        // Bottom tabs: the "More" group holder tab keeps FAQ + Contact as
        // children within the 5-tab limit.
        $tabsMenu = $menus[LookupService::NAVIGATION_MENU_KEY_MOBILE_BOTTOM_TABS] ?? null;
        self::assertIsArray($tabsMenu);
        self::assertIsArray($tabsMenu['items'] ?? null);
        $holderChildren = [];
        foreach ($tabsMenu['items'] as $tabItem) {
            if (is_array($tabItem) && ($tabItem['item_type'] ?? null) === 'group') {
                $holderChildren = $this->collectPageKeywordsFromPayloadItems(
                    is_array($tabItem['children'] ?? null) ? $tabItem['children'] : [],
                );
            }
        }
        self::assertContains($keywordPrefix . 'demo-faq', $holderChildren);
        self::assertContains($keywordPrefix . 'demo-contact', $holderChildren);

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

    public function testLegacyV1BundleIsRejectedWithClearError(): void
    {
        /** @var NavigationExportImportService $navExportImport */
        $navExportImport = self::getContainer()->get(NavigationExportImportService::class);

        $legacyBundle = [
            'format' => NavigationExportImportService::BUNDLE_FORMAT,
            'version' => '1.0',
            'menus' => [
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER => ['items' => []],
            ],
        ];

        $validation = $navExportImport->validateImport($legacyBundle, []);
        self::assertFalse($validation['valid']);
        $versionIssues = array_values(array_filter(
            $validation['issues'],
            static fn (array $issue): bool => $issue['code'] === 'unsupported_version',
        ));
        self::assertCount(1, $versionIssues);
        self::assertStringContainsString(
            'only "2.0" bundles can be imported',
            $versionIssues[0]['message'],
        );

        $this->expectException(ServiceException::class);
        $navExportImport->importBundle($legacyBundle, []);
    }

    public function testTopLayerRoundTripsThroughExportImport(): void
    {
        $admin = $this->loginAsQaAdmin();

        $created = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'external_url',
                'external_url' => 'https://example.test/qa-layer-round-trip',
                'label' => 'QA layer round trip',
                'layer' => 'top',
                'children_nav' => 'pills',
            ],
            $admin,
        );
        $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);

        // Menu-level branch presentation must survive the round trip too.
        $menuUpdate = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ['children_nav' => 'none', 'show_breadcrumbs' => false],
            $admin,
        );
        $this->assertEnvelopeSuccess($menuUpdate);

        /** @var NavigationExportImportService $navExportImport */
        $navExportImport = self::getContainer()->get(NavigationExportImportService::class);
        $bundle = $navExportImport->exportBundle([
            'mode' => NavigationExportImportService::EXPORT_MODE_FULL_SNAPSHOT,
            'menu_keys' => [LookupService::NAVIGATION_MENU_KEY_WEB_HEADER],
        ]);

        $menus = $bundle['menus'];
        self::assertIsArray($menus);
        $header = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER] ?? null;
        self::assertIsArray($header);
        self::assertSame('none', $header['children_nav'] ?? null);
        self::assertFalse($header['show_breadcrumbs'] ?? null);
        $items = $header['items'] ?? null;
        self::assertIsArray($items);
        $exportedTop = array_values(array_filter(
            $items,
            static fn (mixed $item): bool => is_array($item)
                && ($item['external_url'] ?? null) === 'https://example.test/qa-layer-round-trip',
        ));
        self::assertCount(1, $exportedTop);
        self::assertSame('top', $exportedTop[0]['layer']);
        self::assertSame('pills', $exportedTop[0]['children_nav']);

        // Reset the menu default so the import has to restore it.
        $menuReset = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ['children_nav' => null, 'show_breadcrumbs' => true],
            $admin,
        );
        $this->assertEnvelopeSuccess($menuReset);

        // Re-acquire the service after the HTTP reset so its EntityManager
        // sees the reset row (client requests reboot the kernel container).
        /** @var NavigationExportImportService $navExportImport */
        $navExportImport = self::getContainer()->get(NavigationExportImportService::class);
        $navExportImport->importBundle($bundle, [
            'menu_policies' => [
                LookupService::NAVIGATION_MENU_KEY_WEB_HEADER => NavigationExportImportService::POLICY_REPLACE,
            ],
        ]);

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $em->clear();

        /** @var NavigationMenuItemRepository $itemRepo */
        $itemRepo = self::getContainer()->get(NavigationMenuItemRepository::class);
        $reimported = $itemRepo->findOneBy(['externalUrl' => 'https://example.test/qa-layer-round-trip']);
        self::assertNotNull($reimported);
        self::assertSame('top', $reimported->getLayer());
        self::assertSame('pills', $reimported->getChildrenNav()?->getLookupCode());

        $menuAfter = $reimported->getNavigationMenu();
        self::assertNotNull($menuAfter);
        self::assertSame('none', $menuAfter->getChildrenNav()?->getLookupCode());
        self::assertFalse($menuAfter->isShowBreadcrumbs());
    }

    public function testExportEmbedsAncestorChainOfMenuReferencedPages(): void
    {
        $admin = $this->loginAsQaAdmin();

        // Structural parent page that is NOT linked from any menu item.
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

        // Only the CHILD goes into the menu; the parent is menu-invisible.
        $created = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            ['item_type' => 'page', 'page_id' => $childId],
            $admin,
        );
        $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);

        /** @var NavigationExportImportService $navExportImport */
        $navExportImport = self::getContainer()->get(NavigationExportImportService::class);
        $bundle = $navExportImport->exportBundle([
            'mode' => NavigationExportImportService::EXPORT_MODE_BRANCH,
            'page_keywords' => [self::CHILD_KEYWORD],
            'include_pages' => true,
            'menu_keys' => [LookupService::NAVIGATION_MENU_KEY_WEB_HEADER],
        ]);

        $pages = $bundle['pages'] ?? null;
        self::assertIsArray($pages);
        $keywords = array_values(array_filter(array_map(
            static fn (mixed $page): ?string => is_array($page) && is_string($page['keyword'] ?? null) ? $page['keyword'] : null,
            $pages,
        )));
        self::assertContains(self::CHILD_KEYWORD, $keywords);
        self::assertContains(
            self::PARENT_KEYWORD,
            $keywords,
            'Menu-invisible ancestor pages must be embedded so the exported bundle is self-contained.',
        );

        // Re-importing the export under a fresh prefix must validate cleanly
        // (before the ancestor embedding this failed with missing_parent).
        $validation = $navExportImport->validateImport($bundle, [
            'keyword_prefix' => 'qa_rt_',
            'route_prefix' => '/qa_rt',
        ]);
        self::assertTrue($validation['valid'], json_encode($validation['issues'], JSON_THROW_ON_ERROR));
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

    /**
     * Collect every page keyword reachable in a public navigation payload item
     * tree (roots + nested children).
     *
     * @param array<array-key, mixed> $items
     *
     * @return list<string>
     */
    private function collectPageKeywordsFromPayloadItems(array $items): array
    {
        $keywords = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $page = $item['page'] ?? null;
            if (is_array($page) && is_string($page['keyword'] ?? null) && $page['keyword'] !== '') {
                $keywords[] = $page['keyword'];
            }
            $children = $item['children'] ?? null;
            if (is_array($children) && $children !== []) {
                foreach ($this->collectPageKeywordsFromPayloadItems($children) as $childKeyword) {
                    $keywords[] = $childKeyword;
                }
            }
        }

        return $keywords;
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return list<string>
     */
    private function collectExternalUrlsFromPayloadItems(array $items): array
    {
        $urls = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (is_string($item['external_url'] ?? null) && $item['external_url'] !== '') {
                $urls[] = $item['external_url'];
            }
            $children = $item['children'] ?? null;
            if (is_array($children) && $children !== []) {
                foreach ($this->collectExternalUrlsFromPayloadItems($children) as $childUrl) {
                    $urls[] = $childUrl;
                }
            }
        }

        return $urls;
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
