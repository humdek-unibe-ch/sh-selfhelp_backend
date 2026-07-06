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

            // Strict menu contract: typed keys always present, no config blob.
            foreach (['key', 'platform', 'surface', 'preset', 'max_depth', 'item_limit', 'children_nav', 'show_breadcrumbs', 'items'] as $requiredKey) {
                self::assertArrayHasKey($requiredKey, $menu, sprintf('%s.%s', $menuKey, $requiredKey));
            }
            self::assertArrayNotHasKey('config', $menu);
            self::assertSame($menuKey, $menu['key']);

            $items = $menu['items'];
            self::assertIsArray($items);
            $this->assertStrictItemShape($items, $menuKey);
        }

        $header = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER];
        self::assertIsArray($header);
        self::assertContains($header['preset'], LookupService::NAVIGATION_HEADER_PRESETS);
        // Web menus resolve branch presentation: platform default is sidebar
        // with breadcrumbs on; mobile menus carry neutral values.
        self::assertContains($header['children_nav'], LookupService::NAVIGATION_CHILDREN_NAV_MODE_CODES);
        self::assertIsBool($header['show_breadcrumbs']);
        $footer = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_FOOTER];
        self::assertIsArray($footer);
        self::assertContains($footer['preset'], LookupService::NAVIGATION_FOOTER_PRESETS);
        $drawer = $menus[LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER];
        self::assertIsArray($drawer);
        self::assertNull($drawer['children_nav']);
        self::assertFalse($drawer['show_breadcrumbs']);

        $search = $payload['search'];
        self::assertIsArray($search);
        self::assertSame('content_index', $search['mode'] ?? null);
    }

    /**
     * Every resolved item carries the full strict key set; absent values are
     * null keys, never missing keys.
     *
     * @param array<int|string, mixed> $items
     */
    private function assertStrictItemShape(array $items, string $menuKey): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ([
                'id',
                'item_type',
                'label',
                'description',
                'aria_label',
                'icon',
                'mobile_icon',
                'position',
                'layer',
                'children_nav',
                'external_url',
                'page',
                'is_active',
                'children',
            ] as $requiredKey) {
                self::assertArrayHasKey(
                    $requiredKey,
                    $item,
                    sprintf('menu %s item %s misses key %s', $menuKey, json_encode($item['id'] ?? '?'), $requiredKey),
                );
            }
            $children = $item['children'];
            self::assertIsArray($children);
            $this->assertStrictItemShape($children, $menuKey);
        }
    }
}
