<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Soft over-limit: public GET /navigation truncates web_header roots; admin keeps all.
 */
#[Group('integration')]
final class AdminNavigationMenuItemLimitPublicTruncateTest extends QaWebTestCase
{
    public function testPublicHeaderRespectsItemLimitWhileAdminKeepsAllRoots(): void
    {
        $admin = $this->loginAsQaAdmin();
        $suffix = bin2hex(random_bytes(4));

        $pageIds = [];
        for ($i = 0; $i < 4; ++$i) {
            $pageIds[] = $this->createOpenPage(sprintf('navlim-%s-%d', $suffix, $i), $admin);
        }

        foreach ($pageIds as $pageId) {
            $create = $this->jsonRequest(
                'POST',
                '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
                ['item_type' => 'page', 'page_id' => $pageId],
                $admin,
            );
            $this->assertEnvelopeSuccess($create, Response::HTTP_CREATED);
        }

        $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ['item_limit' => 3],
            $admin,
        );

        $adminOverview = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/navigation', null, $admin),
        );
        $adminMenus = $adminOverview['menus'] ?? null;
        self::assertIsArray($adminMenus);
        $adminHeader = $adminMenus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER] ?? null;
        self::assertIsArray($adminHeader);
        $adminRoots = $this->rootItems($adminHeader['items'] ?? null);
        self::assertGreaterThanOrEqual(
            4,
            count($adminRoots),
            'Admin overview must keep every stored root (soft over-limit).',
        );

        $public = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/navigation', null, $admin),
        );
        $publicMenus = $public['menus'] ?? null;
        self::assertIsArray($publicMenus);
        $publicHeader = $publicMenus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER] ?? null;
        self::assertIsArray($publicHeader);
        self::assertSame(3, $publicHeader['item_limit'] ?? null);
        $publicRoots = $publicHeader['items'] ?? null;
        self::assertIsArray($publicRoots);
        self::assertLessThanOrEqual(
            3,
            count($publicRoots),
            'Public navigation must truncate web_header to item_limit.',
        );

        // Restore a finite default so shared QA DBs stay predictable.
        $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ['item_limit' => 8],
            $admin,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rootItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $roots = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Admin overview is a flat list with parent_item_id; public is already roots.
            if (($item['parent_item_id'] ?? null) === null) {
                $normalized = [];
                foreach ($item as $key => $value) {
                    if (is_string($key)) {
                        $normalized[$key] = $value;
                    }
                }
                $roots[] = $normalized;
            }
        }

        return $roots;
    }

    private function createOpenPage(string $keyword, string $token): int
    {
        $create = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => $keyword,
            'url' => '/' . $keyword,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB,
            'openAccess' => true,
            'headless' => false,
        ], $token);
        $pageData = $this->assertEnvelopeSuccess($create, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        return $pageId;
    }
}
