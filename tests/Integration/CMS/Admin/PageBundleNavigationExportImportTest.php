<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS\Admin;

use App\Entity\Page;
use App\Repository\NavigationMenuItemRepository;
use App\Repository\PageRepository;
use App\Service\CMS\Admin\PageExportImportService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('integration')]
final class PageBundleNavigationExportImportTest extends QaWebTestCase
{
    private const PARENT_KEYWORD = 'qa_bundle_nav_parent';
    private const CHILD_KEYWORD = 'qa_bundle_nav_child';

    protected function tearDown(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        foreach ([self::CHILD_KEYWORD, self::PARENT_KEYWORD, self::CHILD_KEYWORD . '_imp', self::PARENT_KEYWORD . '_imp'] as $keyword) {
            $page = $pageRepo->findOneBy(['keyword' => $keyword]);
            if ($page instanceof Page) {
                $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
            }
        }
        parent::tearDown();
    }

    public function testExportImportRoundTripsNavigationAssignments(): void
    {
        $admin = $this->loginAsQaAdmin();

        $parent = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::PARENT_KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::PARENT_KEYWORD,
            'navigationAssignments' => [
                ['menuKey' => LookupService::NAVIGATION_MENU_KEY_WEB_HEADER, 'childSource' => LookupService::NAVIGATION_CHILD_SOURCE_PAGE_CHILDREN],
            ],
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

        /** @var PageExportImportService $exportImport */
        $exportImport = self::getContainer()->get(PageExportImportService::class);
        $bundle = $exportImport->exportBundle([$parentId, $childId]);
        self::assertSame(PageExportImportService::BUNDLE_VERSION, $bundle['version']);
        $navigation = $bundle['navigation'] ?? null;
        self::assertIsArray($navigation);
        $assignments = $navigation['assignments'] ?? null;
        self::assertIsArray($assignments);
        self::assertNotEmpty($assignments);

        $pages = $bundle['pages'] ?? null;
        self::assertIsArray($pages);

        foreach ($pages as &$page) {
            self::assertIsArray($page);
            $keywordValue = $page['keyword'] ?? '';
            $keyword = is_string($keywordValue) ? $keywordValue : '';
            $newKeyword = str_ends_with($keyword, '_imp') ? $keyword : $keyword . '_imp';
            $page['keyword'] = $newKeyword;
            if (is_string($page['parent_keyword'] ?? null) && $page['parent_keyword'] !== '') {
                $page['parent_keyword'] = $page['parent_keyword'] . '_imp';
            }
        }
        unset($page);

        foreach ($pages as &$page) {
            self::assertIsArray($page);
            $keywordValue = $page['keyword'] ?? '';
            $keyword = is_string($keywordValue) ? $keywordValue : '';
            $rawKeyword = str_ends_with($keyword, '_imp') ? substr($keyword, 0, -4) : $keyword;
            if (is_string($page['url'] ?? null) && $page['url'] !== '') {
                $page['url'] = str_replace($rawKeyword, $keyword, (string) $page['url']);
            }
            if (is_array($page['routes'] ?? null)) {
                foreach ($page['routes'] as &$route) {
                    if (!is_array($route)) {
                        continue;
                    }
                    $patternValue = $route['path_pattern'] ?? '';
                    $pattern = is_string($patternValue) ? $patternValue : '';
                    if ($pattern !== '') {
                        $route['path_pattern'] = str_replace($rawKeyword, $keyword, $pattern);
                    }
                }
                unset($route);
            }
        }
        unset($page);
        $bundle['pages'] = $pages;

        foreach ($assignments as &$assignment) {
            self::assertIsArray($assignment);
            $pageKeywordValue = $assignment['page_keyword'] ?? '';
            $assignment['page_keyword'] = (is_string($pageKeywordValue) ? $pageKeywordValue : '') . '_imp';
            if (isset($assignment['parent_page_keyword']) && is_string($assignment['parent_page_keyword'])) {
                $assignment['parent_page_keyword'] = $assignment['parent_page_keyword'] . '_imp';
            }
            if (isset($assignment['excluded_page_keywords']) && is_array($assignment['excluded_page_keywords'])) {
                $assignment['excluded_page_keywords'] = array_map(
                    static fn (mixed $kw): string => is_string($kw) ? $kw . '_imp' : '',
                    $assignment['excluded_page_keywords'],
                );
            }
        }
        unset($assignment);
        /** @var array{assignments: list<array<string, mixed>>} $navigationBlock */
        $navigationBlock = is_array($bundle['navigation'] ?? null) ? $bundle['navigation'] : ['assignments' => []];
        $navigationBlock['assignments'] = $assignments;
        $bundle['navigation'] = $navigationBlock;

        $validation = $exportImport->validateImport($bundle);
        self::assertFalse($validation['valid'] === false && $validation['issues'] === [], 'Expected validation metadata');
        self::assertTrue(
            $validation['valid'],
            'Import validation issues: ' . json_encode($validation['issues'], JSON_THROW_ON_ERROR),
        );

        $result = $exportImport->importBundle($bundle);
        self::assertNotEmpty($result['created']);

        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        $importedParent = $pageRepo->findOneBy(['keyword' => self::PARENT_KEYWORD . '_imp']);
        self::assertInstanceOf(Page::class, $importedParent);

        /** @var NavigationMenuItemRepository $itemRepo */
        $itemRepo = self::getContainer()->get(NavigationMenuItemRepository::class);
        $items = $itemRepo->findBy(['page' => $importedParent, 'isActive' => true]);
        self::assertNotEmpty($items);
    }
}
