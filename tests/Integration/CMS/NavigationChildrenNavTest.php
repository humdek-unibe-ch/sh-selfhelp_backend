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
 * Children-navigation presentation (navigation overhaul): web menus carry a
 * `children_nav` default (`sidebar` / `pills` / `none`, NULL resolving to
 * `sidebar`) plus `show_breadcrumbs`, and parent items may override the menu
 * default per branch. Mobile menus reject the field (native presentation).
 */
#[Group('integration')]
final class NavigationChildrenNavTest extends QaWebTestCase
{
    public function testMenuLevelChildrenNavAndBreadcrumbsRoundTripToPublicPayload(): void
    {
        $admin = $this->loginAsQaAdmin();

        $updated = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ['children_nav' => 'pills', 'show_breadcrumbs' => false],
            $admin,
        );
        $menuData = $this->assertEnvelopeSuccess($updated);
        self::assertSame('pills', $menuData['children_nav']);
        self::assertFalse($menuData['show_breadcrumbs']);

        /** @var NavigationMenuService $navigationMenuService */
        $navigationMenuService = self::getContainer()->get(NavigationMenuService::class);
        $payload = $navigationMenuService->getPublicNavigationPayload(LookupService::PAGE_ACCESS_TYPES_WEB, 1);
        /** @var array<string, mixed> $menus */
        $menus = $payload['menus'];
        /** @var array<string, mixed> $header */
        $header = $menus[LookupService::NAVIGATION_MENU_KEY_WEB_HEADER];
        self::assertSame('pills', $header['children_nav']);
        self::assertFalse($header['show_breadcrumbs']);

        // Clearing the override resolves back to the platform default.
        $cleared = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ['children_nav' => null, 'show_breadcrumbs' => true],
            $admin,
        );
        $clearedData = $this->assertEnvelopeSuccess($cleared);
        self::assertSame(LookupService::NAVIGATION_CHILDREN_NAV_SIDEBAR, $clearedData['children_nav']);
        self::assertTrue($clearedData['show_breadcrumbs']);
    }

    public function testParentItemOverridesMenuDefaultInPublicPayload(): void
    {
        $admin = $this->loginAsQaAdmin();

        $created = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'group',
                'label' => 'QA children-nav override group',
                'children_nav' => 'none',
            ],
            $admin,
        );
        $data = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        /** @var array<string, mixed> $item */
        $item = $data['item'];
        self::assertSame('none', $item['children_nav']);
        $itemId = $item['id'];
        self::assertIsInt($itemId);

        // Childless groups are pruned from the public tree; give it a child.
        $child = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER . '/items',
            [
                'item_type' => 'external_url',
                'external_url' => 'https://example.test/qa-children-nav-child',
                'label' => 'QA children-nav child',
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
        self::assertSame('none', $resolved['children_nav']);
    }

    public function testChildrenNavValidation(): void
    {
        $admin = $this->loginAsQaAdmin();

        // Mobile menus have native presentation: children_nav is rejected.
        $onDrawer = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER,
            ['children_nav' => 'sidebar'],
            $admin,
        );
        $this->assertEnvelope400($onDrawer);

        // Unknown mode is rejected by the JSON schema / lookup validation.
        $invalid = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/' . LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            ['children_nav' => 'accordion'],
            $admin,
        );
        $this->assertEnvelope400($invalid);
    }
}
