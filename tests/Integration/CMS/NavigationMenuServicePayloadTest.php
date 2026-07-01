<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Service\CMS\NavigationMenuService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class NavigationMenuServicePayloadTest extends QaWebTestCase
{
    public function testPublicNavigationPayloadIncludesAllSystemMenus(): void
    {
        $this->loginAsQaGuest();

        /** @var NavigationMenuService $service */
        $service = self::getContainer()->get(NavigationMenuService::class);
        $payload = $service->getPublicNavigationPayload(LookupService::PAGE_ACCESS_TYPES_WEB, 1);

        self::assertArrayHasKey('menus', $payload);
        self::assertArrayHasKey('startup', $payload);
        self::assertArrayHasKey('search', $payload);

        $menus = $payload['menus'];
        self::assertIsArray($menus);

        foreach ([
            LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            LookupService::NAVIGATION_MENU_KEY_WEB_FOOTER,
            LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER,
            LookupService::NAVIGATION_MENU_KEY_MOBILE_BOTTOM_TABS,
        ] as $menuKey) {
            self::assertArrayHasKey($menuKey, $menus);
            $menu = $menus[$menuKey];
            self::assertIsArray($menu);
            self::assertArrayHasKey('items', $menu);
        }

        $search = $payload['search'];
        self::assertIsArray($search);
        self::assertSame('content_index', $search['mode'] ?? null);
    }
}
