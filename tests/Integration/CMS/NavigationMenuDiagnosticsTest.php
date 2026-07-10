<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Service\CMS\NavigationMenuService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration checks for navigation menu resolution helpers wired in NavigationMenuService.
 */
#[Group('integration')]
final class NavigationMenuDiagnosticsTest extends QaKernelTestCase
{
    public function testPublicMobileBottomTabsRespectItemLimit(): void
    {
        $service = self::getContainer()->get(NavigationMenuService::class);
        self::assertInstanceOf(NavigationMenuService::class, $service);

        $payload = $service->getPublicNavigationPayload(LookupService::PAGE_ACCESS_TYPES_MOBILE, 1);
        $menus = $payload['menus'] ?? null;
        self::assertIsArray($menus);
        $tabs = $menus[LookupService::NAVIGATION_MENU_KEY_MOBILE_BOTTOM_TABS] ?? null;
        self::assertIsArray($tabs);

        $limit = $tabs['item_limit'] ?? null;
        $items = $tabs['items'] ?? [];
        self::assertIsArray($items);

        if (is_int($limit) && $limit > 0) {
            self::assertLessThanOrEqual($limit, count($items));
        }
    }

    public function testAdminDiagnosticsReturnsStructuredArrays(): void
    {
        $service = self::getContainer()->get(NavigationMenuService::class);
        self::assertInstanceOf(NavigationMenuService::class, $service);

        $diagnostics = $service->getAdminMenuDiagnostics(LookupService::NAVIGATION_MENU_KEY_WEB_HEADER, 1);

        self::assertArrayHasKey('warnings', $diagnostics);
        self::assertArrayHasKey('suggestions', $diagnostics);
    }
}
