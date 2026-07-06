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
use Symfony\Component\HttpFoundation\Response;

/**
 * Pager toggle + branding (navigation overhaul): web menus carry a
 * `show_pager` default that parent items may override per branch (NULL =
 * inherit), and the navigation settings expose the global branding block
 * (logo asset, alt text, logo link page) that the public payload serves to
 * the web header and mobile drawer.
 */
#[Group('integration')]
final class NavigationPagerAndBrandingTest extends QaWebTestCase
{
    public function testMenuLevelPagerToggleRoundTripsToPublicPayload(): void
    {
        $admin = $this->loginAsQaAdmin();

        $updated = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ['show_pager' => false],
            $admin,
        );
        $menuData = $this->assertEnvelopeSuccess($updated);
        self::assertFalse($menuData['show_pager']);

        /** @var NavigationMenuService $navigationMenuService */
        $navigationMenuService = self::getContainer()->get(NavigationMenuService::class);
        $payload = $navigationMenuService->getPublicNavigationPayload(LookupService::PAGE_ACCESS_TYPES_WEB, 1);
        /** @var array<string, mixed> $menus */
        $menus = $payload['menus'];
        /** @var array<string, mixed> $header */
        $header = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER];
        self::assertFalse($header['show_pager']);

        $restored = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ['show_pager' => true],
            $admin,
        );
        $restoredData = $this->assertEnvelopeSuccess($restored);
        self::assertTrue($restoredData['show_pager']);
    }

    public function testParentItemPagerOverrideResolvesInPublicPayload(): void
    {
        $admin = $this->loginAsQaAdmin();

        $created = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'group',
                'label' => 'QA pager override group',
                'show_pager' => false,
            ],
            $admin,
        );
        $data = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        /** @var array<string, mixed> $item */
        $item = $data['item'];
        self::assertFalse($item['show_pager']);
        $itemId = $item['id'];
        self::assertIsInt($itemId);

        // Childless groups are pruned from the public tree; give it a child.
        $child = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'external_url',
                'external_url' => 'https://example.test/qa-pager-child',
                'label' => 'QA pager child',
                'parent_item_id' => $itemId,
            ],
            $admin,
        );
        $this->assertEnvelopeSuccess($child, Response::HTTP_CREATED);

        /** @var NavigationMenuService $navigationMenuService */
        $navigationMenuService = self::getContainer()->get(NavigationMenuService::class);
        $navigationMenuService->invalidateNavigationCaches();
        $payload = $navigationMenuService->getPublicNavigationPayload(LookupService::PAGE_ACCESS_TYPES_WEB, 1);
        /** @var array<string, mixed> $menus */
        $menus = $payload['menus'];
        /** @var array<string, mixed> $header */
        $header = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER];
        $items = $header['items'];
        self::assertIsArray($items);

        $resolved = null;
        foreach ($items as $node) {
            if (is_array($node) && is_numeric($node['id'] ?? null) && (int) $node['id'] === $itemId) {
                $resolved = $node;
                break;
            }
        }
        self::assertIsArray($resolved, 'Override group should resolve in the public web_header tree');
        self::assertFalse($resolved['show_pager']);

        // Items without an override inherit: their show_pager stays null.
        $children = $resolved['children'];
        self::assertIsArray($children);
        self::assertNotSame([], $children);
        $firstChild = $children[0];
        self::assertIsArray($firstChild);
        self::assertNull($firstChild['show_pager']);
    }

    public function testBrandingSettingsRoundTripToPublicPayload(): void
    {
        $admin = $this->loginAsQaAdmin();

        $settingsResponse = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/settings',
            [
                'logo_asset_path' => '/assets/qa-logo.svg',
                'logo_alt' => 'QA Brand',
            ],
            $admin,
        );
        $settingsData = $this->assertEnvelopeSuccess($settingsResponse);
        self::assertSame('/assets/qa-logo.svg', $settingsData['logo_asset_path']);
        self::assertSame('QA Brand', $settingsData['logo_alt']);
        self::assertArrayHasKey('logo_link_page_id', $settingsData);

        /** @var NavigationMenuService $navigationMenuService */
        $navigationMenuService = self::getContainer()->get(NavigationMenuService::class);
        $navigationMenuService->invalidateNavigationCaches();
        $payload = $navigationMenuService->getPublicNavigationPayload(LookupService::PAGE_ACCESS_TYPES_WEB, 1);
        /** @var array<string, mixed> $branding */
        $branding = $payload['branding'];
        self::assertSame('/assets/qa-logo.svg', $branding['logo_url']);
        self::assertSame('QA Brand', $branding['logo_alt']);

        // Clearing restores the text fallback (nulls).
        $cleared = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/settings',
            ['logo_asset_path' => null, 'logo_alt' => null],
            $admin,
        );
        $clearedData = $this->assertEnvelopeSuccess($cleared);
        self::assertNull($clearedData['logo_asset_path']);
        self::assertNull($clearedData['logo_alt']);
    }
}
