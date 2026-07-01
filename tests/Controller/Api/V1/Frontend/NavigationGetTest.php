<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public navigation payload (`GET /cms-api/v1/navigation`).
 */
#[Group('security')]
final class NavigationGetTest extends QaWebTestCase
{
    public function testGuestCanLoadNavigationPayload(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/navigation');

        $data = $this->assertEnvelopeSuccess($envelope, Response::HTTP_OK);
        self::assertIsArray($data['menus'] ?? null);
        self::assertIsArray($data['startup'] ?? null);
        self::assertIsArray($data['search'] ?? null);
        self::assertArrayHasKey('web_header', $data['menus']);
        self::assertArrayHasKey('web_footer', $data['menus']);
        self::assertArrayHasKey('mobile_drawer', $data['menus']);
        self::assertArrayHasKey('mobile_bottom_tabs', $data['menus']);

        $menus = $data['menus'];
        $tabs = $menus['mobile_bottom_tabs'] ?? null;
        self::assertIsArray($tabs);
        $items = $tabs['items'] ?? null;
        self::assertIsArray($items);
        if (isset($tabs['item_limit']) && is_int($tabs['item_limit']) && $tabs['item_limit'] > 0) {
            self::assertLessThanOrEqual($tabs['item_limit'], count($items));
        }
    }

    public function testAuthenticatedUserCanLoadNavigationPayload(): void
    {
        $user = $this->loginAsQaUser();
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/navigation', null, $user);

        $this->assertEnvelopeSuccess($envelope, Response::HTTP_OK);
    }
}
