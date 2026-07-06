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
 * Web-header layer rules and per-menu preset validation (navigation overhaul):
 * `layer = 'top'` is limited to web_header root items without children, and
 * presets are validated against the menu key (header presets vs footer
 * presets vs none). Layer assignments survive preset switches.
 */
#[Group('integration')]
final class NavigationHeaderLayerAndPresetTest extends QaWebTestCase
{
    public function testTopLayerRootItemIsStoredAndResolvedInPublicPayload(): void
    {
        $admin = $this->loginAsQaAdmin();

        $created = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'external_url',
                'external_url' => 'https://example.test/qa-top-row',
                'label' => 'QA top row link',
                'layer' => 'top',
            ],
            $admin,
        );
        $data = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        /** @var array<string, mixed> $item */
        $item = $data['item'];
        self::assertSame('top', $item['layer']);
        $itemId = $item['id'];
        self::assertIsInt($itemId);

        /** @var NavigationMenuService $navigationMenuService */
        $navigationMenuService = self::getContainer()->get(NavigationMenuService::class);
        $navigationMenuService->invalidateNavigationCaches();

        $payload = $navigationMenuService->getPublicNavigationPayload(LookupService::PAGE_ACCESS_TYPES_WEB, 1);
        /** @var array<string, mixed> $menus */
        $menus = $payload['menus'];
        /** @var array<string, mixed> $webHeader */
        $webHeader = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER];
        $items = $webHeader['items'];
        self::assertIsArray($items);

        $resolved = null;
        foreach ($items as $node) {
            if (is_array($node) && is_numeric($node['id'] ?? null) && (int) $node['id'] === $itemId) {
                $resolved = $node;
                break;
            }
        }
        self::assertIsArray($resolved, 'Top-layer item should resolve in the public web_header tree');
        self::assertSame('top', $resolved['layer']);
    }

    public function testLayerIsRejectedOutsideWebHeaderRootItems(): void
    {
        $admin = $this->loginAsQaAdmin();

        // Non-header menu.
        $onFooter = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_FOOTER . '/items',
            [
                'item_type' => 'external_url',
                'external_url' => 'https://example.test/qa-footer-top',
                'label' => 'QA footer top attempt',
                'layer' => 'top',
            ],
            $admin,
        );
        $this->assertEnvelope400($onFooter);

        // Child items cannot carry a layer.
        $group = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'group',
                'label' => 'QA layer parent group',
            ],
            $admin,
        );
        $groupData = $this->assertEnvelopeSuccess($group, Response::HTTP_CREATED);
        /** @var array<string, mixed> $groupItem */
        $groupItem = $groupData['item'];
        $groupItemId = $groupItem['id'];
        self::assertIsInt($groupItemId);

        $childWithLayer = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'external_url',
                'external_url' => 'https://example.test/qa-child-top',
                'label' => 'QA child top attempt',
                'parent_item_id' => $groupItemId,
                'layer' => 'top',
            ],
            $admin,
        );
        $this->assertEnvelope400($childWithLayer);
    }

    public function testTopLayerItemsAreFlatLinks(): void
    {
        $admin = $this->loginAsQaAdmin();

        $top = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'external_url',
                'external_url' => 'https://example.test/qa-flat-top',
                'label' => 'QA flat top link',
                'layer' => 'top',
            ],
            $admin,
        );
        $topData = $this->assertEnvelopeSuccess($top, Response::HTTP_CREATED);
        /** @var array<string, mixed> $topItem */
        $topItem = $topData['item'];
        $topItemId = $topItem['id'];
        self::assertIsInt($topItemId);

        // Nothing may nest under a top-row link.
        $nested = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'external_url',
                'external_url' => 'https://example.test/qa-nested-under-top',
                'label' => 'QA nested under top',
                'parent_item_id' => $topItemId,
            ],
            $admin,
        );
        $this->assertEnvelope400($nested);

        // An item with children cannot be moved to the top row.
        $parent = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'group',
                'label' => 'QA group with child',
            ],
            $admin,
        );
        $parentData = $this->assertEnvelopeSuccess($parent, Response::HTTP_CREATED);
        /** @var array<string, mixed> $parentItem */
        $parentItem = $parentData['item'];
        $parentItemId = $parentItem['id'];
        self::assertIsInt($parentItemId);

        $child = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'external_url',
                'external_url' => 'https://example.test/qa-group-child',
                'label' => 'QA group child',
                'parent_item_id' => $parentItemId,
            ],
            $admin,
        );
        $this->assertEnvelopeSuccess($child, Response::HTTP_CREATED);

        $promote = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/items/' . $parentItemId,
            ['layer' => 'top'],
            $admin,
        );
        $this->assertEnvelope400($promote);
    }

    public function testPresetValidationIsMenuKeySpecific(): void
    {
        $admin = $this->loginAsQaAdmin();

        // Footer preset on the header menu is rejected.
        $columnsOnHeader = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ['preset' => LookupService::NAVIGATION_PRESET_COLUMNS],
            $admin,
        );
        $this->assertEnvelope400($columnsOnHeader);

        // Mobile menus support no presets at all.
        $presetOnDrawer = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER,
            ['preset' => LookupService::NAVIGATION_PRESET_INLINE],
            $admin,
        );
        $this->assertEnvelope400($presetOnDrawer);

        // Footer accepts its own presets.
        $inlineOnFooter = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_FOOTER,
            ['preset' => LookupService::NAVIGATION_PRESET_INLINE],
            $admin,
        );
        $footerData = $this->assertEnvelopeSuccess($inlineOnFooter);
        self::assertSame(LookupService::NAVIGATION_PRESET_INLINE, $footerData['preset']);
    }

    public function testLayerSurvivesHeaderPresetSwitch(): void
    {
        $admin = $this->loginAsQaAdmin();

        $created = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'external_url',
                'external_url' => 'https://example.test/qa-preset-switch',
                'label' => 'QA preset switch link',
                'layer' => 'top',
            ],
            $admin,
        );
        $createdData = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        /** @var array<string, mixed> $createdItem */
        $createdItem = $createdData['item'];
        $itemId = $createdItem['id'];
        self::assertIsInt($itemId);

        // Single-row preset: layer data must remain untouched.
        $single = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ['preset' => 'simple'],
            $admin,
        );
        $this->assertEnvelopeSuccess($single);
        self::assertSame('top', $this->fetchAdminItemLayer($itemId, $admin));

        // Back to a double preset: the stored split is restored, not rebuilt.
        $double = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ['preset' => 'double-dropdown'],
            $admin,
        );
        $doubleData = $this->assertEnvelopeSuccess($double);
        self::assertSame('double-dropdown', $doubleData['preset']);
        self::assertSame('top', $this->fetchAdminItemLayer($itemId, $admin));
    }

    private function fetchAdminItemLayer(int $itemId, string $adminToken): ?string
    {
        $overview = $this->jsonRequest('GET', '/cms-api/v1/admin/navigation', null, $adminToken);
        $data = $this->assertEnvelopeSuccess($overview);
        /** @var array<string, mixed> $menus */
        $menus = $data['menus'];
        /** @var array<string, mixed> $header */
        $header = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER];
        $items = $header['items'];
        self::assertIsArray($items);
        foreach ($items as $item) {
            if (is_array($item) && is_numeric($item['id'] ?? null) && (int) $item['id'] === $itemId) {
                $layer = $item['layer'] ?? null;

                return is_string($layer) ? $layer : null;
            }
        }
        self::fail(sprintf('Menu item %d not found in admin web_header items.', $itemId));
    }
}
