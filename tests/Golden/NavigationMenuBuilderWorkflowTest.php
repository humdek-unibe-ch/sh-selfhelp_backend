<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Entity\NavigationMenuItem;
use App\Entity\Page;
use App\Repository\NavigationMenuItemRepository;
use App\Repository\PageRepository;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Golden menu-builder workflow: create page with navigation assignments,
 * resolve public payload, then clean up.
 */
#[Group('golden')]
final class NavigationMenuBuilderWorkflowTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_nav_menu_builder_workflow';

    protected function tearDown(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        $page = $pageRepo->findOneBy(['keyword' => self::KEYWORD]);
        if ($page instanceof Page) {
            $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
        }

        parent::tearDown();
    }

    public function testCreateAssignAndResolvePublicNavigation(): void
    {
        $admin = $this->loginAsQaAdmin();

        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::KEYWORD,
            'navigationAssignments' => [
                ['menuKey' => LookupService::NAVIGATION_MENU_KEY_WEB_HEADER],
            ],
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        /** @var NavigationMenuItemRepository $itemRepo */
        $itemRepo = self::getContainer()->get(NavigationMenuItemRepository::class);
        $items = $itemRepo->findBy(['page' => $pageId, 'isActive' => true]);
        self::assertNotEmpty($items);
        self::assertInstanceOf(NavigationMenuItem::class, $items[0]);

        $navigation = $this->jsonRequest('GET', '/cms-api/v1/navigation?language_id=1', null, $admin);
        $payload = $this->assertEnvelopeSuccess($navigation);
        $menus = $payload['menus'] ?? null;
        self::assertIsArray($menus);
        $header = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER] ?? null;
        self::assertIsArray($header);
        $itemsJson = $header['items'] ?? null;
        self::assertIsArray($itemsJson);

        $found = false;
        $walk = function (array $nodes) use (&$walk, &$found, $pageId): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $page = $node['page'] ?? null;
                if (!is_array($page)) {
                    continue;
                }
                $pageIdFromNode = $page['id'] ?? null;
                if (is_numeric($pageIdFromNode) && (int) $pageIdFromNode === $pageId) {
                    $found = true;
                    return;
                }
                $children = $node['children'] ?? [];
                if (is_array($children) && $children !== []) {
                    $walk($children);
                }
            }
        };
        $walk($itemsJson);
        self::assertTrue($found, 'Created page should appear in resolved web_header menu');
    }
}
